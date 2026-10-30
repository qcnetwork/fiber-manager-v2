<?php

namespace App\Console\Commands;

use App\Enums\CableType;
use App\Enums\PortStatus;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Cable;
use App\Models\Customer;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\Olt;
use App\Services\GeoService;
use App\Services\Legacy\OltCardImporter;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Copies the network from the previous "fiber-manager" app into this one.
 *
 * Mapping: POP → area (the area is the POP), OLT → olt, PON card → OLT ports of one slot, ODC → odc (+ its PON port),
 * ODP → odp, ODP port → port (+ customer),
 * route coordinates of ODC / ODP / port → feeder / distribution / drop cable, users → users.
 * Everything runs in one transaction; a report and an id map (for migrating photos later) are written
 * to storage/app/migrasi-fiber-manager.
 */
class MigrateFiberManager extends Command
{
    protected $signature = 'migrasi:fiber-manager
        {--database=fiber_manager_import : Database MariaDB berisi dump aplikasi lama}
        {--connection= : Pakai koneksi database yang sudah dikonfigurasi (untuk pengujian)}
        {--force : Tetap jalan walaupun aplikasi ini sudah berisi data jaringan}';

    protected $description = 'Migrasi data ODC/ODP/pelanggan/kabel/pengguna dari aplikasi fiber-manager lama';

    /** An old route end this close to its ODC/ODP is moved onto it; farther ends get the asset appended. */
    private const SNAP_METERS = 30;

    /** A feeder route starting this close to its POP is attached to the POP. */
    private const POP_SNAP_METERS = 60;

    private const PLACEHOLDER_TARGETS = ['', 'x', '-', '0'];

    private Connection $old;

    private OltCardImporter $cards;

    /** @var list<string> */
    private array $notes = [];

    /** @var array<string, array<int, int>> old id → new id, per kind */
    private array $idMap = ['area' => [], 'olt' => [], 'odc' => [], 'odp' => [], 'port' => []];

    /** @var array<string, int> */
    private array $counts = [];

    public function handle(): int
    {
        $this->old = $this->connectToOldDatabase();

        if (! $this->option('force') && (Odc::withoutGlobalScopes()->exists() || Odp::withoutGlobalScopes()->exists())) {
            $this->error('Aplikasi ini sudah berisi ODC/ODP. Kosongkan dulu (php artisan migrate:fresh) atau pakai --force.');

            return self::FAILURE;
        }

        DB::transaction(function () {
            $this->migrateAreasAndOlts();
            $odcs = $this->migrateOdcs();
            $odps = $this->migrateOdps($odcs);
            $this->migratePorts($odps);
            $this->migrateCables($odcs, $odps);
            $this->migrateUsers();
        });

        $this->report();

        return self::SUCCESS;
    }

    private function connectToOldDatabase(): Connection
    {
        if ($name = $this->option('connection')) {
            return DB::connection($name);
        }

        config(['database.connections.fiber_manager' => [
            ...config('database.connections.'.config('database.default')),
            'database' => $this->option('database'),
        ]]);

        return DB::connection('fiber_manager');
    }

    // ------------------------------------------------------------------ POP → area, OLT

    private function migrateAreasAndOlts(): void
    {
        foreach ($this->old->table('pop')->orderBy('id')->get() as $pop) {
            $area = Area::create([
                'code' => mb_substr($pop->code ?: 'POP-'.$pop->id, 0, 20),
                'name' => $pop->name,
                'center_lat' => $pop->lat,
                'center_lng' => $pop->lng,
                'address' => $this->nullIfBlank($pop->address ?? null),
                'notes' => $this->nullIfBlank($pop->description ?? null),
            ]);
            $this->idMap['area'][$pop->id] = $area->id;
        }
        $this->counts['Area (dari POP)'] = count($this->idMap['area']);

        $pops = $this->old->table('pop')->get()->keyBy('id');
        foreach ($this->old->table('olt')->orderBy('id')->get() as $olt) {
            $pop = $pops[$olt->pop_id] ?? null;
            if (! $pop) {
                $this->notes[] = "OLT \"{$olt->name}\" dilewati: POP-nya tidak ada.";

                continue;
            }
            // An OLT sits at its POP; its PON ports come from the old cards below.
            $new = Olt::create([
                'area_id' => $this->idMap['area'][$pop->id],
                'code' => $olt->name,
                'name' => $olt->name,
                'brand' => $olt->model,
                'ip_address' => $this->nullIfBlank($olt->ip_address),
                'pon_ports' => max(1, (int) $olt->total_pon_ports),
                'notes' => $this->nullIfBlank($olt->description),
            ]);
            $this->idMap['olt'][$olt->id] = $new->id;
        }
        $this->counts['OLT'] = count($this->idMap['olt']);

        $this->cards = new OltCardImporter($this->old);
        $this->cards->importCards($this->idMap['olt']);
        $this->notes = [...$this->notes, ...$this->cards->notes];
        $this->counts['Kartu PON'] = $this->cards->cardCount;
    }

