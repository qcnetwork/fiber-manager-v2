<?php

namespace App\Services\Transfer;

use App\Enums\AssetStatus;
use App\Enums\CableType;
use App\Enums\PortStatus;
use App\Models\Cable;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\OdpPort;
use App\Services\GeoService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports ODC, ODP and cable rows into one area, all or nothing.
 *
 * Rows are matched on their code: an existing code is updated, a new one is created. On updates, empty
 * cells keep the stored value. Every row is checked before anything is written; if any row has an error,
 * nothing is saved and all errors are reported together.
 */
class NetworkImporter
{
    public const MAX_ROWS = 5000;

    /** Cable ends within this distance of an ODC/ODP get attached to it. */
    public const SNAP_METERS = 15;

    private const SPLITTERS = ['1:2', '1:4', '1:8', '1:16', '1:32'];

    private const CORES = [1, 2, 4, 6, 8, 12, 24, 48, 72, 96, 144, 288];

    /** @var list<array{where: string, message: string}> */
    private array $errors = [];

    public function __construct(private readonly int $areaId) {}

    /**
     * @param  list<array{label: string, data: array<string, mixed>}>  $odcRows
     * @param  list<array{label: string, data: array<string, mixed>}>  $odpRows
     * @param  list<array{label: string, data: array<string, mixed>}>  $cableRows  data: kode, jenis, core, status, path
     * @param  list<array{where: string, message: string}>  $skipped  rows the reader already set aside
     * @return array{ok: bool, created: array<string, int>, updated: array<string, int>, skipped: list<array{where: string, message: string}>, errors: list<array{where: string, message: string}>}
     */
    public function import(array $odcRows, array $odpRows, array $cableRows = [], array $skipped = []): array
    {
        $result = [
            'ok' => false,
            'created' => ['odc' => 0, 'odp' => 0, 'cable' => 0],
            'updated' => ['odc' => 0, 'odp' => 0, 'cable' => 0],
            'skipped' => $skipped,
            'errors' => [],
        ];

        $total = count($odcRows) + count($odpRows) + count($cableRows);
        if ($total === 0) {
            $result['errors'][] = ['where' => 'File', 'message' => 'Tidak ada baris data untuk diimpor.'];

            return $result;
        }
        if ($total > self::MAX_ROWS) {
            $result['errors'][] = ['where' => 'File', 'message' => 'Maksimal '.self::MAX_ROWS.' baris per impor; file ini berisi '.$total.'.'];

            return $result;
        }

        $odcs = $this->planOdcs($odcRows);
        $odps = $this->planOdps($odpRows, $odcs);
        $cables = $this->planCables($cableRows);

        if ($this->errors) {
            $result['errors'] = $this->errors;

            return $result;
        }

        try {
            DB::transaction(function () use ($odcs, $odps, $cables, &$result) {
                $saved = [];
                foreach ($odcs as $key => $plan) {
                    $saved[$key] = $this->save($plan['model'] ?? new Odc, $plan['attrs'], 'odc', $result);
                }

                foreach ($odps as $plan) {
                    $attrs = $plan['attrs'];
                    if ($plan['odcKey'] !== null) {
                        $attrs['odc_id'] = $saved[$plan['odcKey']]->id ?? $plan['odcId'];
                    }
                    $odp = $this->save($plan['model'] ?? new Odp, $attrs, 'odp', $result);
                    if ($plan['model']) {
                        $odp->syncPorts();
                    }
                }

                foreach ($cables as $plan) {
                    $this->save($plan['model'] ?? new Cable, $this->attachEnds($plan['attrs']), 'cable', $result);
                }
            });
        } catch (Throwable $e) {
            report($e);
            $result['errors'][] = ['where' => 'Database', 'message' => 'Impor dibatalkan karena kesalahan saat menyimpan. Tidak ada data yang berubah.'];

            return $result;
        }

        $result['ok'] = true;

        return $result;
    }

    // ---------------------------------------------------------------- planning (validation only)

