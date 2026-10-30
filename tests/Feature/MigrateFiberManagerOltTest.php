<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Odc;
use App\Models\Olt;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class MigrateFiberManagerOltTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private Olt $olt;

    private Odc $onCard2;

    private Odc $noCard;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // State after the schema migration: slot-1 ports only, ODCs linked by bare port number.
        $this->area = Area::factory()->create(['center_lat' => -7.0, 'center_lng' => 109.5]);
        $this->olt = Olt::create(['area_id' => $this->area->id, 'code' => 'ZTE KJN-01', 'name' => 'ZTE KJN-01']);
        $this->olt->syncPorts([1 => 10]);
        $this->onCard2 = Odc::factory()->for($this->area)->create(['olt_port_id' => $this->port(1, 4)]);
        $this->noCard = Odc::factory()->for($this->area)->create();

        Storage::disk('local')->put('migrasi-fiber-manager/peta-id-20260101-000000.json', json_encode([
            'area' => [1 => $this->area->id],
            'olt' => [5 => $this->olt->id],
            'odc' => [3 => $this->onCard2->id, 4 => $this->noCard->id],
        ]));

        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $schema = Schema::connection('legacy');
        $schema->create('pop', function (Blueprint $t) {
            $t->integer('id');
            $t->text('address')->nullable();
            $t->text('description')->nullable();
        });
        $schema->create('olt', function (Blueprint $t) {
            $t->integer('id');
            $t->string('ip_address')->nullable();
        });
        $schema->create('pon', function (Blueprint $t) {
            $t->integer('id');
            $t->integer('olt_id');
            $t->integer('card_number');
            $t->string('name');
            $t->integer('port_count');
        });
        $schema->create('pon_ports', function (Blueprint $t) {
            $t->integer('id');
            $t->integer('pon_id');
            $t->integer('port_number');
            $t->string('status');
            $t->text('description')->nullable();
        });
        $schema->create('odc', function (Blueprint $t) {
            $t->integer('id');
            $t->integer('pon_id')->nullable();
            $t->integer('pon_port_number')->nullable();
        });

        $db = DB::connection('legacy');
        $db->table('pop')->insert(['id' => 1, 'address' => 'Jl. Raya Kajen', 'description' => 'Ruko lantai 2']);
        $db->table('olt')->insert(['id' => 5, 'ip_address' => '10.0.0.2']);
        $db->table('pon')->insert([
            ['id' => 12, 'olt_id' => 5, 'card_number' => 1, 'name' => 'PON Card 1', 'port_count' => 8],
            ['id' => 14, 'olt_id' => 5, 'card_number' => 2, 'name' => 'PON Card 2', 'port_count' => 16],
        ]);
        $db->table('pon_ports')->insert(['id' => 1, 'pon_id' => 14, 'port_number' => 7, 'status' => 'maintenance', 'description' => null]);
        $db->table('odc')->insert([
            ['id' => 3, 'pon_id' => 14, 'pon_port_number' => 4],
            ['id' => 4, 'pon_id' => null, 'pon_port_number' => null],
        ]);
    }

    private function port(int $slot, int $port): ?int
    {
        return $this->olt->ports()->where(['slot' => $slot, 'port' => $port])->value('id');
    }

    private function recover(array $options = []): PendingCommand
    {
        return $this->artisan('migrasi:fiber-manager-olt', ['--connection' => 'legacy', ...$options]);
    }

    public function test_cards_are_restored_and_odcs_reconnected()
    {
        $this->recover()->assertSuccessful();

        $olt = $this->olt->fresh();
        // Slot 1 ports 9 and 10 were placeholders no card covers.
        $this->assertSame([1 => 8, 2 => 16], $olt->cards());
        $this->assertSame(24, $olt->pon_ports);
        $this->assertSame('rusak', $olt->ports->first(fn ($p) => $p->slot === 2 && $p->port === 7)->status);
        $this->assertSame('10.0.0.2', $olt->ip_address);

        $this->assertSame($this->port(2, 4), $this->onCard2->fresh()->olt_port_id);
        $this->assertNull($this->noCard->fresh()->olt_port_id);
        $this->assertSame(['Jl. Raya Kajen', 'Ruko lantai 2'], [$this->area->fresh()->address, $this->area->fresh()->notes]);

        $report = Storage::disk('local')->get(collect(Storage::disk('local')->files('migrasi-fiber-manager'))->first(fn ($f) => str_contains($f, 'laporan-olt')));
        $this->assertStringContainsString("ODC {$this->noCard->code}: di aplikasi lama tidak tercatat", $report);
    }

    public function test_dry_run_changes_nothing()
    {
        $this->recover(['--dry-run' => true])->assertSuccessful();

        $this->assertSame([1 => 10], $this->olt->fresh()->cards());
        $this->assertSame($this->port(1, 4), $this->onCard2->fresh()->olt_port_id);
        $this->assertNull($this->olt->fresh()->ip_address);
    }
}
