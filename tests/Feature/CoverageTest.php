<?php

namespace Tests\Feature;

use App\Enums\PortStatus;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Cable;
use App\Models\Odp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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

    private function fakeRouting(array $tableDistances, array $snaps = [0, 0, 0, 0]): void
    {
        config(['odp.routing.url' => 'https://osrm.test/routed-foot']);
        Http::fake([
            'osrm.test/routed-foot/table/*' => Http::response([
                'code' => 'Ok',
                'distances' => [[0, ...$tableDistances]],
                'sources' => [['distance' => $snaps[0]]],
                'destinations' => array_map(fn ($d) => ['distance' => $d], $snaps),
            ]),
            'osrm.test/routed-foot/route/*' => Http::response([
                'code' => 'Ok',
                'routes' => [['geometry' => ['type' => 'LineString', 'coordinates' => [[106.8, -6.2001], [106.801, -6.2001]]]]],
            ]),
        ]);
    }

    public function test_distance_follows_the_roads_when_routing_works()
    {
        // 110 m straight but 400 m by road (across a river): not covered.
        $acrossRiver = $this->odpAt(0.001);
        // 165 m straight, 180 m by road plus 10 m from each point to the road.
        $sameStreet = $this->odpAt(0.0015);
        $this->fakeRouting([400, 180], [10, 10, 10]);

        $this->actingAs($this->sales());
        $result = $this->check();

        $this->assertSame('jalan', $result['distance_mode']);
        $this->assertSame([$sameStreet->id], array_column($result['available'], 'id'));
        $this->assertSame(200, $result['available'][0]['distance']);
        $this->assertEqualsWithDelta(165, $result['available'][0]['straight_distance'], 5);
        // The path runs from the point, along the road, to the ODP.
        $route = $result['available'][0]['route'];
        $this->assertSame([-6.2, 106.8], $route[0]);
        $this->assertSame([-6.2001, 106.8], $route[1]);
        $this->assertEqualsWithDelta([$sameStreet->lat, $sameStreet->lng], end($route), 1e-9);
        $this->assertNotContains($acrossRiver->id, array_column($result['full'], 'id'));

        // OSRM gets lng,lat with the customer first, and an identifying User-Agent.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/table/v1/driving/106.8,-6.2;106.801,-6.2;106.8015,-6.2')
            && str_starts_with($request->header('User-Agent')[0], 'FiberManager'));
    }

    public function test_odp_only_close_in_a_straight_line_is_not_covered_by_road()
    {
        $acrossRiver = $this->odpAt(0.001); // ~110 m straight
        $this->fakeRouting([400]);

        $this->actingAs($this->sales());
        $result = $this->check();

        $this->assertSame('tidak_tercover', $result['status']);
        // Shown as the closest free ODP, with its road distance.
        $this->assertSame($acrossRiver->id, $result['nearest_outside']['id']);
        $this->assertSame(400, $result['nearest_outside']['distance']);
    }

    public function test_falls_back_to_straight_lines_when_routing_fails()
    {
        $near = $this->odpAt(0.002); // ~220 m
        config(['odp.routing.url' => 'https://osrm.test/routed-foot']);
        Http::fake(['osrm.test/*' => Http::response('Bad Gateway', 502)]);

        $this->actingAs($this->sales());
        $result = $this->check();

        $this->assertSame('lurus', $result['distance_mode']);
        $this->assertSame('tercover', $result['status']);
        $this->assertSame([$near->id], array_column($result['available'], 'id'));
        $this->assertNull($result['available'][0]['route']);
    }

    public function test_an_unreachable_routing_server_is_skipped_for_a_while()
    {
        $this->odpAt(0.001);
        config(['odp.routing.url' => 'https://osrm.test/routed-foot']);
        $attempts = 0;
        $up = false;
        Http::fake(function () use (&$attempts, &$up) {
            $attempts++;
            if (! $up) {
                throw new ConnectionException('timed out');
            }

            return Http::response(['code' => 'Ok', 'distances' => [[0, 120]], 'sources' => [[]], 'destinations' => [[], []], 'routes' => []]);
        });
        $this->actingAs($this->sales());

        $this->assertSame('lurus', $this->check()['distance_mode']);
        $this->assertSame('lurus', $this->check()['distance_mode']);
        $this->assertSame(1, $attempts); // the second check didn't wait for the server again

        // Tried again after a few minutes.
        $up = true;
        $this->travel(6)->minutes();
        $this->assertSame('jalan', $this->check()['distance_mode']);
    }

    public function test_routing_answers_are_cached()
    {
        $this->odpAt(0.001);
        $this->fakeRouting([120]);
        $this->actingAs($this->sales());

        $this->check();
        $this->check();

        Http::assertSentCount(2); // one table + one route, only the first time
    }

    public function test_road_distances_can_be_turned_off_for_speed()
    {
        $this->odpAt(0.001);
        $this->fakeRouting([120]);
        $this->actingAs($this->sales());

        $result = $this->getJson('/coverage/check?lat=-6.2&lng=106.8&road=0')->assertOk()->json();

        $this->assertSame('lurus', $result['distance_mode']);
        Http::assertNothingSent();
    }

    public function test_super_admin_sets_the_radius_for_everyone()
    {
        $this->odpAt(0.002); // ~220 m
        $admin = User::factory()->create(['role' => Role::SuperAdmin, 'area_id' => null]);

        $this->actingAs($admin)->put('/admin/coverage-radius', ['radius' => 100])->assertSessionHasNoErrors();
        $this->put('/admin/coverage-radius', ['radius' => 5])->assertSessionHasErrors('radius');

        // Applies to the checks and pages of other users.
        $this->actingAs($this->sales());
        $result = $this->check();
        $this->assertSame(100, $result['radius']);
        $this->assertSame('tidak_tercover', $result['status']);
        $this->get('/coverage')->assertInertia(fn ($page) => $page->where('radius', 100)->where('canEditRadius', false));

        // Only the super admin may change it.
        $this->put('/admin/coverage-radius', ['radius' => 300])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => Role::AdminArea, 'area_id' => $this->area->id]))
            ->put('/admin/coverage-radius', ['radius' => 300])->assertForbidden();
        $helpdesk = User::factory()->create(['role' => Role::Helpdesk, 'area_id' => null]);
        $this->actingAs($helpdesk)->put('/admin/coverage-radius', ['radius' => 300])->assertForbidden();
        $this->get('/coverage')->assertInertia(fn ($page) => $page->where('canEditRadius', false));
        $this->assertSame(100, $this->check()['radius']);
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
