<?php

namespace Tests\Feature;

use App\Enums\PortStatus;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Cable;
use App\Models\Odp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoverageTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        config(['odp.coverage_radius' => 250]);
        $this->area = Area::factory()->create();
    }

    private function sales(): User
    {
        return User::factory()->create(['role' => Role::Sales, 'area_id' => null]);
    }

    /**
     * An ODP east of (-6.2, 106.8); 0.001° of longitude is ~110 m at this latitude.
     */
    private function odpAt(float $lngOffset, array $attributes = []): Odp
    {
        return Odp::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.8 + $lngOffset, ...$attributes]);
    }

    private function check(): array
    {
        return $this->getJson('/coverage/check?lat=-6.2&lng=106.8')->assertOk()->json();
    }

    public function test_covered_when_a_free_active_odp_is_within_250_m()
    {
        $near = $this->odpAt(0.002); // ~220 m
        $this->odpAt(0.0024); // ~265 m, outside

        $this->actingAs($this->sales());
        $result = $this->check();

        $this->assertSame('tercover', $result['status']);
        $this->assertSame(250, $result['radius']);
        $this->assertSame([$near->id], array_column($result['available'], 'id'));
        $this->assertEqualsWithDelta(220, $result['available'][0]['distance'], 5);
        $this->assertSame(8, $result['available'][0]['free_ports']);
        $this->assertArrayNotHasKey('ports', $result['available'][0]); // no customer data for sales
    }

    public function test_full_when_nearby_odps_have_no_free_port()
    {
        $odp = $this->odpAt(0.001, ['capacity' => 2]);
        $odp->ports()->update(['status' => PortStatus::Terpakai]);

        $this->actingAs($this->sales());
        $result = $this->check();

        $this->assertSame('penuh', $result['status']);
        $this->assertSame([$odp->id], array_column($result['full'], 'id'));
    }

    public function test_not_covered_shows_the_nearest_free_odp_beyond_the_radius()
    {
        $this->odpAt(0.001, ['status' => 'rencana']); // planned ODPs don't count
        $far = $this->odpAt(0.004); // ~440 m

        $this->actingAs($this->sales());
        $result = $this->check();

        $this->assertSame('tidak_tercover', $result['status']);
        $this->assertCount(1, $result['planned']);
        $this->assertSame($far->id, $result['nearest_outside']['id']);
    }

    public function test_radius_comes_from_configuration()
    {
        config(['odp.coverage_radius' => 100]);
        $this->odpAt(0.002); // ~220 m

        $this->actingAs($this->sales());

        $this->assertSame('tidak_tercover', $this->check()['status']);
    }

    public function test_sales_only_reach_the_coverage_tools()
    {
        $odp = $this->odpAt(0.001);
        $this->actingAs($this->sales());

        $this->get('/coverage')->assertOk();
        $this->get('/dashboard')->assertRedirect('/coverage');
        $this->get('/map')->assertRedirect('/coverage');
        $this->getJson("/odps/{$odp->id}")->assertForbidden(); // would include customers
        $this->get('/data/export/xlsx')->assertRedirect('/coverage');
        $this->post('/odps', [])->assertForbidden();
        // No cable routes: not on the map, not in search, not their endpoints.
        $cable = Cable::create(['area_id' => $this->area->id, 'code' => 'KBL-SALES', 'type' => 'feeder', 'cores' => 12, 'path' => [[-6.2, 106.8], [-6.2, 106.802]]]);
        $this->assertSame([], $this->getJson('/map/features?bbox=106,-7,107,-6')->assertOk()->json('cables'));
        $this->assertSame([], $this->getJson('/map/search?q=KBL-SALES')->assertOk()->json('cables') ?? []);
        $this->getJson("/cables/{$cable->id}")->assertForbidden();
        $this->getJson('/cables/nearby?lat=-6.2&lng=106.801')->assertForbidden();
    }

    public function test_staff_can_use_coverage_too_and_their_area_still_applies()
    {
        $this->odpAt(0.001);
        $otherArea = Area::factory()->create();
        $this->actingAs(User::factory()->create(['role' => Role::Teknisi, 'area_id' => $otherArea->id]));

        $this->get('/coverage')->assertOk();
        $this->assertSame('tidak_tercover', $this->check()['status']); // the ODP belongs to another area
    }
}
