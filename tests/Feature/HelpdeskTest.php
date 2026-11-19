<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HelpdeskTest extends TestCase
{
    use RefreshDatabase;

    private function helpdesk(): User
    {
        return User::factory()->create(['role' => Role::Helpdesk, 'area_id' => null]);
    }

    public function test_helpdesk_has_super_admin_powers_except_user_management()
    {
        $user = $this->helpdesk();

        $this->assertTrue($user->isAdministrator());
        $this->assertFalse($user->isSuperAdmin());
        $this->assertTrue($user->canEditNetwork());
        $this->assertTrue($user->canApprove());
        $this->assertTrue($user->canDeleteNetwork());
        $this->assertFalse($user->needsApproval());
        $this->assertTrue($user->seesAllAreas());
    }

    public function test_helpdesk_manages_areas_but_not_users()
    {
        $this->actingAs($this->helpdesk());

        $this->get('/admin/areas')->assertOk();
        $this->get('/admin/users')->assertForbidden();
        $this->post('/admin/users', [
            'name' => 'Baru', 'username' => 'baru', 'role' => 'super_admin', 'password' => 'Password123!',
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['username' => 'baru']);

        $this->get('/map')->assertInertia(fn (Assert $page) => $page
            ->where('auth.isAdministrator', true)
            ->where('auth.isSuperAdmin', false)
            ->where('auth.canApprove', true));
    }

    public function test_super_admin_can_create_a_helpdesk_account_without_area()
    {
        $area = Area::factory()->create();
        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin, 'area_id' => null]));

        $this->post('/admin/users', [
            'name' => 'Helpdesk', 'username' => 'helpdesk', 'role' => 'helpdesk', 'area_id' => $area->id, 'password' => 'Password123!',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['username' => 'helpdesk', 'role' => 'helpdesk', 'area_id' => null]);
    }
}