    /**
     * @return array<string, array{model: ?Odc, attrs: array<string, mixed>, capacity: int}>
     */
    private function planOdcs(array $rows): array
    {
        $existing = $this->existing(Odc::class, $rows);
        $plans = [];

        foreach ($rows as $row) {
            [$label, $data] = [$row['label'], $row['data']];
            $code = $this->code($data, $label, $plans);
            if ($code === null) {
                continue;
            }

            $model = $existing[mb_strtoupper($code)] ?? null;
            if (! $this->inArea($model, $code, $label)) {
                continue;
            }

            $attrs = $this->assetAttrs($data, $label, $model === null);
            $capacity = $this->int($data['kapasitas'] ?? null, $label, 'kapasitas', 1, 1024);
            if ($capacity !== null) {
                $attrs['capacity'] = $capacity;
            }

            $plans[mb_strtoupper($code)] = [
                'model' => $model,
                'attrs' => ['code' => $code, ...$attrs],
                'capacity' => $capacity ?? $model?->capacity ?? 48,
            ];
        }

        return $plans;
    }

    /**
     * @param  array<string, array{model: ?Odc, attrs: array<string, mixed>, capacity: int}>  $odcPlans
     * @return list<array{model: ?Odp, attrs: array<string, mixed>, odcKey: ?string, odcId: ?int}>
     */
    private function planOdps(array $rows, array $odcPlans): array
    {
        $existing = $this->existing(Odp::class, $rows);

        // ODCs referenced by code that are not in the file must already exist in this area.
        $referenced = collect($rows)->pluck('data.kode_odc')->filter()->map(fn ($c) => mb_strtoupper(trim((string) $c)))->unique();
        $areaOdcs = Odc::withoutGlobalScope('area')
            ->where('area_id', $this->areaId)
            ->whereIn('code', $referenced->diff(array_keys($odcPlans))->values())
            ->get()
            ->keyBy(fn (Odc $odc) => mb_strtoupper($odc->code));

        $plans = [];
        $seen = [];
        $portOwners = []; // "odcKey#port" => ODP code, for ODPs in the file
        // ODPs in the file are judged by their new values, so only other ODPs can block a port in the database.
        $fileCodes = collect($rows)->pluck('data.kode')->filter()->map(fn ($c) => trim((string) $c))->values()->all();

        foreach ($rows as $row) {
            [$label, $data] = [$row['label'], $row['data']];
            $code = $this->code($data, $label, $seen);
            if ($code === null) {
                continue;
            }
            $seen[mb_strtoupper($code)] = true;

            $model = $existing[mb_strtoupper($code)] ?? null;
            if (! $this->inArea($model, $code, $label)) {
                continue;
            }

            $attrs = $this->assetAttrs($data, $label, $model === null);
            $errorsBefore = count($this->errors);

            $capacity = $this->int($data['kapasitas'] ?? null, $label, 'kapasitas', 1, 64);
            if ($capacity !== null) {
                $attrs['capacity'] = $capacity;
                if ($model && $capacity < $model->capacity && $this->hasUsedPortsAbove($model, $capacity)) {
                    $this->error($label, "Kapasitas tidak bisa dikurangi ke {$capacity}: ada port bernomor lebih besar yang masih terpakai.");
                }
            }

            if (($pole = $this->str($data['no_tiang'] ?? null, 50, $label, 'no_tiang')) !== null) {
                $attrs['pole_number'] = $pole;
            }

            // Uplink: ODC code (from the file or this area) plus a port on it.
            $odcKey = null;
            $odcId = null;
            $odcCode = $this->str($data['kode_odc'] ?? null, 100, $label, 'kode_odc');
            $port = $this->int($data['port_odc'] ?? null, $label, 'port_odc', 1, 1024);

            if ($odcCode !== null) {
                $upper = mb_strtoupper($odcCode);
                $odcCapacity = $odcPlans[$upper]['capacity'] ?? $areaOdcs[$upper]->capacity ?? null;

                if ($odcCapacity === null) {
                    $this->error($label, "ODC \"{$odcCode}\" tidak ditemukan di file maupun di area ini.");
                } else {
                    $odcKey = isset($odcPlans[$upper]) ? $upper : null;
                    $odcId = $areaOdcs[$upper]->id ?? $odcPlans[$upper]['model']?->id ?? null;
                    $attrs['odc_id'] = $odcId; // replaced with the saved ODC's id when it is new
                    $attrs['odc_port'] = $port;

                    if ($port !== null && $port > $odcCapacity) {
                        $this->error($label, "Port ODC {$port} melebihi kapasitas ODC {$odcCode} ({$odcCapacity}).");
                    } elseif ($port !== null) {
                        $slot = $upper.'#'.$port;
                        if (isset($portOwners[$slot])) {
                            $this->error($label, "Port {$port} di ODC {$odcCode} juga dipakai {$portOwners[$slot]} di file ini.");
                        } elseif ($odcId && $this->portTakenInDatabase($odcId, $port, $fileCodes)) {
                            $this->error($label, "Port {$port} di ODC {$odcCode} sudah dipakai ODP lain.");
                        }
                        $portOwners[$slot] = $code;
                    }
                }
            } elseif ($port !== null) {
                $this->error($label, 'Kolom port_odc diisi tetapi kode_odc kosong.');
            }

            if (count($this->errors) === $errorsBefore) {
                $plans[] = ['model' => $model, 'attrs' => ['code' => $code, ...$attrs], 'odcKey' => $odcKey, 'odcId' => $odcId];
            }
        }

        return $plans;
    }

