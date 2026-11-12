<?php

namespace Tests\Feature;

use App\Enums\PortStatus;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\Photo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class MigrateFiberManagerPhotosTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    private Odc $odc;

    private Odp $odp;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('photos');

        $area = Area::factory()->create();
        $this->odc = Odc::factory()->for($area)->create();
        $this->odp = Odp::factory()->for($area)->create();
        $port = $this->odp->ports()->where('number', 3)->first();
        $port->update(['status' => PortStatus::Terpakai, 'customer_id' => Customer::create(['area_id' => $area->id, 'name' => 'Budi'])->id]);

        // What `migrasi:fiber-manager` leaves behind: old id → new id.
        Storage::disk('local')->put('migrasi-fiber-manager/peta-id-20260101-000000.json', json_encode([
            'odc' => [7 => $this->odc->id],
            'odp' => [10 => $this->odp->id, 11 => $this->odp->id], // two old copies merged into one
            'port' => [90 => $port->id],
        ]));

        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        foreach (['odc_photos' => 'odc_id', 'odp_photos' => 'odp_id', 'port_photos' => 'port_id'] as $table => $fk) {
            Schema::connection('legacy')->create($table, function (Blueprint $t) use ($fk) {
                $t->integer('id');
                $t->integer($fk);
                $t->string('filename');
                $t->timestamp('created_at')->nullable();
            });
        }
        $db = DB::connection('legacy');
        $db->table('odc_photos')->insert(['id' => 1, 'odc_id' => 7, 'filename' => 'odc_7_a.jpg', 'created_at' => '2026-06-01 10:00:00']);
        $db->table('odp_photos')->insert([
            ['id' => 1, 'odp_id' => 11, 'filename' => 'odp_11_a.jpg', 'created_at' => null],
            ['id' => 2, 'odp_id' => 10, 'filename' => 'odp_10_hilang.jpg', 'created_at' => null],
            ['id' => 3, 'odp_id' => 999, 'filename' => 'odp_999.jpg', 'created_at' => null],
        ]);
        $db->table('port_photos')->insert(['id' => 1, 'port_id' => 90, 'filename' => 'port_90_a.png', 'created_at' => null]);

        $this->folder = sys_get_temp_dir().'/uploads-'.uniqid();
        foreach (['odc', 'odp', 'port'] as $dir) {
            File::ensureDirectoryExists("{$this->folder}/{$dir}");
        }
        foreach (['odc/odc_7_a.jpg', 'odp/odp_11_a.jpg', 'odp/odp_999.jpg', 'odp/sisa_lama.jpg'] as $name) {
            imagejpeg(imagecreatetruecolor(2400, 1800), "{$this->folder}/{$name}");
        }
        imagepng(imagecreatetruecolor(300, 200), "{$this->folder}/port/port_90_a.png");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    private function migratePhotos(array $options = []): PendingCommand
    {
        return $this->artisan('migrasi:fiber-manager-foto', ['folder' => $this->folder, '--connection' => 'legacy', ...$options]);
    }

    public function test_photos_are_attached_to_their_new_owners()
    {
        $this->migratePhotos()->assertSuccessful();

        $this->assertSame(1, $this->odc->photos()->count());
        $this->assertSame('2026-06-01 10:00:00', $this->odc->photos()->first()->created_at->format('Y-m-d H:i:s'));

        $captions = $this->odp->photos()->pluck('caption')->all();
        $this->assertEqualsCanonicalizing([null, 'Port 3 – Budi'], $captions);
        $this->assertSame(1600, $this->odp->photos()->whereNull('caption')->first()->width);

        $report = Storage::disk('local')->get(collect(Storage::disk('local')->files('migrasi-fiber-manager'))->first(fn ($f) => str_contains($f, 'laporan-foto')));
        $this->assertStringContainsString('odp_10_hilang.jpg tidak ada', $report);
        $this->assertStringContainsString('odp_photos:3: ODC/ODP/port tujuannya tidak ditemukan', $report);
        $this->assertStringContainsString('odp/sisa_lama.jpg tidak tercatat', $report);
        $this->assertFileExists("{$this->folder}/odp/odp_11_a.jpg"); // the old folder is left alone
    }

    public function test_running_again_does_not_duplicate_and_dry_run_saves_nothing()
    {
        $this->migratePhotos(['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Photo::count());

        $this->migratePhotos()->assertSuccessful();
        $this->migratePhotos()->assertSuccessful();
        $this->assertSame(3, Photo::count());
    }
}
