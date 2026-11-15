<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_see_the_help_page()
    {
        $this->actingAs(User::factory()->create(['role' => Role::Teknisi]))
            ->get('/bantuan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Help'));
    }

    public function test_guests_and_sales_do_not()
    {
        $this->get('/bantuan')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => Role::Sales]))->get('/bantuan')->assertRedirect();
    }
}
