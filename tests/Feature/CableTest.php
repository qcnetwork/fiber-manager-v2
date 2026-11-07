<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Cable;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CableTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = Area::factory()->create();
        $this->actingAs(User::factory()->create(['area_id' => $this->area->id, 'role' => Role::AdminArea]));
    }

    public function test_cable_ends_snap_to_their_assets_and_length_is_computed()
    {
        $odc = Odc::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.8]);
        $odp = Odp::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.802]);

        $this->post('/cables', [
            'type' => 'distribusi', 'cores' => 12, 'status' => 'aktif',
            'from_type' => 'odc', 'from_id' => $odc->id, 'to_type' => 'odp', 'to_id' => $odp->id,
            // Ends were clicked slightly off the markers.
            'path' => [[-6.20001, 106.80001], [-6.2010, 106.8010], [-6.20002, 106.80198]],
        ])->assertSessionHasNoErrors();

        $cable = Cable::sole();
        $this->assertSame([-6.2, 106.8], $cable->path[0]);
        $this->assertSame([-6.2, 106.802], $cable->path[2]);
        $this->assertSame($this->area->id, $cable->area_id);
        // Two legs of ~157 m each via the bend.
        $this->assertEqualsWithDelta(314, $cable->length_m, 5);
        $this->assertSame(-6.201, $cable->min_lat);
    }

    public function test_moving_or_deleting_an_odp_updates_attached_cables()
    {
        $odc = Odc::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.8]);
        $odp = Odp::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.801]);
        $cable = Cable::create([
            'area_id' => $this->area->id, 'type' => 'distribusi', 'cores' => 12,
            'from_type' => 'odc', 'from_id' => $odc->id, 'to_type' => 'odp', 'to_id' => $odp->id,
            'path' => [[-6.2, 106.8], [-6.2, 106.801]],
        ]);

        $odp->update(['lat' => -6.201, 'lng' => 106.801]);
        $this->assertSame([-6.201, 106.801], $cable->fresh()->path[1]);

        $odp->delete();
        $cable->refresh();
        $this->assertNull($cable->to_id);
        $this->assertSame('odc', $cable->from_type);
    }

    public function test_cable_records_the_assets_it_passes_through_in_order()
    {
        $odc = Odc::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.8]);
        $odp1 = Odp::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.801]);
        $odp2 = Odp::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.802]);
        $odp3 = Odp::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.803]);

        $this->post('/cables', [
            'type' => 'distribusi', 'cores' => 12, 'status' => 'aktif',
            'from_type' => 'odc', 'from_id' => $odc->id, 'to_type' => 'odp', 'to_id' => $odp3->id,
            'path' => [[-6.2, 106.8], [-6.20001, 106.80101], [-6.2005, 106.8015], [-6.2, 106.802], [-6.2, 106.803]],
            'stops' => [
                ['vertex' => 3, 'type' => 'odp', 'id' => $odp2->id],
                ['vertex' => 1, 'type' => 'odp', 'id' => $odp1->id],
                ['vertex' => 4, 'type' => 'odp', 'id' => $odp3->id], // the end: already to_id, ignored
            ],
        ])->assertSessionHasNoErrors();

        $cable = Cable::sole();
        $this->assertSame([-6.2, 106.801], $cable->path[1]); // snapped onto ODP 1
        $this->assertSame([1, 3], $cable->stops->pluck('vertex')->all());

        $route = $this->getJson("/cables/{$cable->id}")->assertOk()->json('cable.route');
        $this->assertSame(['from', 'via', 'via', 'to'], array_column($route, 'role'));
        $this->assertSame([$odc->code, $odp1->code, $odp2->code, $odp3->code], array_column(array_column($route, 'asset'), 'code'));
        $this->assertSame(0, $route[0]['distance_m']);
        $this->assertEqualsWithDelta(110, $route[1]['distance_m'], 3);
        $this->assertSame($cable->length_m, $route[3]['distance_m']);

        // Editing replaces the pass-through points.
        $this->put("/cables/{$cable->id}", [
            'type' => 'distribusi', 'cores' => 12, 'status' => 'aktif',
            'from_type' => 'odc', 'from_id' => $odc->id,
            'path' => [[-6.2, 106.8], [-6.2, 106.802], [-6.2, 106.803]],
            'stops' => [['vertex' => 1, 'type' => 'odp', 'id' => $odp2->id]],
        ])->assertSessionHasNoErrors();
        $this->assertSame([$odp2->id], $cable->stops()->pluck('asset_id')->all());
    }

    public function test_moving_or_deleting_a_pass_through_odp_updates_the_cable()
    {
        $odp = Odp::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.801]);
        $cable = Cable::create([
            'area_id' => $this->area->id, 'type' => 'distribusi', 'cores' => 12,
            'path' => [[-6.2, 106.8], [-6.2, 106.801], [-6.2, 106.802]],
        ]);
        $cable->syncStops([['vertex' => 1, 'type' => 'odp', 'id' => $odp->id]]);

        $odp->update(['lat' => -6.201, 'lng' => 106.8012]);
        $this->assertSame([-6.201, 106.8012], $cable->fresh()->path[1]);

        $odp->delete();
        $this->assertSame(0, $cable->stops()->count());
        $this->assertCount(3, $cable->fresh()->path);
    }

    public function test_pass_through_point_must_be_in_the_same_area()
    {
        $foreign = Odp::factory()->create();
        Odp::withoutGlobalScopes()->whereKey($foreign->id)->update(['area_id' => Area::factory()->create()->id]);

        $this->post('/cables', [
            'type' => 'distribusi', 'cores' => 12, 'status' => 'aktif',
            'path' => [[-6.2, 106.8], [-6.2, 106.801], [-6.2, 106.802]],
            'stops' => [['vertex' => 1, 'type' => 'odp', 'id' => $foreign->id]],
        ])->assertSessionHasErrors('stops.0.id');
    }

    public function test_nearby_lists_cables_passing_close_to_a_point()
    {
        $near = Cable::create(['area_id' => $this->area->id, 'type' => 'distribusi', 'cores' => 12, 'path' => [[-6.2, 106.8], [-6.2, 106.802]]]);
        Cable::create(['area_id' => $this->area->id, 'type' => 'distribusi', 'cores' => 12, 'path' => [[-6.21, 106.8], [-6.21, 106.802]]]);

        // ~11 m north of the middle of the first cable; the second is >1 km away.
        $cables = $this->getJson('/cables/nearby?lat=-6.1999&lng=106.801')->assertOk()->json('cables');

        $this->assertSame([$near->id], array_column($cables, 'id'));
        $this->assertEqualsWithDelta(11, $cables[0]['distance_m'], 1);
        $this->assertFalse($cables[0]['connected']);
    }

    public function test_new_odp_can_be_connected_to_a_nearby_cable()
    {
        $odc = Odc::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.8]);
        $cable = Cable::create([
            'area_id' => $this->area->id, 'type' => 'distribusi', 'cores' => 12,
            'from_type' => 'odc', 'from_id' => $odc->id,
            'path' => [[-6.2, 106.8], [-6.2, 106.802], [-6.2, 106.804]],
        ]);
        $existing = Odp::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.802]);
        $cable->syncStops([['vertex' => 1, 'type' => 'odp', 'id' => $existing->id]]);

        // Beside the first segment: a vertex is inserted there and the later pass-through point shifts along.
        $this->post('/odps', [
            'code' => 'ODP-BARU', 'status' => 'aktif', 'capacity' => 8,
            'lat' => -6.20005, 'lng' => 106.801, 'connect_cables' => [$cable->id],
        ])->assertSessionHasNoErrors();

        $new = Odp::where('code', 'ODP-BARU')->sole();
        $cable->refresh();
        $this->assertCount(4, $cable->path);
        $this->assertSame([-6.20005, 106.801], $cable->path[1]);
        $this->assertSame([[1, $new->id], [2, $existing->id]], $cable->stops->map(fn ($s) => [$s->vertex, $s->asset_id])->all());
        $this->assertSame([-6.2, 106.802], $cable->path[2]);

        // Near the free far end: attaches as that end, no new vertex.
        $this->post('/odps', [
            'code' => 'ODP-UJUNG', 'status' => 'aktif', 'capacity' => 8,
            'lat' => -6.20001, 'lng' => 106.80401, 'connect_cables' => [$cable->id],
        ])->assertSessionHasNoErrors();
        $cable->refresh();
        $this->assertSame(Odp::where('code', 'ODP-UJUNG')->value('id'), $cable->to_id);
        $this->assertCount(4, $cable->path);

        // Saving again with the same cable ticked changes nothing.
        $this->put("/odps/{$new->id}", [
            'code' => 'ODP-BARU', 'status' => 'aktif', 'capacity' => 8,
            'lat' => -6.20005, 'lng' => 106.801, 'connect_cables' => [$cable->id],
        ])->assertSessionHasNoErrors();
        $this->assertCount(4, $cable->fresh()->path);
        $this->assertTrue($this->getJson("/cables/nearby?lat=-6.20005&lng=106.801&kind=odp&id={$new->id}")->json('cables.0.connected'));
    }

    public function test_cannot_connect_to_a_cable_in_another_area()
    {
        // Created while signed in, so the area hook puts it in our area; move it out directly.
        $cable = Cable::create(['type' => 'drop', 'cores' => 1, 'path' => [[-6.2, 106.8], [-6.2, 106.801]]]);
        Cable::withoutGlobalScopes()->whereKey($cable->id)->update(['area_id' => Area::factory()->create()->id]);

        $this->post('/odcs', [
            'code' => 'ODC-X', 'status' => 'aktif', 'capacity' => 48,
            'lat' => -6.2, 'lng' => 106.8005, 'connect_cables' => [$cable->id],
        ])->assertSessionHasErrors('connect_cables.0');
    }

    public function test_detect_command_records_bends_that_sit_on_an_asset()
    {
        $odp = Odp::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.801]);
        $far = Odp::factory()->for($this->area)->create(['lat' => -6.2005, 'lng' => 106.8015]);
        $cable = Cable::create([
            'area_id' => $this->area->id, 'type' => 'distribusi', 'cores' => 12,
            // Vertex 1 is ~1 m from the ODP; vertex 2 is ~20 m from the other one.
            'path' => [[-6.2, 106.8], [-6.20001, 106.801], [-6.2003, 106.8016], [-6.2, 106.802]],
        ]);

        $this->artisan('kabel:deteksi-sambungan')->assertSuccessful();
        $this->assertSame(0, $cable->stops()->count()); // a dry run saves nothing

        $this->artisan('kabel:deteksi-sambungan --simpan')->assertSuccessful();
        $this->assertSame([[1, $odp->id]], $cable->stops()->get()->map(fn ($s) => [$s->vertex, $s->asset_id])->all());

        $this->artisan('kabel:deteksi-sambungan --simpan')->assertSuccessful();
        $this->assertSame(1, $cable->stops()->count()); // running again adds no duplicates
        $this->assertNotNull($far);
    }

    public function test_cable_cannot_end_at_an_asset_in_another_area()
    {
        // Created while signed in, so the area hook puts it in our area; move it out directly.
        $foreign = Odp::factory()->create();
        Odp::withoutGlobalScopes()->whereKey($foreign->id)->update(['area_id' => Area::factory()->create()->id]);

        $this->post('/cables', [
            'type' => 'drop', 'cores' => 1, 'status' => 'aktif',
            'to_type' => 'odp', 'to_id' => $foreign->id,
            'path' => [[-6.2, 106.8], [-6.2, 106.801]],
        ])->assertSessionHasErrors('to_id');
    }

    public function test_map_returns_cables_crossing_the_viewport()
    {
        // Both vertices lie outside the viewport, but the line crosses it.
        Cable::create([
            'area_id' => $this->area->id, 'type' => 'feeder', 'cores' => 48,
            'path' => [[-6.2, 106.7], [-6.2, 106.9]],
        ]);
        Cable::create([
            'area_id' => $this->area->id, 'type' => 'feeder', 'cores' => 48,
            'path' => [[-7.2, 106.7], [-7.2, 106.9]],
        ]);

        $cables = $this->getJson('/map/features?bbox=106.79,-6.21,106.81,-6.19')->assertOk()->json('cables');

        $this->assertCount(1, $cables);
    }
}