    // ------------------------------------------------------------------ ODC

    /**
     * @return Collection<int, Odc> new ODCs keyed by old id
     */
    private function migrateOdcs(): Collection
    {
        $olts = $this->old->table('olt')->get()->keyBy('id');
        $pons = $this->old->table('pon')->get()->keyBy('id');
        $odcs = collect();

        foreach ($this->old->table('odc')->orderBy('id')->get() as $old) {
            $oltId = $old->olt_id ?? $pons[$old->pon_id]->olt_id ?? null;
            $popId = $olts[$oltId]->pop_id ?? null;
            $areaId = $this->idMap['area'][$popId] ?? $this->nearestArea((float) $old->lat, (float) $old->lng);
            $pon = $pons[$old->pon_id] ?? null;

            $odc = new Odc([
                'area_id' => $areaId,
                'olt_port_id' => $this->cards->portId($old->pon_id, $old->pon_port_number),
                'code' => trim($old->name),
                'lat' => $old->lat,
                'lng' => $old->lng,
                'address' => $this->nullIfBlank($old->location),
                'capacity' => max(1, (int) $old->capacity),
                'status' => 'aktif',
                'notes' => $this->joinNotes([
                    $old->description,
                    $pon ? "PON: {$pon->name}".($old->pon_port_number ? ", port {$old->pon_port_number}" : '') : null,
                ]),
            ]);
            $this->keepTimestamps($odc, $old);
            $odc->save();

            $odcs[$old->id] = $odc;
            $this->idMap['odc'][$old->id] = $odc->id;
        }
        $this->counts['ODC'] = $odcs->count();

        return $odcs;
    }

    // ------------------------------------------------------------------ ODP (with duplicate handling)

