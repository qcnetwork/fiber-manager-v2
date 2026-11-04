<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupDatabaseTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/backup-'.uniqid());
        config(['backup.path' => $this->folder, 'backup.copy_to' => null]);
        File::ensureDirectoryExists($this->folder);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    private function fakeBackup(string $stamp): string
    {
        $path = $this->folder.DIRECTORY_SEPARATOR."backup-aplikasi_odp-{$stamp}.sql.gz";
        File::put($path, 'x');

        return $path;
    }

    public function test_scheduler_skips_when_a_recent_backup_exists()
    {
        $this->fakeBackup(now(config('backup.timezone'))->subHours(2)->format('Ymd-His'));

        // Tests run on SQLite, which mysqldump cannot back up: reaching the dump would fail.
        $this->artisan('backup:database', ['--if-stale' => true])->assertSuccessful();
    }

    public function test_it_refuses_a_database_mysqldump_cannot_dump()
    {
        $this->fakeBackup(now(config('backup.timezone'))->subDays(2)->format('Ymd-His'));

        $this->artisan('backup:database', ['--if-stale' => true])
            ->expectsOutput('Backup hanya untuk database MySQL/MariaDB.')
            ->assertFailed();
    }
}
