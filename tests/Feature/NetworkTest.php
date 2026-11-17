<?php

namespace Tests\Feature;

use App\Enums\PortStatus;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkTest extends TestCase
{
    use RefreshDatabase;

    private function userIn(Area $area, Role $role = Role::AdminArea): User
    {
        return User::factory()->create(['area_id' => $area->id, 'role' => $role]);
    }

    public function test_creating_an_odp_creates_its_ports()
    {
        $area = Area::factory()->create();

        $this->actingAs($this->userIn($area))
            ->post('/odps', ['code' => 'ODP-01', 'lat' => -6.2, 'lng' => 106.8, 'capacity' => 16, 'status' => 'aktif'])
            ->assertSessionHasNoErrors();

        $odp = Odp::firstWhere('code', 'ODP-01');
        $this->assertSame($area->id, $odp->area_id);
        $this->assertSame(range(1, 16), $odp->ports()->pluck('number')->all());
    }

    public function test_odp_capacity_cannot_shrink_below_a_used_port()
    {
        $area = Area::factory()->create();
        $odp = Odp::factory()->for($area)->create(['capacity' => 16]);
        $odp->ports()->where('number', 12)->update(['status' => PortStatus::Terpakai]);

        $payload = ['code' => $odp->code, 'lat' => $odp->lat, 'lng' => $odp->lng, 'capacity' => 8, 'status' => 'aktif'];

        $this->actingAs($this->userIn($area))
            ->put("/odps/{$odp->id}", $payload)
            ->assertSessionHasErrors('capacity');

        $this->assertSame(16, $odp->fresh()->capacity);
        $this->assertSame(16, $odp->ports()->count());
    }

    public function test_users_only_see_their_own_area()
    {
        [$a, $b] = Area::factory()->count(2)->create();
        $mine = Odp::factory()->for($a)->create(['lat' => -6.2, 'lng' => 106.8]);
        $other = Odp::factory()->for($b)->create(['lat' => -6.2, 'lng' => 106.8]);

        $this->actingAs($this->userIn($a));

        $ids = collect($this->getJson('/map/features?bbox=106,-7,107,-6')->json('odps'))->pluck('id');
        $this->assertEquals([$mine->id], $ids->all());

        $this->getJson("/odps/{$other->id}")->assertNotFound();
    }

    public function test_users_without_an_area_see_every_area_and_pick_one_when_adding()
    {
        [$a, $b] = Area::factory()->count(2)->create();
        Odp::factory()->for($a)->create(['lat' => -6.2, 'lng' => 106.8]);
        Odp::factory()->for($b)->create(['lat' => -6.2, 'lng' => 106.8]);
        $this->actingAs(User::factory()->create(['area_id' => null, 'role' => Role::AdminArea]));

        $this->assertCount(2, $this->getJson('/map/features?bbox=106,-7,107,-6')->json('odps'));

        $payload = ['code' => 'ODP-N', 'lat' => -6.2, 'lng' => 106.8, 'capacity' => 8, 'status' => 'aktif'];
        $this->post('/odps', $payload)->assertSessionHasErrors('area_id');
        $this->post('/odps', [...$payload, 'area_id' => $b->id])->assertSessionHasNoErrors();
        $this->assertSame($b->id, Odp::firstWhere('code', 'ODP-N')->area_id);
    }

    public function test_odp_cannot_connect_to_an_odc_in_another_area()
    {
        [$a, $b] = Area::factory()->count(2)->create();
        $odc = Odc::factory()->for($b)->create();

        $this->actingAs($this->userIn($a, Role::AdminArea))
            ->post('/odps', [
                'code' => 'ODP-X', 'lat' => -6.2, 'lng' => 106.8, 'capacity' => 8, 'status' => 'aktif',
                'odc_id' => $odc->id, 'odc_port' => 1,
            ])
            ->assertSessionHasErrors('odc_id');
    }

    public function test_viewer_cannot_edit_and_teknisi_delete_waits_for_approval()
    {
        $area = Area::factory()->create();
        $odp = Odp::factory()->for($area)->create();

        $this->actingAs($this->userIn($area, Role::Viewer))
            ->post('/odps', ['code' => 'ODP-V', 'lat' => -6.2, 'lng' => 106.8, 'capacity' => 8, 'status' => 'aktif'])
            ->assertForbidden();

        $this->actingAs($this->userIn($area, Role::Teknisi))->delete("/odps/{$odp->id}")->assertSessionHas('notice');
        $this->assertModelExists($odp);
        $this->actingAs($this->userIn($area, Role::AdminArea))->delete("/odps/{$odp->id}")->assertRedirect();
        $this->assertModelMissing($odp);
    }

    public function test_assigning_a_customer_to_a_port()
    {
        $area = Area::factory()->create();
        $port = Odp::factory()->for($area)->create()->ports()->first();

        $this->actingAs($this->userIn($area))
            ->patch("/ports/{$port->id}", ['status' => 'terpakai', 'customer' => ['name' => 'Budi', 'phone' => '0812']])
            ->assertSessionHasNoErrors();

        $port->refresh();
        $this->assertSame(PortStatus::Terpakai, $port->status);
        $this->assertSame('Budi', $port->customer->name);
        $this->assertSame($area->id, $port->customer->area_id);

        $this->patch("/ports/{$port->id}", ['status' => 'kosong'])->assertSessionHasNoErrors();
        $this->assertNull($port->fresh()->customer_id);
    }

    public function test_a_customer_gets_a_location_from_the_port_form()
    {
        $area = Area::factory()->create();
        $port = Odp::factory()->for($area)->create()->ports()->first();
        $this->actingAs($this->userIn($area));

        // Latitude without longitude is refused.
        $this->patch("/ports/{$port->id}", ['status' => 'terpakai', 'customer' => ['name' => 'Budi', 'lat' => -7.0429, 'lng' => null]])
            ->assertSessionHasErrors('customer.lng');

        $this->patch("/ports/{$port->id}", ['status' => 'terpakai', 'customer' => ['name' => 'Budi', 'lat' => -7.0429123, 'lng' => 109.5813456]])
            ->assertSessionHasNoErrors();
        $customer = $port->fresh()->customer;
        $this->assertEqualsWithDelta(-7.0429123, $customer->lat, 1e-7);
        $this->assertEqualsWithDelta(109.5813456, $customer->lng, 1e-7);

        // Emptying both removes the location again.
        $this->patch("/ports/{$port->id}", ['status' => 'terpakai', 'customer' => ['name' => 'Budi', 'lat' => null, 'lng' => null]])
            ->assertSessionHasNoErrors();
        $this->assertNull($customer->fresh()->lat);
    }

    public function test_a_port_can_be_in_use_by_an_unknown_customer()
    {
        $area = Area::factory()->create();
        $port = Odp::factory()->for($area)->create()->ports()->first();
        $this->actingAs($this->userIn($area));

        // Without the flag the name is still required.
        $this->patch("/ports/{$port->id}", ['status' => 'terpakai'])->assertSessionHasErrors('customer.name');

        $this->patch("/ports/{$port->id}", ['status' => 'terpakai', 'customer_unknown' => true, 'customer' => ['name' => '']])
            ->assertSessionHasNoErrors();

        $port->refresh();
        $this->assertSame(PortStatus::Terpakai, $port->status);
        $this->assertNull($port->customer_id);
        $this->assertSame(0, Customer::count());
    }

    public function test_nearest_returns_available_odps_closest_first()
    {
        $area = Area::factory()->create();
        $far = Odp::factory()->for($area)->create(['lat' => -6.2000, 'lng' => 106.8015]); // ~165 m
        $near = Odp::factory()->for($area)->create(['lat' => -6.2000, 'lng' => 106.8005]); // ~55 m
        $full = Odp::factory()->for($area)->create(['lat' => -6.2000, 'lng' => 106.8001, 'capacity' => 2]);
        $full->ports()->update(['status' => PortStatus::Terpakai]);
        Odp::factory()->for($area)->create(['lat' => -6.2000, 'lng' => 106.8100]); // ~1.1 km, outside radius

        $this->actingAs($this->userIn($area));

        $odps = $this->getJson('/map/nearest?lat=-6.2&lng=106.8&radius=250')->assertOk()->json('odps');

        $this->assertSame([$near->id, $far->id], array_column($odps, 'id'));
        $this->assertEqualsWithDelta(55, $odps[0]['distance'], 2);
    }

    public function test_only_super_admin_manages_areas_and_users()
    {
        $area = Area::factory()->create();

        $this->actingAs($this->userIn($area, Role::AdminArea))->get('/admin/users')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]))
            ->post('/admin/users', [
                'name' => 'Teknisi Baru', 'email' => 'baru@example.com', 'role' => 'teknisi',
                'area_id' => $area->id, 'password' => 'rahasia123',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($area->id, User::firstWhere('email', 'baru@example.com')->area_id);
    }
}