    /**
     * @param  Collection<int, Odc>  $odcs
     * @return Collection<int, array{odp: Odp, old: list<object>}> keyed by the new ODP id
     */
    private function migrateOdps(Collection $odcs): Collection
    {
        $groups = $this->groupDuplicateOdps($this->old->table('odp')->orderBy('id')->get());
        $maxPort = $this->old->table('odp_ports')->select('odp_id', DB::raw('max(port_number) as max_port'))->groupBy('odp_id')->pluck('max_port', 'odp_id');

        $takenOdcPorts = [];
        $result = collect();

        foreach ($groups as $group) {
            $code = $group['code'];
            $rows = $group['rows'];
            // Prefer the row whose customers were recorded; the uplink may sit on another copy.
            $primary = collect($rows)->sortByDesc(fn ($r) => $r->used_count)->first();
            $linked = collect($rows)->first(fn ($r) => $r->source_type === 'odc' && isset($odcs[$r->source_id])) ?? $primary;
            $odc = $linked->source_type === 'odc' ? ($odcs[$linked->source_id] ?? null) : null;

            if ($linked->source_type === 'odc' && ! $odc) {
                $this->notes[] = "ODP {$code}: tercatat terhubung ke ODC, tetapi ODC-nya ".($linked->source_id ? "(id lama {$linked->source_id}) tidak ada" : 'tidak diisi').'; disimpan tanpa ODC.';
            }

            $port = $odc ? $linked->port_number_in_odc : null;
            if ($odc && $port !== null && isset($takenOdcPorts[$odc->id][$port])) {
                $this->notes[] = "ODP {$code}: port {$port} di ODC {$odc->code} sudah dipakai {$takenOdcPorts[$odc->id][$port]}; ODP tetap terhubung ke ODC itu tanpa nomor port.";
                $port = null;
            }
            if ($odc && $port !== null && $port > $odc->capacity) {
                $this->notes[] = "ODP {$code}: port {$port} melebihi kapasitas ODC {$odc->code}; nomor port dikosongkan.";
                $port = null;
            }
            if ($odc && $port !== null) {
                $takenOdcPorts[$odc->id][$port] = $code;
            }

            $capacity = max((int) $primary->total_ports, ...array_map(fn ($r) => (int) ($maxPort[$r->id] ?? 0), $rows));
            if ($capacity > 64) {
                $this->notes[] = "ODP {$code}: kapasitas {$capacity} dibatasi menjadi 64.";
            }

            $odp = new Odp([
                'area_id' => $odc?->area_id ?? $this->nearestOdcArea($odcs, (float) $primary->lat, (float) $primary->lng),
                'odc_id' => $odc?->id,
                'odc_port' => $port,
                'code' => $code,
                'lat' => $primary->lat,
                'lng' => $primary->lng,
                'address' => $this->nullIfBlank($primary->location),
                'capacity' => min(64, max(1, $capacity)),
                'status' => 'aktif',
                'notes' => $this->nullIfBlank($primary->description),
            ]);
            $this->keepTimestamps($odp, $primary);
            $odp->save(); // creates the port rows

            foreach ($rows as $row) {
                $this->idMap['odp'][$row->id] = $odp->id;
            }
            $result[$odp->id] = ['odp' => $odp, 'old' => $rows, 'linked' => $linked];
        }

        $this->counts['ODP'] = $result->count();

        return $result;
    }

    /**
     * Same name at the same spot = one ODP recorded several times (merged).
     * Same name elsewhere = different ODPs (renamed with a suffix).
     *
     * @return list<array{code: string, rows: list<object>}>
     */
    private function groupDuplicateOdps(Collection $rows): array
    {
        $used = $this->old->table('odp_ports')->where('status', 'used')->select('odp_id', DB::raw('count(*) as n'))->groupBy('odp_id')->pluck('n', 'odp_id');
        $rows->each(fn ($r) => $r->used_count = (int) ($used[$r->id] ?? 0));

        $groups = [];
        foreach ($rows->groupBy(fn ($r) => mb_strtoupper(trim($r->name))) as $sameName) {
            $bySpot = $sameName->groupBy(fn ($r) => round((float) $r->lat, 6).','.round((float) $r->lng, 6))->values();
            $baseName = trim($sameName->first()->name);

            foreach ($bySpot as $i => $spot) {
                $code = $i === 0 ? $baseName : "{$baseName} (".($i + 1).')';
                $groups[] = ['code' => $code, 'rows' => $spot->all()];

                if ($spot->count() > 1) {
                    $this->notes[] = "ODP {$code}: {$spot->count()} data dengan nama & lokasi sama digabung menjadi satu (id lama: ".$spot->pluck('id')->implode(', ').').';
                }
                if ($i > 0) {
                    $this->notes[] = "ODP \"{$baseName}\" ada di lokasi berbeda; yang ini diberi nama \"{$code}\".";
                }
            }
        }

        return $groups;
    }

    // ------------------------------------------------------------------ ports & customers

