<?php

namespace App\Console\Commands;

use App\Models\Odc;
use App\Models\Odp;
use App\Models\OdpPort;
use App\Services\PhotoService;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copies photos from the previous "fiber-manager" app, after `migrasi:fiber-manager` has run.
 *
 * Owners are found through the id map that command wrote. Port photos go to the port's ODP with a
 * "Port N – customer" caption, since photos here belong to ODCs and ODPs. The old folder is only read.
 * Finished photos are recorded, so the command can be run again without creating duplicates.
 */
class MigrateFiberManagerPhotos extends Command
{
    protected $signature = 'migrasi:fiber-manager-foto
        {folder : Folder "uploads" aplikasi lama (berisi subfolder odc, odp, port)}
        {--database=fiber_manager_import : Database MariaDB berisi dump aplikasi lama}
        {--connection= : Pakai koneksi database yang sudah dikonfigurasi (untuk pengujian)}
        {--dry-run : Hanya periksa, tanpa menyimpan foto}';

    protected $description = 'Migrasi foto ODC/ODP/port dari aplikasi fiber-manager lama';

    private const DIR = 'migrasi-fiber-manager';

    private const DONE_FILE = self::DIR.'/foto-selesai.json';

    /** @var list<string> */
    private array $notes = [];

    public function handle(PhotoService $photos): int
    {
        $folder = rtrim(str_replace('\\', '/', $this->argument('folder')), '/');
        if (! is_dir($folder)) {
            $this->error("Folder tidak ditemukan: {$folder}");

            return self::FAILURE;
        }

        $idMap = $this->latestIdMap();
        if (! $idMap) {
            $this->error('Peta ID migrasi tidak ditemukan. Jalankan dulu: php artisan migrasi:fiber-manager');

            return self::FAILURE;
        }

        $old = $this->connectToOldDatabase();
        $done = $this->option('dry-run') ? [] : array_flip(json_decode(Storage::disk('local')->get(self::DONE_FILE) ?? '[]', true));
        $counts = ['dipindah' => 0, 'sudah ada' => 0, 'file hilang' => 0, 'pemilik tidak ada' => 0, 'gagal' => 0];

        $jobs = $this->collectJobs($old, $idMap, $folder);
        $bar = $this->output->createProgressBar(count($jobs));

        foreach ($jobs as $job) {
            $bar->advance();

            if (isset($done[$job['key']])) {
                $counts['sudah ada']++;

                continue;
            }
            if (! $job['owner']) {
                $counts['pemilik tidak ada']++;
                $this->notes[] = "{$job['key']}: ODC/ODP/port tujuannya tidak ditemukan di aplikasi ini.";

                continue;
            }
            if (! is_file($job['path'])) {
                $counts['file hilang']++;
                $this->notes[] = "{$job['key']}: file {$job['path']} tidak ada.";

                continue;
            }
            if ($this->option('dry-run')) {
                $counts['dipindah']++;

                continue;
            }

            try {
                $file = new UploadedFile($job['path'], basename($job['path']), null, null, true);
                $photo = $photos->store($job['owner'], $file, $job['caption'], null);
                $photo->forceFill(['created_at' => $job['created_at'] ?? now(), 'updated_at' => $job['created_at'] ?? now()])->save();

                $done[$job['key']] = true;
                $counts['dipindah']++;
            } catch (Throwable $e) {
                $counts['gagal']++;
                $this->notes[] = "{$job['key']}: gagal diproses ({$e->getMessage()}).";
            }

            // Record progress as we go so an interrupted run resumes where it stopped.
            if ($counts['dipindah'] % 25 === 0) {
                $this->saveDone($done);
            }
        }

        $bar->finish();
        $this->newLine(2);
        if (! $this->option('dry-run')) {
            $this->saveDone($done);
        }

        $this->notes = [...$this->notes, ...$this->unreferencedFiles($old, $folder)];
        $this->report($counts);

        return $counts['gagal'] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, array<int|string, int>>  $idMap
     * @return list<array{key: string, path: string, owner: Odc|Odp|null, caption: ?string, created_at: ?string}>
     */
    private function collectJobs(Connection $old, array $idMap, string $folder): array
    {
        $jobs = [];

        foreach ($old->table('odc_photos')->orderBy('id')->get() as $row) {
            $id = $idMap['odc'][$row->odc_id] ?? null;
            $jobs[] = $this->job("odc_photos:{$row->id}", "{$folder}/odc/{$row->filename}", $id ? Odc::withoutGlobalScopes()->find($id) : null, null, $row->created_at);
        }

        foreach ($old->table('odp_photos')->orderBy('id')->get() as $row) {
            $id = $idMap['odp'][$row->odp_id] ?? null;
            $jobs[] = $this->job("odp_photos:{$row->id}", "{$folder}/odp/{$row->filename}", $id ? Odp::withoutGlobalScopes()->find($id) : null, null, $row->created_at);
        }

        foreach ($old->table('port_photos')->orderBy('id')->get() as $row) {
            $portId = $idMap['port'][$row->port_id] ?? null;
            $port = $portId ? OdpPort::with('customer', 'odp')->find($portId) : null;
            // Photos here belong to ODCs/ODPs, so a port photo goes to its ODP, labelled with the port.
            $caption = $port ? trim("Port {$port->number}".($port->customer ? " – {$port->customer->name}" : '')) : null;
            $jobs[] = $this->job("port_photos:{$row->id}", "{$folder}/port/{$row->filename}", $port?->odp, $caption, $row->created_at);
        }

        return $jobs;
    }

    private function job(string $key, string $path, Odc|Odp|null $owner, ?string $caption, ?string $createdAt): array
    {
        return ['key' => $key, 'path' => $path, 'owner' => $owner, 'caption' => $caption, 'created_at' => $createdAt];
    }

    /**
     * Files in the folder that no photo record points to (left over from photos deleted in the old app).
     *
     * @return list<string>
     */
    private function unreferencedFiles(Connection $old, string $folder): array
    {
        $known = [];
        foreach (['odc_photos' => 'odc', 'odp_photos' => 'odp', 'port_photos' => 'port'] as $table => $dir) {
            foreach ($old->table($table)->pluck('filename') as $name) {
                $known["{$dir}/{$name}"] = true;
            }
        }

        $notes = [];
        foreach (['odc', 'odp', 'port'] as $dir) {
            foreach (glob("{$folder}/{$dir}/*") ?: [] as $path) {
                if (! isset($known["{$dir}/".basename($path)])) {
                    $notes[] = "File {$dir}/".basename($path).' tidak tercatat di database lama; dilewati.';
                }
            }
        }

        return $notes;
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

    private function saveDone(array $done): void
    {
        Storage::disk('local')->put(self::DONE_FILE, json_encode(array_keys($done)));
    }

    private function report(array $counts): void
    {
        $this->info($this->option('dry-run') ? 'Pemeriksaan selesai (tidak ada yang disimpan).' : 'Migrasi foto selesai.');
        $this->table(['Foto', 'Jumlah'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());

        $file = self::DIR.'/laporan-foto-'.now()->format('Ymd-His').'.txt';
        Storage::disk('local')->put($file, implode(PHP_EOL, [
            'Migrasi foto fiber-manager — '.now()->format('Y-m-d H:i').($this->option('dry-run') ? ' (uji coba)' : ''),
            '',
            ...collect($counts)->map(fn ($n, $k) => str_pad($k, 18).": {$n}")->values(),
            '',
            'Catatan ('.count($this->notes).'):',
            ...array_map(fn ($n) => "- {$n}", $this->notes),
        ]));

        if ($this->notes) {
            $this->warn(count($this->notes).' catatan — lihat laporan.');
        }
        $this->line('Laporan: '.Storage::disk('local')->path($file));
    }
}
