<?php

namespace App\Console\Commands;

use App\Models\Photo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Moves photos saved before they became private from the web-served public disk to the private photos disk.
 *
 * Safe to run again: files already moved are skipped, and a file is only removed from the public disk
 * once its private copy is in place.
 */
#[Signature('foto:pindah-privat {--dry-run : Hanya hitung, tanpa memindahkan}')]
#[Description('Pindahkan file foto dari storage publik ke storage privat (hanya bisa dibuka setelah login)')]
class MovePhotosPrivate extends Command
{
    protected $signature = 'foto:pindah-privat {--dry-run : Hanya hitung, tanpa memindahkan}';

    protected $description = 'Pindahkan file foto dari storage publik ke storage privat (hanya bisa dibuka setelah login)';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk(Photo::DISK);
        $moved = $missing = 0;

        Photo::query()->select(['id', 'path', 'thumb_path'])->chunkById(200, function ($photos) use ($public, $private, &$moved, &$missing) {
            foreach ($photos as $photo) {
                foreach ([$photo->path, $photo->thumb_path] as $path) {
                    if ($private->exists($path)) {
                        continue;
                    }
                    if (! $public->exists($path)) {
                        $missing++;

                        continue;
                    }
                    if (! $this->option('dry-run')) {
                        $private->put($path, $public->get($path));
                        if ($private->size($path) !== $public->size($path)) {
                            $this->error("Gagal menyalin {$path}");

                            return false;
                        }
                        $public->delete($path);
                    }
                    $moved++;
                }
            }
        });

        // Directories left empty by the move.
        if (! $this->option('dry-run')) {
            foreach (array_reverse($public->allDirectories('photos')) as $dir) {
                if (! $public->allFiles($dir)) {
                    $public->deleteDirectory($dir);
                }
            }
            if ($public->exists('photos') && ! $public->allFiles('photos')) {
                $public->deleteDirectory('photos');
            }
        }

        $this->info(($this->option('dry-run') ? 'Akan dipindahkan' : 'Dipindahkan').": {$moved} file. Tidak ditemukan: {$missing} file.");

        return self::SUCCESS;
    }
}
