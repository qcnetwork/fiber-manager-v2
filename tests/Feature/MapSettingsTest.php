<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\User;
use App\Support\MapLayers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MapSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role = Role::Teknisi): User
    {
        return User::factory()->create(['role' => $role, 'area_id' => Area::factory()->create()->id]);
    }

    private function payload(array $overrides = []): array
    {
        return [...MapLayers::DEFAULTS, ...$overrides];
    }

    public function test_new_users_get_the_built_in_defaults()
    {
        $this->actingAs($this->user())
            ->get('/settings/map')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/MapDefaults')
                ->where('layers', MapLayers::DEFAULTS)
                ->where('defaults', MapLayers::DEFAULTS));
    }

    public function test_saved_defaults_reach_the_map_page()
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->put('/settings/map', $this->payload(['base' => 'satelit', 'cables' => false, 'customers' => true, 'cluster' => false]))
            ->assertSessionHasNoErrors();

        $expected = [...MapLayers::DEFAULTS, 'base' => 'satelit', 'cables' => false, 'customers' => true, 'cluster' => false];
        $this->assertSame($expected, $user->fresh()->mapLayers());

        $this->get('/map')->assertInertia(fn (Assert $page) => $page->where('mapLayers', $expected));
    }

    public function test_other_preferences_and_other_users_are_left_alone()
    {
        $user = $this->user();
        $user->forceFill(['preferences' => ['other' => 'kept']])->save();
        $other = $this->user();

        $this->actingAs($user)->put('/settings/map', $this->payload(['odps' => false]));

        $this->assertSame('kept', $user->fresh()->preferences['other']);
        $this->assertFalse($user->fresh()->mapLayers()['odps']);
        $this->assertSame(MapLayers::DEFAULTS, $other->fresh()->mapLayers());
    }

    public function test_invalid_values_are_rejected()
    {
        $this->actingAs($this->user())
            ->put('/settings/map', $this->payload(['base' => 'google', 'odcs' => 'ya']))
            ->assertSessionHasErrors(['base', 'odcs']);
    }

    public function test_unknown_or_broken_saved_values_fall_back_to_defaults()
    {
        $this->assertSame(MapLayers::DEFAULTS, MapLayers::resolve(['base' => 'x', 'odcs' => 'yes', 'foo' => true]));
    }

    public function test_sales_cannot_use_map_settings()
    {
        $this->actingAs($this->user(Role::Sales));

        $this->get('/settings/map')->assertRedirect(route('coverage'));
        $this->put('/settings/map', $this->payload())->assertForbidden();
    }
}