    /**
     * @return list<array{model: ?Cable, attrs: array<string, mixed>}>
     */
    private function planCables(array $rows): array
    {
        $existing = $this->existing(Cable::class, array_filter($rows, fn ($r) => filled($r['data']['kode'] ?? null)));
        $plans = [];
        $seen = [];

        foreach ($rows as $row) {
            [$label, $data] = [$row['label'], $row['data']];

            $code = $this->str($data['kode'] ?? null, 100, $label, 'kode');
            // Cable codes are optional, and KML from elsewhere often repeats line names ("Path", "Garis
            // tanpa judul"); a repeated name is imported as a cable without a code rather than rejected.
            if ($code !== null && isset($seen[mb_strtoupper($code)])) {
                $code = null;
            }
            $model = null;
            if ($code !== null) {
                $seen[mb_strtoupper($code)] = true;
                $model = $existing[mb_strtoupper($code)] ?? null;
                if (! $this->inArea($model, $code, $label)) {
                    continue;
                }
            }

            $path = array_values(array_filter(
                $data['path'] ?? [],
                fn ($p) => abs($p[0]) <= 90 && abs($p[1]) <= 180,
            ));
            if (count($path) < 2) {
                $this->error($label, 'Garis kabel minimal punya 2 titik koordinat yang valid.');

                continue;
            }

            $type = CableType::tryFrom((string) ($data['jenis'] ?? ''));
            $cores = $this->int($data['core'] ?? null, $label, 'core');
            if ($cores !== null && ! in_array($cores, self::CORES, true)) {
                $this->error($label, 'Jumlah core tidak dikenal: '.$cores.'.');

                continue;
            }

            $status = $this->status($data['status'] ?? null, $label);
            $attrs = array_filter([
                'code' => $code,
                'type' => $type ?? $model?->type ?? CableType::Distribusi,
                'cores' => $cores ?? $model?->cores ?? ['feeder' => 48, 'distribusi' => 12, 'drop' => 1][($type ?? CableType::Distribusi)->value],
                'status' => $status,
                'path' => $path,
            ], fn ($v) => $v !== null);

            $plans[] = ['model' => $model, 'attrs' => $attrs];
        }

        return $plans;
    }

    // ---------------------------------------------------------------- writing

    private function save(Odc|Odp|Cable $model, array $attrs, string $kind, array &$result): Odc|Odp|Cable
    {
        $isNew = ! $model->exists;
        $model->fill($attrs);
        $model->area_id = $this->areaId;
        $model->save();

        $result[$isNew ? 'created' : 'updated'][$kind]++;

        return $model;
    }