    /**
     * @param  Collection<int, array{odp: Odp, old: list<object>}>  $odps
     */
    private function migratePorts(Collection $odps): void
    {
        $oldPorts = $this->old->table('odp_ports')->orderBy('id')->get()->groupBy('odp_id');
        $customerCodes = [];
        $customers = 0;

        foreach ($odps as ['odp' => $odp, 'old' => $rows]) {
            $newPorts = $odp->ports()->get()->keyBy('number');

            // Merged duplicates may each have a row for a port number; a used one wins.
            $byNumber = collect($rows)->flatMap(fn ($r) => $oldPorts[$r->id] ?? [])
                ->sortBy(fn ($p) => $p->status === 'used' ? 0 : 1)
                ->unique('port_number');

            foreach ($byNumber as $old) {
                $port = $newPorts[$old->port_number] ?? null;
                if (! $port) {
                    continue; // port number 0 or negative in the old data
                }
                $this->idMap['port'][$old->id] = $port->id;

                $target = trim((string) $old->target);
                $hasCustomer = $old->status === 'used' && ! in_array(mb_strtolower($target), self::PLACEHOLDER_TARGETS, true);
                $customerId = null;

                if ($hasCustomer) {
                    // Targets look like PPPoE usernames ("name0101108@qc.net"): keep them as the customer ID.
                    $isLogin = str_contains($target, '@');
                    $code = $isLogin && ! isset($customerCodes[mb_strtolower($target)]) ? mb_substr($target, 0, 50) : null;
                    if ($code) {
                        $customerCodes[mb_strtolower($target)] = true;
                    }

                    $customer = new Customer([
                        'area_id' => $odp->area_id,
                        'customer_code' => $code,
                        'name' => mb_substr($isLogin ? strstr($target, '@', true) : $target, 0, 255),
                        'lat' => $this->validCoordinate($old->lat, $old->lng) ? $old->lat : null,
                        'lng' => $this->validCoordinate($old->lat, $old->lng) ? $old->lng : null,
                        'ont_sn' => $this->nullIfBlank($old->onu_number) ? mb_substr($old->onu_number, 0, 50) : null,
                        'status' => 'aktif',
                    ]);
                    $this->keepTimestamps($customer, $old);
                    $customer->save();
                    $customerId = $customer->id;
                    $customers++;
                }

                $port->forceFill([
                    'status' => match ($old->status) {
                        'used' => PortStatus::Terpakai,
                        'maintenance' => PortStatus::Rusak,
                        default => PortStatus::Kosong,
                    },
                    'customer_id' => $customerId,
                    'notes' => mb_substr((string) $this->joinNotes([
                        $this->nullIfBlank($old->modem_type) ? "Modem: {$old->modem_type}" : null,
                        ! $hasCustomer && ! in_array(mb_strtolower($target), self::PLACEHOLDER_TARGETS, true) ? "Target lama: {$target}" : null,
                        $old->description,
                    ], ' · '), 0, 255) ?: null,
                ])->save();
            }
        }

        $this->counts['Port ODP'] = count($this->idMap['port']);
        $this->counts['Pelanggan'] = $customers;
    }

    // ------------------------------------------------------------------ routes → cables

