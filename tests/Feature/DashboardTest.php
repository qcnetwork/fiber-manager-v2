<?php

namespace Tests\Feature;

use App\Enums\PortStatus;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Odp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertStatus(200);
    }

    public function test_dashboard_shows_port_states_fill_groups_growth_and_missing_data()
    {
        $area = Area::factory()->create();
        $full = Odp::factory()->for($area)->create(['capacity' => 2]);
        Odp::factory()->for($area)->create(['capacity' => 8]); // empty, no ODC, no photo

        $connected = Customer::create(['area_id' => $area->id, 'name' => 'Budi', 'lat' => -6.2, 'lng' => 106.8]);
        $full->ports()->where('number', 1)->update(['status' => PortStatus::Terpakai, 'customer_id' => $connected->id]);
        $full->ports()->where('number', 2)->update(['status' => PortStatus::Rusak]);
        Customer::create(['area_id' => $area->id, 'name' => 'Tanpa Port']);

        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin, 'area_id' => null]));

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('portStatus', ['kosong' => 8, 'terpakai' => 1, 'booking' => 0, 'rusak' => 1])
            ->where('odpFill', ['empty' => 1, 'low' => 0, 'high' => 0, 'full' => 1])
            ->has('growth', 12)
            ->where('growth.11.count', 2)
            ->where('stats.customers_this_month', 2)
            ->where('gaps.customers_without_location', 1)
            ->where('gaps.customers_without_port', 1)
            ->where('gaps.odps_without_photo', 2)
            ->where('areas.0.customers_count', 2)
            ->has('activity')
        );
    }

    public function test_technicians_do_not_get_the_activity_feed()
    {
        $this->actingAs(User::factory()->create(['role' => Role::Teknisi]));

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('activity', null));
    }
}
