<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Cable;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\Olt;
use App\Models\SpliceBox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cables and assets link up across area borders.
 */
class CrossAreaTest extends TestCase
{
    use RefreshDatabase;

    private Area $north;

    private Area $south;

    protected function setUp(): void
    {
        parent::setUp();

        $this->north = Area::factory()->create(['code' => 'UTR']);
        $this->south = Area::factory()->create(['code' => 'SLT']);
        $this->actingAs(User::factory()->create(['area_id' => null, 'role' => Role::SuperAdmin]));
    }

    public function test_a_cable_can_run_between_assets_of_different_areas()
    {
        $odc = Odc::factory()->for($this->north)->create(['lat' => -6.2, 'lng' => 106.8]);
        $joint = SpliceBox::factory()->for($this->south)->create(['lat' => -6.2, 'lng' => 106.801]);
        $odp = Odp::factory()->for($this->south)->create(['lat' => -6.2, 'lng' => 106.802]);

        $this->post('/cables', [
            'area_id' => $this->north->id, 'type' => 'feeder', 'cores' => 12, 'status' => 'aktif',
            'path' => [[-6.2, 106.8], [-6.2, 106.801], [-6.2, 106.802]],
            'from_type' => 'odc', 'from_id' => $odc->id, 'to_type' => 'odp', 'to_id' => $odp->id,
            'stops' => [['vertex' => 1, 'type' => 'joint', 'id' => $joint->id]],
        ])->assertSessionHasNoErrors();

        $cable = Cable::sole();
        $this->assertTrue($cable->connects($odp) && $cable->connects($joint));

        // The south's map shows the north cable reaching into it.
        $ids = fn (Area $area) => array_column($this->getJson("/map/features?bbox=106,-7,107,-6&area_id={$area->id}")->json('cables'), 'id');
        $this->assertSame([$cable->id], $ids($this->south));
        $this->assertSame([], $ids(Area::factory()->create()));

        // Its cores can be spliced at the south joint.
        $data = $this->getJson("/splices?location_type=joint&location_id={$joint->id}")->assertOk()->json();
        $this->assertSame([$cable->id], array_column($data['cables'], 'id'));
    }

    public function test_an_asset_can_be_connected_to_a_cable_of_another_area()
    {
        $cable = Cable::create(['area_id' => $this->north->id, 'type' => 'feeder', 'cores' => 12, 'path' => [[-6.2, 106.8], [-6.2, 106.802]]]);

        $nearby = $this->getJson("/cables/nearby?lat=-6.2&lng=106.801&area_id={$this->south->id}")->json('cables');
        $this->assertSame([[$cable->id, 'UTR']], array_map(fn ($c) => [$c['id'], $c['area']['code']], $nearby));

        $this->post('/joints', [
            'area_id' => $this->south->id, 'type' => 'joint_box', 'status' => 'aktif',
            'lat' => -6.20001, 'lng' => 106.801, 'connect_cables' => [$cable->id],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($cable->fresh()->connects(SpliceBox::sole()));
    }

    public function test_odp_and_odc_can_hang_off_another_area()
    {
        $olt = Olt::create(['area_id' => $this->north->id, 'code' => 'OLT-UTR', 'name' => 'OLT']);
        $olt->syncPorts([1 => 8]);
        $port = $olt->ports()->firstOrFail();

        // An ODC in the south fed from the north POP.
        $this->post('/odcs', [
            'area_id' => $this->south->id, 'code' => 'ODC-SLT', 'status' => 'aktif', 'capacity' => 8,
            'lat' => -6.2, 'lng' => 106.8, 'olt_port_id' => $port->id,
        ])->assertSessionHasNoErrors();
        $odc = Odc::sole();
        $this->assertSame($port->id, $odc->olt_port_id);

        // An ODP in the north on that south ODC.
        $this->post('/odps', [
            'area_id' => $this->north->id, 'code' => 'ODP-UTR', 'status' => 'aktif', 'capacity' => 8,
            'lat' => -6.2001, 'lng' => 106.8, 'odc_id' => $odc->id, 'odc_port' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame($odc->id, Odp::sole()->odc_id);

        // Both pickers list the other area's options.
        $this->assertSame('SLT', $this->getJson('/odcs/options?lat=-6.2&lng=106.8')->json('0.area.code'));
        $this->assertSame('UTR', $this->getJson('/olts/options')->json('0.area.code'));
    }
}