    /**
     * Attaches cable ends that lie on (or within a few meters of) an ODC/ODP to it, snapping the vertex.
     */
    private function attachEnds(array $attrs): array
    {
        $path = $attrs['path'];

        foreach (['from' => 0, 'to' => count($path) - 1] as $end => $index) {
            [$lat, $lng] = $path[$index];
            $box = GeoService::boundingBox($lat, $lng, self::SNAP_METERS);
            $best = null;

            foreach ([Odc::class, Odp::class] as $class) {
                $candidates = $class::withoutGlobalScope('area')
                    ->where('area_id', $this->areaId)
                    ->whereBetween('lat', [$box['minLat'], $box['maxLat']])
                    ->whereBetween('lng', [$box['minLng'], $box['maxLng']])
                    ->get(['id', 'lat', 'lng']);

                foreach ($candidates as $asset) {
                    $distance = GeoService::distance($lat, $lng, $asset->lat, $asset->lng);
                    if ($distance <= self::SNAP_METERS && (! $best || $distance < $best[1])) {
                        $best = [$asset, $distance];
                    }
                }
            }

            $attrs["{$end}_type"] = $best ? $best[0]->getMorphClass() : null;
            $attrs["{$end}_id"] = $best ? $best[0]->id : null;
            if ($best) {
                $path[$index] = [$best[0]->lat, $best[0]->lng];
            }
        }

        $attrs['path'] = $path;

        return $attrs;
    }

    // ---------------------------------------------------------------- field parsing

    /**
     * Fields shared by ODC and ODP. Only cells that hold a value are returned, so updates keep the rest.
     *
     * @return array<string, mixed>
     */
    private function assetAttrs(array $data, string $label, bool $isNew): array
    {
        $attrs = [];

        $errorsBefore = count($this->errors);
        $lat = $this->number($data['latitude'] ?? null, $label, 'latitude');
        $lng = $this->number($data['longitude'] ?? null, $label, 'longitude');
        if (count($this->errors) > $errorsBefore) {
            // already reported as not a number
        } elseif (($lat === null) !== ($lng === null)) {
            $this->error($label, 'Latitude dan longitude harus diisi berdua.');
        } elseif ($lat === null && $isNew) {
            $this->error($label, 'Latitude dan longitude wajib diisi untuk data baru.');
        } elseif ($lat !== null) {
            if (abs($lat) > 90 || abs($lng) > 180) {
                $this->error($label, 'Koordinat di luar jangkauan. Pastikan latitude dan longitude tidak tertukar.');
            }
            $attrs['lat'] = $lat;
            $attrs['lng'] = $lng;
        }

        foreach (['nama' => ['name', 255], 'alamat' => ['address', 255], 'catatan' => ['notes', 2000]] as $column => [$field, $max]) {
            if (($value = $this->str($data[$column] ?? null, $max, $label, $column)) !== null) {
                $attrs[$field] = $value;
            }
        }

        if (($splitter = $data['splitter'] ?? null) !== null) {
            $normalized = preg_replace('/\s*[:\/]\s*/', ':', trim((string) $splitter));
            if (in_array($normalized, self::SPLITTERS, true)) {
                $attrs['splitter_ratio'] = $normalized;
            } else {
                $this->error($label, 'Splitter harus salah satu dari: '.implode(', ', self::SPLITTERS).'.');
            }
        }

        if (($status = $this->status($data['status'] ?? null, $label)) !== null) {
            $attrs['status'] = $status;
        }

        if (($date = $data['tanggal_pasang'] ?? null) !== null) {
            $parsed = $this->date($date);
            $parsed === null
                ? $this->error($label, 'Format tanggal_pasang tidak dikenali (pakai 2025-01-31 atau 31/01/2025).')
                : $attrs['installed_at'] = $parsed;
        }

        return $attrs;
    }

