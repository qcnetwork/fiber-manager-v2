<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Dumps the MariaDB/MySQL database with mysqldump into a gzip file, then removes backups older than the
 * retention period. Optionally copies each backup to a second folder (e.g. a synced Google Drive folder),
 * so a dead disk does not take the backups with it.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database
        {--if-stale : Lewati kalau sudah ada backup yang lebih baru dari interval (dipakai penjadwal)}';

    protected $description = 'Backup database ke file .sql.gz dan hapus backup lama';

    private const PREFIX = 'backup-';

    public function handle(): int
    {
        $folder = config('backup.path');
        File::ensureDirectoryExists($folder);

        $latest = $this->backups($folder)->first();
        if ($this->option('if-stale') && $latest && $latest['at']->gt(now()->subHours(config('backup.interval_hours')))) {
            return self::SUCCESS;
        }

        $connection = config('database.connections.'.config('database.default'));
        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error('Backup hanya untuk database MySQL/MariaDB.');

            return self::FAILURE;
        }

        $file = $folder.DIRECTORY_SEPARATOR.self::PREFIX.$connection['database'].'-'.now(config('backup.timezone'))->format('Ymd-His').'.sql.gz';

        try {
            $size = $this->dump($connection, $file);
        } catch (RuntimeException $e) {
            File::delete($file);
            $this->error('Backup gagal: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $this->info('Backup dibuat: '.$file.' ('.number_format($size / 1024, 0, ',', '.').' KB)');

        if ($copyTo = config('backup.copy_to')) {
            try {
                File::ensureDirectoryExists($copyTo);
                File::copy($file, $copyTo.DIRECTORY_SEPARATOR.basename($file));
                $this->info('Disalin ke: '.$copyTo);
                $this->prune($copyTo);
            } catch (\Throwable $e) {
                // The local backup is still fine; the copy is retried with the next backup.
                $this->warn('Gagal menyalin ke '.$copyTo.': '.$e->getMessage());
                report($e);
            }
        }

        $this->prune($folder);

        return self::SUCCESS;
    }

    /**
     * Streams mysqldump output through gzip into $file. Returns the compressed size.
     *
     * @param  array<string, mixed>  $connection
     */
    private function dump(array $connection, string $file): int
    {
        $process = new Process(
            [
                config('backup.mysqldump'),
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--user='.$connection['username'],
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--default-character-set=utf8mb4',
                $connection['database'],
            ],
            // Password through the environment, so it never shows in the process list.
            env: ['MYSQL_PWD' => (string) $connection['password']],
            timeout: 1800,
        );

        $gz = gzopen($file, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("Tidak bisa menulis {$file}");
        }

        $tail = '';
        $errors = '';
        try {
            $process->run(function (string $type, string $buffer) use ($gz, &$tail, &$errors) {
                if ($type === Process::ERR) {
                    $errors .= $buffer;

                    return;
                }
                gzwrite($gz, $buffer);
                $tail = substr($tail.$buffer, -200);
            });
        } finally {
            gzclose($gz);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($errors) ?: 'mysqldump keluar dengan kode '.$process->getExitCode());
        }
        // mysqldump ends a complete dump with this line; anything else is a cut-off file.
        if (! str_contains($tail, 'Dump completed')) {
            throw new RuntimeException('Hasil dump tidak lengkap.');
        }

        return filesize($file);
    }

    /**
     * Removes backups older than the retention period, always keeping the newest few.
     */
    private function prune(string $folder): void
    {
        $cutoff = now()->subDays(config('backup.keep_days'));

        $this->backups($folder)
            ->slice(config('backup.keep_min'))
            ->filter(fn (array $backup) => $backup['at']->lt($cutoff))
            ->each(function (array $backup) {
                File::delete($backup['path']);
                $this->line('Dihapus (lama): '.basename($backup['path']));
            });
    }

    /**
     * Backups this command made in $folder, newest first.
     *
     * @return Collection<int, array{path: string, at: Carbon}>
     */
    private function backups(string $folder)
    {
        return collect(File::glob($folder.DIRECTORY_SEPARATOR.self::PREFIX.'*.sql.gz'))
            ->map(function (string $path) {
                if (! preg_match('/-(\d{8}-\d{6})\.sql\.gz$/', $path, $m)) {
                    return null;
                }

                return ['path' => $path, 'at' => Carbon::createFromFormat('Ymd-His', $m[1], config('backup.timezone'))];
            })
            ->filter()
            ->sortByDesc(fn (array $backup) => $backup['at']->getTimestamp())
            ->values();
    }
}
