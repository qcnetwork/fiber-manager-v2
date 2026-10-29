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
