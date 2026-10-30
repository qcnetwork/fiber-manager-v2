<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\Odc;
use App\Models\Olt;
use App\Models\OltPort;
use App\Services\Legacy\OltCardImporter;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * For data migrated before OLT ports existed: rebuilds the PON cards (slots) from the old "fiber-manager" app and
 * reconnects each ODC to its card and port, through the id map `migrasi:fiber-manager` wrote.
 *
 * Ports the schema migration made in slot 1 that no card covers are removed when no ODC uses them.
 * OLT IP addresses and POP addresses/descriptions are filled in where still empty.
 */
class MigrateFiberManagerOlt extends Command
{
    protected $signature = 'migrasi:fiber-manager-olt
        {--database=fiber_manager_import : Database MariaDB berisi dump aplikasi lama}
        {--connection= : Pakai koneksi database yang sudah dikonfigurasi (untuk pengujian)}
        {--dry-run : Hanya periksa, tanpa menyimpan perubahan}';

    protected $description = 'Pulihkan kartu PON (slot) OLT dan sambungan ODC → port PON dari aplikasi fiber-manager lama';

    private const DIR = 'migrasi-fiber-manager';

    public function handle(): int
    {
        $idMap = $this->latestIdMap();
        if (! $idMap) {
            $this->error('Peta ID migrasi tidak ditemukan. Jalankan dulu: php artisan migrasi:fiber-manager');

            return self::FAILURE;
        }

        $old = $this->connectToOldDatabase();
        $cards = new OltCardImporter($old);
        $counts = ['Kartu PON' => 0, 'ODC tersambung' => 0, 'ODC tanpa kartu' => 0, 'Port sisa dihapus' => 0];
        $notes = [];

        DB::beginTransaction();
        try {
            $cards->importCards($idMap['olt'] ?? []);
            $counts['Kartu PON'] = $cards->cardCount;
            $notes = $cards->notes;

            foreach ($old->table('odc')->orderBy('id')->get() as $oldOdc) {
                $odc = Odc::withoutGlobalScopes()->find($idMap['odc'][$oldOdc->id] ?? null);
                if (! $odc) {
                    continue;
                }
                $portId = $cards->portId($oldOdc->pon_id, $oldOdc->pon_port_number);
                if ($portId) {
                    $odc->update(['olt_port_id' => $portId]);
                    $counts['ODC tersambung']++;
                } else {
                    $counts['ODC tanpa kartu']++;
                    $notes[] = "ODC {$odc->code}: di aplikasi lama tidak tercatat kartu/port PON-nya; sambungan dibiarkan.";
                }
            }

            // Slot-1 placeholders from the schema migration that no card covers.
            $leftovers = OltPort::whereIn('olt_id', array_values($idMap['olt'] ?? []))->withCount('odcs')->get()
                ->reject(fn (OltPort $port) => $cards->isImported($port));
            foreach ($leftovers as $port) {
                if ($port->odcs_count > 0) {
                    $notes[] = "Port slot {$port->slot}/{$port->port} (OLT #{$port->olt_id}) tidak ada di kartu lama tapi masih dipakai ODC; dibiarkan.";
                } else {
                    $port->delete();
                    $counts['Port sisa dihapus']++;
                }
            }
            foreach (array_unique($idMap['olt'] ?? []) as $oltId) {
                Olt::withoutGlobalScopes()->whereKey($oltId)->update(['pon_ports' => OltPort::where('olt_id', $oltId)->count()]);
            }

            $this->fillDetails($old, $idMap);

            $this->option('dry-run') ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Gagal, tidak ada yang diubah: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->report($counts, $notes);

        return self::SUCCESS;
    }

    /**
     * OLT IPs and POP address/description from the old app, only where nothing is filled in yet.
     */
    private function fillDetails(Connection $old, array $idMap): void
    {
        foreach ($old->table('olt')->get() as $oldOlt) {
            $olt = Olt::withoutGlobalScopes()->find($idMap['olt'][$oldOlt->id] ?? null);
            if ($olt && ! $olt->ip_address && trim((string) $oldOlt->ip_address) !== '') {
                $olt->update(['ip_address' => trim($oldOlt->ip_address)]);
            }
        }

        foreach ($old->table('pop')->get() as $oldPop) {
            $area = Area::find($idMap['area'][$oldPop->id] ?? null);
            if (! $area) {
                continue;
            }
            $area->fill([
                'address' => $area->address ?: (trim((string) ($oldPop->address ?? '')) ?: null),
                'notes' => $area->notes ?: (trim((string) ($oldPop->description ?? '')) ?: null),
            ])->save();
        }
    }

    /**
     * @return array<string, array<int|string, int>>|null
     */
    private function latestIdMap(): ?array
    {
        $files = collect(Storage::disk('local')->files(self::DIR))->filter(fn ($f) => str_contains($f, 'peta-id-'))->sort();

        return $files->isEmpty() ? null : json_decode(Storage::disk('local')->get($files->last()), true);
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

    private function report(array $counts, array $notes): void
    {
        $this->info($this->option('dry-run') ? 'Pemeriksaan selesai (tidak ada yang disimpan).' : 'Kartu PON dan sambungan ODC dipulihkan.');
        $this->table(['', 'Jumlah'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());

        $file = self::DIR.'/laporan-olt-'.now()->format('Ymd-His').'.txt';
        Storage::disk('local')->put($file, implode(PHP_EOL, [
            'Pemulihan kartu PON fiber-manager — '.now()->format('Y-m-d H:i').($this->option('dry-run') ? ' (uji coba)' : ''),
            '',
            ...collect($counts)->map(fn ($n, $k) => str_pad($k, 20).": {$n}")->values(),
            '',
            'Catatan ('.count($notes).'):',
            ...array_map(fn ($n) => "- {$n}", $notes),
        ]));

        if ($notes) {
            $this->warn(count($notes).' catatan — lihat laporan.');
        }
        $this->line('Laporan: '.Storage::disk('local')->path($file));
    }
}