    /**
     * @param  Collection<int, Odc>  $odcs
     * @param  Collection<int, array{odp: Odp, old: list<object>}>  $odps
     */
    private function migrateCables(Collection $odcs, Collection $odps): void
    {
        $made = ['feeder' => 0, 'distribusi' => 0, 'drop' => 0];
        $pops = Area::all()->keyBy('id');

        // Feeder: POP → ODC
        foreach ($this->old->table('odc')->whereNotNull('path_coordinates')->get() as $old) {
            $odc = $odcs[$old->id] ?? null;
            $path = $this->path($old->path_coordinates);
            if (! $odc || ! $path) {
                continue;
            }
            $path = $this->orientTowards($path, $odc->lat, $odc->lng);
            $path = $this->snapEnd($path, $odc->lat, $odc->lng, last: true, what: "Kabel feeder ke ODC {$odc->code}");

            $pop = $pops[$odc->area_id] ?? null;
            $fromPop = $pop?->lat !== null && GeoService::distance($path[0][0], $path[0][1], $pop->lat, $pop->lng) <= self::POP_SNAP_METERS;
            if ($fromPop) {
                $path[0] = [$pop->lat, $pop->lng];
            }

            $this->makeCable($odc->area_id, CableType::Feeder, $path, $fromPop ? $pop : null, $odc);
            $made['feeder']++;
        }

        // Distribution: ODC → ODP (the old app stored these starting at the ODP)
        foreach ($odps as ['odp' => $odp, 'old' => $rows, 'linked' => $linked]) {
            $withPath = collect([$linked, ...$rows])->first(fn ($r) => $this->path($r->path_coordinates));
            if (! $withPath) {
                continue;
            }
            $path = $this->orientTowards($this->path($withPath->path_coordinates), $odp->lat, $odp->lng);
            $path = $this->snapEnd($path, $odp->lat, $odp->lng, last: true, what: "Kabel distribusi ke ODP {$odp->code}");

            $odc = $odp->odc_id ? $odcs->firstWhere('id', $odp->odc_id) : null;
            if ($odc) {
                $path = $this->snapEnd($path, $odc->lat, $odc->lng, last: false, what: "Kabel distribusi ODP {$odp->code} dari ODC {$odc->code}");
            }

            $this->makeCable($odp->area_id, CableType::Distribusi, $path, $odc, $odp);
            $made['distribusi']++;
        }

        // Drop: ODP → customer
        $oldPorts = $this->old->table('odp_ports')->whereNotNull('path_coordinates')->get();
        foreach ($oldPorts as $old) {
            $odpId = $this->idMap['odp'][$old->odp_id] ?? null;
            $path = $this->path($old->path_coordinates);
            if (! $odpId || ! $path || ! isset($this->idMap['port'][$old->id])) {
                continue;
            }
            $odp = $odps[$odpId]['odp'];
            // Orient towards the ODP, then flip so the drop starts there.
            $path = array_reverse($this->orientTowards($path, $odp->lat, $odp->lng));
            $path = $this->snapEnd($path, $odp->lat, $odp->lng, last: false, what: "Kabel drop dari ODP {$odp->code}");

            $this->makeCable($odp->area_id, CableType::Drop, $path, $odp, null);
            $made['drop']++;
        }

        foreach ($made as $type => $count) {
            $this->counts["Kabel {$type}"] = $count;
        }
    }

    private function makeCable(int $areaId, CableType $type, array $path, ?object $from, ?object $to): void
    {
        $cable = new Cable([
            'type' => $type,
            'cores' => ['feeder' => 48, 'distribusi' => 12, 'drop' => 1][$type->value],
            'status' => 'aktif',
            'from_type' => $from?->getMorphClass(),
            'from_id' => $from?->id,
            'to_type' => $to?->getMorphClass(),
            'to_id' => $to?->id,
            'path' => $path,
            'notes' => 'Dari aplikasi lama; jumlah core belum diverifikasi.',
        ]);
        $cable->area_id = $areaId;
        $cable->save();
    }

