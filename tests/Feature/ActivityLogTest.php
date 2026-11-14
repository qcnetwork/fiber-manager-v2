<?php

namespace Tests\Feature;

use App\Enums\PortStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Odp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function userIn(Area $area, Role $role = Role::AdminArea): User
    {
        return User::factory()->create(['area_id' => $area->id, 'role' => $role]);
    }

    public function test_nothing_is_logged_without_a_signed_in_user()
    {
        Odp::factory()->for(Area::factory())->create();

        $this->assertSame(0, ActivityLog::count());
    }

    public function test_creating_an_odp_logs_one_entry_not_one_per_port()
    {
        $area = Area::factory()->create();
        $user = $this->userIn($area);

        $this->actingAs($user)
            ->post('/odps', ['code' => 'ODP-01', 'lat' => -6.2, 'lng' => 106.8, 'capacity' => 16, 'status' => 'aktif'])
            ->assertSessionHasNoErrors();

        $log = ActivityLog::sole();
        $this->assertSame(['created', 'odp', 'ODP-01', $area->id, $user->name], [$log->action, $log->subject_type, $log->subject_label, $log->area_id, $log->user_name]);
    }

    public function test_an_update_records_old_and_new_values()
    {
        $area = Area::factory()->create();
        $odp = Odp::factory()->for($area)->create(['code' => 'ODP-01', 'capacity' => 8, 'address' => 'Jl. Lama']);

        $this->actingAs($this->userIn($area))
            ->put("/odps/{$odp->id}", ['code' => 'ODP-01', 'lat' => $odp->lat, 'lng' => $odp->lng, 'capacity' => 16, 'status' => 'aktif', 'address' => 'Jl. Baru'])
            ->assertSessionHasNoErrors();

        $log = ActivityLog::where('action', 'updated')->sole();
        $this->assertSame([8, 16], $log->changes['capacity']);
        $this->assertSame(['Jl. Lama', 'Jl. Baru'], $log->changes['address']);
        $this->assertArrayNotHasKey('updated_at', $log->changes);
    }

    public function test_deleting_keeps_what_the_record_held()
    {
        $area = Area::factory()->create(['code' => 'POP-A']);
        $odp = Odp::factory()->for($area)->create(['code' => 'ODP-HAPUS']);

        $this->actingAs($this->userIn($area, Role::AdminArea))->delete("/odps/{$odp->id}")->assertRedirect();

        $log = ActivityLog::where('action', 'deleted')->sole();
        $this->assertSame('ODP-HAPUS', $log->subject_label);
        $this->assertSame(['POP-A', null], $log->changes['area_id']);
    }

    public function test_moving_a_customer_is_one_entry_with_both_ports()
    {
        $area = Area::factory()->create();
        $from = Odp::factory()->for($area)->create(['code' => 'ODP-A']);
        $to = Odp::factory()->for($area)->create(['code' => 'ODP-B']);
        $customer = Customer::create(['area_id' => $area->id, 'name' => 'Budi']);
        $from->ports()->where('number', 1)->update(['status' => PortStatus::Terpakai, 'customer_id' => $customer->id]);

        $this->actingAs($this->userIn($area))
            ->post("/customers/{$customer->id}/move", ['port_id' => $to->ports()->where('number', 3)->value('id')])
            ->assertSessionHasNoErrors();

        $log = ActivityLog::sole();
        $this->assertSame(['moved', 'customer', 'Budi'], [$log->action, $log->subject_type, $log->subject_label]);
        $this->assertSame(['ODP-A port 1', 'ODP-B port 3'], $log->changes['port']);
    }

    public function test_a_port_change_shows_the_customer_name()
    {
        $area = Area::factory()->create();
        $odp = Odp::factory()->for($area)->create(['code' => 'ODP-A']);
        $port = $odp->ports()->where('number', 2)->first();

        $this->actingAs($this->userIn($area))
            ->patch("/ports/{$port->id}", ['status' => 'terpakai', 'customer' => ['name' => 'Siti']])
            ->assertSessionHasNoErrors();

        $log = ActivityLog::where('subject_type', 'odp_port')->sole();
        $this->assertSame('ODP-A port 2', $log->subject_label);
        $this->assertSame([null, 'Siti'], $log->changes['customer_id']);
    }

    public function test_passwords_are_logged_as_changed_without_their_value()
    {
        $admin = User::factory()->create(['role' => Role::SuperAdmin]);
        $user = User::factory()->create(['role' => Role::Teknisi, 'username' => 'teknisi1']);

        $this->actingAs($admin)
            ->put("/admin/users/{$user->id}", ['name' => $user->name, 'username' => 'teknisi1', 'role' => 'teknisi', 'password' => 'rahasia-baru-123'])
            ->assertSessionHasNoErrors();

        $log = ActivityLog::where('subject_type', 'user')->sole();
        $this->assertSame([null, null], $log->changes['password']);
        $this->assertStringNotContainsString('rahasia', json_encode($log->changes));
    }

    public function test_area_admins_only_see_their_area_and_technicians_cannot_open_the_page()
    {
        [$a, $b] = Area::factory()->count(2)->create();
        $this->actingAs($this->userIn($a))->post('/odps', ['code' => 'ODP-A1', 'lat' => -6.2, 'lng' => 106.8, 'capacity' => 8, 'status' => 'aktif']);
        $this->actingAs($this->userIn($b))->post('/odps', ['code' => 'ODP-B1', 'lat' => -6.2, 'lng' => 106.8, 'capacity' => 8, 'status' => 'aktif']);

        $this->actingAs($this->userIn($a, Role::Teknisi))->get('/activity')->assertForbidden();

        $this->actingAs($this->userIn($a, Role::AdminArea))
            ->get('/activity')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('ActivityLogs')
                ->has('logs.data', 1)
                ->where('logs.data.0.subject_label', 'ODP-A1'));

        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]))
            ->get('/activity?q=B1')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('logs.data', 1)->where('logs.data.0.subject_label', 'ODP-B1'));
    }
}