    private function code(array $data, string $label, array $seen): ?string
    {
        $code = $this->str($data['kode'] ?? null, 100, $label, 'kode');
        if ($code === null) {
            $this->error($label, 'Kolom kode wajib diisi.');

            return null;
        }
        if (isset($seen[mb_strtoupper($code)])) {
            $this->error($label, "Kode \"{$code}\" muncul lebih dari sekali di file.");

            return null;
        }

        return $code;
    }

    private function inArea(?object $model, string $code, string $label): bool
    {
        if ($model && (int) $model->area_id !== $this->areaId) {
            $this->error($label, "Kode \"{$code}\" sudah dipakai di area lain.");

            return false;
        }

        return true;
    }

    /**
     * Stored rows matching the codes in the file, from every area (codes are unique across areas), keyed by upper-cased code.
     *
     * @param  class-string<Odc|Odp|Cable>  $class
     */
    private function existing(string $class, array $rows): Collection
    {
        $codes = collect($rows)->pluck('data.kode')->filter()->map(fn ($c) => trim((string) $c))->unique()->values();

        return $codes->chunk(1000)
            ->flatMap(fn ($chunk) => $class::withoutGlobalScope('area')->whereIn('code', $chunk->all())->get())
            ->keyBy(fn ($model) => mb_strtoupper($model->code));
    }

    private function hasUsedPortsAbove(Odp $odp, int $capacity): bool
    {
        return OdpPort::where('odp_id', $odp->id)
            ->where('number', '>', $capacity)
            ->where('status', '!=', PortStatus::Kosong)
            ->exists();
    }

    /**
     * @param  list<string>  $exceptCodes
     */
    private function portTakenInDatabase(int $odcId, int $port, array $exceptCodes): bool
    {
        return Odp::withoutGlobalScope('area')
            ->where('odc_id', $odcId)
            ->where('odc_port', $port)
            ->whereNotIn('code', $exceptCodes)
            ->exists();
    }

    private function str(mixed $value, int $max, string $label, string $column): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(is_float($value) && floor($value) === $value ? (string) (int) $value : (string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            $this->error($label, "Isi kolom {$column} terlalu panjang (maks. {$max} karakter).");

            return null;
        }

        return $value;
    }

    /**
     * Accepts Excel numbers as well as text such as "-6,9175" (Indonesian decimal comma).
     */
    private function number(mixed $value, string $label, string $column): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace(' ', '', $value);
            if (str_contains($value, ',') && ! str_contains($value, '.')) {
                $value = str_replace(',', '.', $value);
            }
        }
        if (! is_numeric($value)) {
            $this->error($label, "Kolom {$column} harus berupa angka.");

            return null;
        }

        return (float) $value;
    }

    private function int(mixed $value, string $label, string $column, ?int $min = null, ?int $max = null): ?int
    {
        $number = $this->number($value, $label, $column);
        if ($number === null) {
            return null;
        }
        if (floor($number) !== $number || ($min !== null && $number < $min) || ($max !== null && $number > $max)) {
            $range = $min !== null ? " antara {$min}–{$max}" : '';
            $this->error($label, "Kolom {$column} harus bilangan bulat{$range}.");

            return null;
        }

        return (int) $number;
    }

    private function status(mixed $value, string $label): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $status = AssetStatus::tryFrom(mb_strtolower(trim((string) $value)));
        if (! $status) {
            $this->error($label, 'Status harus aktif, rencana, atau rusak.');

            return null;
        }

        return $status->value;
    }

    private function date(mixed $value): ?string
    {
        // Excel stores dates as days since 1899-12-30 when the cell isn't formatted as a date.
        if (is_numeric($value) && $value > 20000 && $value < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->format('Y-m-d');
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, trim((string) $value));
                if ($date && $date->format($format) === trim((string) $value)) {
                    return $date->format('Y-m-d');
                }
            } catch (Throwable) {
                // try the next format
            }
        }

        return null;
    }

    private function error(string $where, string $message): void
    {
        $this->errors[] = ['where' => $where, 'message' => $message];
    }
}