    /**
     * @return list<array{0: float, 1: float}>|null
     */
    private function path(?string $json): ?array
    {
        $points = json_decode((string) $json, true);
        if (! is_array($points)) {
            return null;
        }

        $path = [];
        foreach ($points as $p) {
            if (is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1]) && $this->validCoordinate($p[0], $p[1])) {
                $path[] = [(float) $p[0], (float) $p[1]];
            }
        }

        return count($path) >= 2 ? $path : null;
    }

    /**
     * Reverses the path when its first point is closer to the target than its last, so it ends at the target.
     */
    private function orientTowards(array $path, float $lat, float $lng): array
    {
        $first = GeoService::distance($path[0][0], $path[0][1], $lat, $lng);
        $last = GeoService::distance($path[count($path) - 1][0], $path[count($path) - 1][1], $lat, $lng);

        return $first < $last ? array_reverse($path) : $path;
    }

    /**
     * Puts the path's end exactly on the asset: moves it there when close, otherwise extends the line to it.
     */
    private function snapEnd(array $path, float $lat, float $lng, bool $last, string $what = ''): array
    {
        $index = $last ? count($path) - 1 : 0;
        $distance = GeoService::distance($path[$index][0], $path[$index][1], $lat, $lng);

        if ($distance <= self::SNAP_METERS) {
            $path[$index] = [$lat, $lng];

            return $path;
        }

        $this->notes[] = "{$what}: jalur lama berhenti ".round($distance).' m dari asetnya; disambung dengan garis lurus, mohon digambar ulang.';
        if ($last) {
            $path[] = [$lat, $lng];
        } else {
            array_unshift($path, [$lat, $lng]);
        }

        return $path;
    }

    // ------------------------------------------------------------------ users

    private function migrateUsers(): void
    {
        $created = 0;
        foreach ($this->old->table('users')->orderBy('id')->get() as $old) {
            if (! $old->is_active) {
                $this->notes[] = "Pengguna {$old->username} tidak aktif di aplikasi lama; tidak dimigrasi.";

                continue;
            }
            $email = $this->nullIfBlank($old->email);
            if (DB::table('users')->where('username', $old->username)->orWhere(fn ($q) => $email ? $q->where('email', $email) : $q->whereRaw('1 = 0'))->exists()) {
                $this->notes[] = "Pengguna {$old->username} sudah ada; dilewati.";

                continue;
            }

            // Inserted directly so the existing bcrypt hash is kept as-is: old passwords keep working.
            DB::table('users')->insert([
                'name' => $this->nullIfBlank($old->full_name) ?? $old->username,
                'username' => $old->username,
                'email' => $email,
                'email_verified_at' => now(),
                'password' => $old->password,
                'role' => match ($old->role) {
                    'admin' => Role::SuperAdmin->value,
                    'operator' => Role::Teknisi->value,
                    default => Role::Viewer->value,
                },
                'area_id' => null, // all areas, as in the old app
                'created_at' => $old->created_at ?? now(),
                'updated_at' => $old->updated_at ?? now(),
            ]);
            $created++;
        }
        $this->counts['Pengguna'] = $created;
    }

    // ------------------------------------------------------------------ helpers

    private function nearestArea(float $lat, float $lng): int
    {
        $pop = $this->old->table('pop')->get()
            ->sortBy(fn ($p) => GeoService::distance($lat, $lng, (float) $p->lat, (float) $p->lng))
            ->first();

        return $this->idMap['area'][$pop->id];
    }

    /**
     * Area for an ODP without an uplink: that of the closest ODC, else the closest POP.
     *
     * @param  Collection<int, Odc>  $odcs
     */
    private function nearestOdcArea(Collection $odcs, float $lat, float $lng): int
    {
        $odc = $odcs->sortBy(fn (Odc $o) => GeoService::distance($lat, $lng, $o->lat, $o->lng))->first();

        return $odc->area_id ?? $this->nearestArea($lat, $lng);
    }

    private function keepTimestamps(object $model, object $old): void
    {
        $model->created_at = $old->created_at ?? now();
        $model->updated_at = $old->updated_at ?? $old->created_at ?? now();
    }

    private function validCoordinate(mixed $lat, mixed $lng): bool
    {
        return is_numeric($lat) && is_numeric($lng) && (float) $lat !== 0.0 && abs((float) $lat) <= 90 && abs((float) $lng) <= 180;
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<?string>  $parts
     */
    private function joinNotes(array $parts, string $glue = "\n"): ?string
    {
        $parts = array_filter(array_map(fn ($p) => $this->nullIfBlank($p), $parts));

        return $parts ? implode($glue, $parts) : null;
    }

    private function report(): void
    {
        $this->info('Migrasi selesai.');
        $this->table(['Data', 'Jumlah'], collect($this->counts)->map(fn ($n, $k) => [$k, $n])->values()->all());

        $dir = 'migrasi-fiber-manager';
        $stamp = now()->format('Ymd-His');
        $lines = [
            'Migrasi fiber-manager — '.now()->format('Y-m-d H:i'),
            '',
            ...collect($this->counts)->map(fn ($n, $k) => str_pad($k, 18).": {$n}")->values(),
            '',
            'Catatan ('.count($this->notes).'):',
            ...array_map(fn ($n) => "- {$n}", $this->notes),
        ];
        Storage::disk('local')->put("{$dir}/laporan-{$stamp}.txt", implode(PHP_EOL, $lines));
        Storage::disk('local')->put("{$dir}/peta-id-{$stamp}.json", json_encode($this->idMap, JSON_PRETTY_PRINT));

        if ($this->notes) {
            $this->warn(count($this->notes).' catatan perlu dicek:');
            foreach ($this->notes as $note) {
                $this->line(" - {$note}");
            }
        }
        $this->line('Laporan: '.Storage::disk('local')->path("{$dir}/laporan-{$stamp}.txt"));
    }
}
