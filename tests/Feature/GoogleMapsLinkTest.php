<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleMapsLinkTest extends TestCase
{
    use RefreshDatabase;

    private function resolve(string $url)
    {
        return $this->actingAs(User::factory()->create(['role' => Role::Sales, 'area_id' => null]))
            ->getJson(route('map.resolve-link', ['url' => $url]));
    }

    public function test_long_links_are_read_without_a_request()
    {
        Http::preventStrayRequests();

        $this->resolve('https://www.google.com/maps/place/Toko+Budi/@-7.0400,109.5800,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d-7.0429!4d109.5813')
            ->assertOk()
            ->assertExactJson(['lat' => -7.0429, 'lng' => 109.5813, 'precision' => 'pin']);

        $this->resolve('https://www.google.com/maps/@-7.04,109.58,15z')->assertJson(['precision' => 'center']);
        $this->resolve('https://maps.google.com/?q=-7.05,109.59')->assertJson(['lat' => -7.05, 'precision' => 'pin']);
    }

    public function test_short_links_follow_redirects()
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/place/x/data=!3d-6.9!4d110.4']),
        ]);

        $this->resolve('https://maps.app.goo.gl/AbCdEf123')
            ->assertOk()
            ->assertJson(['lat' => -6.9, 'lng' => 110.4, 'precision' => 'pin']);
    }

    public function test_redirects_outside_google_are_not_followed()
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin?q=-6.9,110.4']),
        ]);

        $this->resolve('https://maps.app.goo.gl/AbCdEf123')->assertStatus(422);
        Http::assertSentCount(1);
    }

    public function test_other_hosts_and_links_without_coordinates_are_rejected()
    {
        Http::fake(['www.google.com/*' => Http::response('<html>tidak ada</html>')]);

        $this->resolve('https://evil.example/maps/@-7,109,15z')->assertStatus(422)->assertJson(['message' => 'Hanya link Google Maps yang bisa dibaca.']);
        $this->resolve('https://www.google.com/maps/place/Toko+Budi')->assertStatus(422)->assertJsonStructure(['message']);
    }
}
