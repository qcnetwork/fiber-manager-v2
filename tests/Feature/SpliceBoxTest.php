<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Cable;
use App\Models\Odc;
use App\Models\SpliceBox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpliceBoxTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = Area::factory()->create();
        $this->actingAs(User::factory()->create(['area_id' => $this->area->id, 'role' => Role::AdminArea]));
    }

    public function test_joint_box_can_be_created_and_connected_to_a_nearby_cable()
    {
        $cable = Cable::create(['type' => 'feeder', 'cores' => 24, 'path' => [[-6.2, 106.8], [-6.2, 106.802]]]);

        $this->post('/joints', [
            'type' => 'joint_box', 'code' => 'JB-01', 'status' => 'aktif', 'capacity' => 48, 'pole_number' => 'T-12',
            'lat' => -6.20003, 'lng' => 106.801, 'connect_cables' => [$cable->id],
        ])->assertSessionHasNoErrors();

        $joint = SpliceBox::sole();
        $this->assertSame($this->area->id, $joint->area_id);
        $this->assertTrue($cable->fresh()->connects($joint));

        $response = $this->getJson("/joints/{$joint->id}")->assertOk();
        $this->assertSame('joint_box', $response->json('joint.type'));
        $this->assertSame([$cable->id], array_column($response->json('cables'), 'id'));
    }

    public function test_cable_can_end_and_pass_through_joints_and_follows_them()
    {
        $odc = Odc::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.8]);
        $roset = SpliceBox::factory()->for($this->area)->create(['type' => 'roset', 'lat' => -6.2, 'lng' => 106.801]);
        $joint = SpliceBox::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.802]);

        $this->post('/cables', [
            'type' => 'distribusi', 'cores' => 12, 'status' => 'aktif',
            'from_type' => 'odc', 'from_id' => $odc->id, 'to_type' => 'joint', 'to_id' => $joint->id,
            'path' => [[-6.2, 106.8], [-6.2, 106.801], [-6.2, 106.802]],
            'stops' => [['vertex' => 1, 'type' => 'joint', 'id' => $roset->id]],
        ])->assertSessionHasNoErrors();

        $cable = Cable::sole();
        $route = $this->getJson("/cables/{$cable->id}")->json('cable.route');
        $this->assertSame(['odc', 'joint', 'joint'], array_column($route, 'kind'));

        $joint->update(['lat' => -6.201]);
        $this->assertSame([-6.201, 106.802], $cable->fresh()->path[2]);
    }

    public function test_map_features_and_search_include_joints()
    {
        SpliceBox::factory()->for($this->area)->create(['code' => 'JB-PASAR', 'lat' => -6.2, 'lng' => 106.8]);

        $this->assertCount(1, $this->getJson('/map/features?bbox=106.79,-6.21,106.81,-6.19')->json('joints'));
        $results = $this->getJson('/map/search?q=pasar')->json();
        $this->assertSame('joint', $results[0]['kind']);
        $this->assertSame('Joint box', $results[0]['name']);
    }

    public function test_deleting_a_joint_detaches_cables()
    {
        $joint = SpliceBox::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.8]);
        $cable = Cable::create([
            'type' => 'drop', 'cores' => 1, 'from_type' => 'joint', 'from_id' => $joint->id,
            'path' => [[-6.2, 106.8], [-6.2, 106.801]],
        ]);

        $this->delete("/joints/{$joint->id}")->assertSessionHasNoErrors();

        $this->assertNull($cable->fresh()->from_id);
        $this->assertSame(0, SpliceBox::count());
    }
}
