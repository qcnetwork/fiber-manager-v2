<?php

namespace Tests\Feature;

use App\Enums\PortStatus;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Cable;
use App\Models\Customer;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\Olt;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MigrateFiberManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $this->createLegacySchema();
        $this->seedLegacyData();
    }

    private function createLegacySchema(): void
    {
        $schema = Schema::connection('legacy');
        $schema->create('pop', function (Blueprint $t) {
            $t->integer('id');
            $t->string('name');
            $t->string('code')->nullable();
            $t->decimal('lat', 10, 8)->nullable();
            $t->decimal('lng', 11, 8)->nullable();
        });
        $schema->create('olt', function (Blueprint $t) {
            $t->integer('id');
            $t->integer('pop_id')->nullable();
            $t->string('name');
            $t->string('model')->nullable();
            $t->string('ip_address')->nullable();
            $t->integer('total_pon_ports')->nullable();
            $t->decimal('lat', 10, 8)->nullable();
            $t->decimal('lng', 11, 8)->nullable();
            $t->text('description')->nullable();
        });
        $schema->create('pon', function (Blueprint $t) {
            $t->integer('id');
            $t->integer('olt_id');
            $t->string('name');
        });
        $schema->create('odc', function (Blueprint $t) {
            $t->integer('id');
            $t->string('name');
            $t->decimal('lat', 10, 8);
            $t->decimal('lng', 11, 8);
            $t->string('location')->default('');
            $t->integer('capacity')->default(24);
            $t->text('description')->nullable();
            $t->integer('pon_id')->nullable();
            $t->integer('pon_port_number')->nullable();
            $t->integer('olt_id')->nullable();
            $t->text('path_coordinates')->nullable();
            $t->timestamps();
        });
        $schema->create('odp', function (Blueprint $t) {
            $t->integer('id');
            $t->string('name');
            $t->integer('source_id')->nullable();
            $t->integer('port_number_in_odc')->nullable();
            $t->string('source_type')->nullable();
            $t->decimal('lat', 10, 8);
            $t->decimal('lng', 11, 8);
            $t->text('path_coordinates')->nullable();
            $t->string('location')->default('');
            $t->integer('total_ports')->default(8);
            $t->text('description')->nullable();
            $t->timestamps();
        });
        $schema->create('odp_ports', function (Blueprint $t) {
            $t->integer('id');
            $t->integer('odp_id');
            $t->integer('port_number');
            $t->string('status')->default('available');
            $t->string('target')->nullable();
            $t->decimal('lat', 10, 8)->nullable();
            $t->decimal('lng', 11, 8)->nullable();
            $t->string('onu_number')->nullable();
            $t->string('modem_type')->nullable();
            $t->text('description')->nullable();
            $t->text('path_coordinates')->nullable();
            $t->timestamps();
        });
        $schema->create('users', function (Blueprint $t) {
            $t->integer('id');
            $t->string('username');
            $t->string('password');
            $t->string('full_name')->nullable();
            $t->string('email')->nullable();
            $t->string('role');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    private function seedLegacyData(): void
    {
        $db = DB::connection('legacy');
        $db->table('pop')->insert(['id' => 1, 'name' => 'POP Kajen', 'code' => 'KJN', 'lat' => -7.0, 'lng' => 109.5]);
        $db->table('olt')->insert(['id' => 5, 'pop_id' => 1, 'name' => 'ZTE KJN-01', 'model' => 'ZTE-C320', 'total_pon_ports' => 2]);
        $db->table('pon')->insert(['id' => 13, 'olt_id' => 5, 'name' => 'PON Card 1']);
        $db->table('odc')->insert([
            'id' => 3, 'name' => 'KJN-0101 MS-02', 'lat' => -7.01, 'lng' => 109.5, 'capacity' => 8, 'pon_id' => 13, 'pon_port_number' => 2, 'olt_id' => 5,
            // Feeder from the POP to the ODC.
            'path_coordinates' => json_encode([[-7.0, 109.5], [-7.005, 109.5001], [-7.01, 109.5]]),
        ]);
        $db->table('odp')->insert([
            // Distribution route stored the old way: starting at the ODP.
            ['id' => 10, 'name' => 'ODP-A', 'source_id' => 3, 'source_type' => 'odc', 'port_number_in_odc' => 1, 'lat' => -7.012, 'lng' => 109.501, 'total_ports' => 8, 'path_coordinates' => json_encode([[-7.012, 109.501], [-7.011, 109.5005], [-7.01, 109.5]])],
            // Same port on the ODC as ODP-A.
            ['id' => 11, 'name' => 'ODP-B', 'source_id' => 3, 'source_type' => 'odc', 'port_number_in_odc' => 1, 'lat' => -7.013, 'lng' => 109.502, 'total_ports' => 9, 'path_coordinates' => null],
            // Recorded twice at the same spot: customers on one copy, uplink on the other.
            ['id' => 20, 'name' => 'ODP-DUP', 'source_id' => null, 'source_type' => null, 'port_number_in_odc' => null, 'lat' => -7.02, 'lng' => 109.51, 'total_ports' => 8, 'path_coordinates' => null],
            ['id' => 21, 'name' => 'ODP-DUP', 'source_id' => 3, 'source_type' => 'odc', 'port_number_in_odc' => 2, 'lat' => -7.02, 'lng' => 109.51, 'total_ports' => 8, 'path_coordinates' => null],
            // Same name, different place.
            ['id' => 30, 'name' => 'ODP-DUP', 'source_id' => null, 'source_type' => null, 'port_number_in_odc' => null, 'lat' => -7.03, 'lng' => 109.52, 'total_ports' => 8, 'path_coordinates' => null],
        ]);
        $db->table('odp_ports')->insert([
            ['id' => 1, 'odp_id' => 10, 'port_number' => 1, 'status' => 'used', 'target' => 'BudiSantoso0101108@qc.net', 'lat' => -7.0125, 'lng' => 109.5015, 'onu_number' => 'ZTEG1234', 'modem_type' => 'F609', 'description' => null, 'path_coordinates' => json_encode([[-7.012, 109.501], [-7.0125, 109.5015]])],
            ['id' => 2, 'odp_id' => 10, 'port_number' => 2, 'status' => 'used', 'target' => 'x', 'lat' => null, 'lng' => null, 'onu_number' => null, 'modem_type' => null, 'description' => null, 'path_coordinates' => null],
            ['id' => 3, 'odp_id' => 10, 'port_number' => 3, 'status' => 'maintenance', 'target' => null, 'lat' => null, 'lng' => null, 'onu_number' => null, 'modem_type' => null, 'description' => 'konektor patah', 'path_coordinates' => null],
            ['id' => 4, 'odp_id' => 11, 'port_number' => 9, 'status' => 'used', 'target' => 'Siti', 'lat' => null, 'lng' => null, 'onu_number' => null, 'modem_type' => null, 'description' => null, 'path_coordinates' => null],
            ['id' => 5, 'odp_id' => 20, 'port_number' => 1, 'status' => 'used', 'target' => 'Andi@qc.net', 'lat' => null, 'lng' => null, 'onu_number' => null, 'modem_type' => null, 'description' => null, 'path_coordinates' => null],
            ['id' => 6, 'odp_id' => 21, 'port_number' => 1, 'status' => 'available', 'target' => null, 'lat' => null, 'lng' => null, 'onu_number' => null, 'modem_type' => null, 'description' => null, 'path_coordinates' => null],
        ]);
        $db->table('users')->insert([
            ['id' => 1, 'username' => 'qcadmin', 'password' => Hash::make('rahasia-lama'), 'full_name' => null, 'email' => null, 'role' => 'admin', 'is_active' => true],
            ['id' => 2, 'username' => 'operator', 'password' => Hash::make('x'), 'full_name' => 'Operator Satu', 'email' => null, 'role' => 'operator', 'is_active' => true],
            ['id' => 3, 'username' => 'lama', 'password' => Hash::make('x'), 'full_name' => null, 'email' => null, 'role' => 'viewer', 'is_active' => false],
        ]);
    }

    private function migrate(): void
    {
        $this->artisan('migrasi:fiber-manager', ['--connection' => 'legacy'])->assertSuccessful();
    }

    public function test_network_is_migrated_into_areas_with_uplinks()
    {
        $this->migrate();

        $area = Area::sole();
        $this->assertSame(['KJN', 'POP Kajen'], [$area->code, $area->name]);

        $olt = Olt::sole();
        $this->assertSame([-7.0, 109.5], [$olt->lat, $olt->lng]); // placed at its POP

        $odc = Odc::sole();
        $this->assertSame(['KJN-0101 MS-02', $olt->id, 2, $area->id], [$odc->code, $odc->olt_id, $odc->olt_pon_port, $odc->area_id]);
        $this->assertStringContainsString('PON Card 1', $odc->notes);

        $a = Odp::firstWhere('code', 'ODP-A');
        $b = Odp::firstWhere('code', 'ODP-B');
        $this->assertSame([$odc->id, 1], [$a->odc_id, $a->odc_port]);
        $this->assertSame([$odc->id, null, 9], [$b->odc_id, $b->odc_port, $b->capacity]); // port clash cleared; odd capacity kept
    }

    public function test_duplicates_are_merged_or_renamed()
    {
        $this->migrate();

        $this->assertSame(['ODP-A', 'ODP-B', 'ODP-DUP', 'ODP-DUP (2)'], Odp::orderBy('code')->pluck('code')->all());

        $merged = Odp::firstWhere('code', 'ODP-DUP');
        $this->assertSame(2, $merged->odc_port); // uplink from one copy
        $this->assertSame('Andi', $merged->ports()->where('number', 1)->first()->customer->name); // customer from the other
    }

    public function test_ports_and_customers()
    {
        $this->migrate();
        $ports = Odp::firstWhere('code', 'ODP-A')->ports()->with('customer')->get()->keyBy('number');

        $this->assertSame(PortStatus::Terpakai, $ports[1]->status);
        $this->assertSame(['BudiSantoso0101108', 'BudiSantoso0101108@qc.net', 'ZTEG1234', -7.0125], [
            $ports[1]->customer->name, $ports[1]->customer->customer_code, $ports[1]->customer->ont_sn, $ports[1]->customer->lat,
        ]);
        $this->assertStringContainsString('Modem: F609', $ports[1]->notes);

        $this->assertSame(PortStatus::Terpakai, $ports[2]->status); // "x" placeholder: in use, no customer record
        $this->assertNull($ports[2]->customer);

        $this->assertSame([PortStatus::Rusak, 'konektor patah'], [$ports[3]->status, $ports[3]->notes]);
        $this->assertSame(3, Customer::count()); // Budi, Siti, Andi
    }

    public function test_routes_become_connected_cables()
    {
        $this->migrate();
        $odc = Odc::sole();
        $odpA = Odp::firstWhere('code', 'ODP-A');

        $feeder = Cable::where('type', 'feeder')->sole();
        $this->assertSame(['olt', 'odc', $odc->id], [$feeder->from_type, $feeder->to_type, $feeder->to_id]);

        $distribusi = Cable::where('type', 'distribusi')->sole();
        $this->assertSame([$odc->id, $odpA->id], [$distribusi->from_id, $distribusi->to_id]);
        $this->assertSame([-7.01, 109.5], $distribusi->path[0]); // reversed to run ODC → ODP

        $drop = Cable::where('type', 'drop')->sole();
        $this->assertSame(['odp', $odpA->id, null], [$drop->from_type, $drop->from_id, $drop->to_type]);
        $this->assertSame([-7.012, 109.501], $drop->path[0]);
    }

    public function test_users_keep_their_passwords_and_see_all_areas()
    {
        $this->migrate();

        $admin = User::firstWhere('username', 'qcadmin');
        $this->assertSame([Role::SuperAdmin, null, null], [$admin->role, $admin->area_id, $admin->email]);
        $this->assertSame(Role::Teknisi, User::firstWhere('username', 'operator')->role);
        $this->assertNull(User::firstWhere('username', 'lama')); // inactive

        $this->post('/login', ['login' => 'qcadmin', 'password' => 'rahasia-lama']);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_refuses_to_run_twice()
    {
        $this->migrate();

        $this->artisan('migrasi:fiber-manager', ['--connection' => 'legacy'])->assertFailed();
        $this->assertSame(1, Odc::count());
    }
}
