<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Cable;
use App\Models\CoreSplice;
use App\Models\Odp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Regression tests for the security review fixes.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private Area $home;

    private Area $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = Area::factory()->create(['center_lat' => -6.2, 'center_lng' => 106.8]);
        $this->other = Area::factory()->create(['center_lat' => -6.3, 'center_lng' => 106.9]);
    }

    private function teknisi(): User
    {
        return User::factory()->create(['role' => Role::Teknisi, 'area_id' => $this->home->id]);
    }

    public function test_pops_of_other_areas_are_out_of_reach_for_splices()
    {
        $cable = Cable::create([
            'area_id' => $this->other->id, 'type' => 'feeder', 'cores' => 12, 'path' => [[-6.3, 106.9], [-6.31, 106.9]],
            'from_type' => 'pop', 'from_id' => $this->other->id,
        ]);
        [$a, $b] = $cable->coreRows()->orderBy('number')->take(2)->get()->all();
        $splice = CoreSplice::create(['location_type' => 'pop', 'location_id' => $this->other->id, 'core_a_id' => $a->id, 'core_b_id' => $b->id]);

        $this->actingAs($this->teknisi());

        $this->getJson("/splices?location_type=pop&location_id={$this->other->id}")->assertNotFound();
        $this->get("/splice-sheet/pop/{$this->other->id}")->assertNotFound();
        $this->delete("/splices/{$splice->id}")->assertNotFound();
        $this->assertModelExists($splice);

        // Their own POP still works.
        $this->getJson("/splices?location_type=pop&location_id={$this->home->id}")->assertOk();
    }

    public function test_a_cable_cannot_end_at_a_pop_of_another_area()
    {
        $this->actingAs($this->teknisi());

        $this->post('/cables', [
            'type' => 'feeder', 'cores' => 12, 'status' => 'aktif',
            'path' => [[-6.3, 106.9], [-6.2, 106.8]],
            'from_type' => 'pop', 'from_id' => $this->other->id,
        ])->assertSessionHasErrors('from_id');
    }

    public function test_invisible_characters_do_not_reset_the_login_limit()
    {
        User::factory()->create(['username' => 'admin', 'password' => 'rahasia-benar']);

        foreach (['admin', "ad\u{200B}min", "a\u{200B}d\u{200B}min", "admin\u{2060}", "\u{FEFF}admin"] as $login) {
            $this->post('/login', ['login' => $login, 'password' => 'salah'])->assertSessionHasErrors('login');
        }

        // Locked out, whatever invisible characters are added, even with the right password.
        $this->post('/login', ['login' => "adm\u{200B}\u{200B}in", 'password' => 'rahasia-benar']);
        $this->assertGuest();
        $this->assertMatchesRegularExpression('/\d+ (seconds|detik)/', session('errors')->first('login'));
    }

    public function test_login_tolerates_invisible_characters_in_the_name()
    {
        User::factory()->create(['username' => 'admin', 'password' => 'rahasia-benar']);

        $this->post('/login', ['login' => "ad\u{200B}min ", 'password' => 'rahasia-benar']);

        $this->assertAuthenticated();
    }

    public function test_many_accounts_from_one_ip_are_limited()
    {
        foreach (range(1, 20) as $i) {
            $this->post('/login', ['login' => "user{$i}", 'password' => 'salah']);
        }

        User::factory()->create(['username' => 'lain', 'password' => 'rahasia-benar']);
        $this->post('/login', ['login' => 'lain', 'password' => 'rahasia-benar']);
        $this->assertGuest();
    }

    public function test_the_last_super_admin_cannot_delete_or_demote_themselves()
    {
        $admin = User::factory()->create(['role' => Role::SuperAdmin, 'area_id' => null, 'password' => 'password']);
        $this->actingAs($admin);

        $this->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertModelExists($admin);

        $this->put("/admin/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'role' => 'teknisi'])
            ->assertSessionHasErrors('role');
        $this->assertSame(Role::SuperAdmin, $admin->fresh()->role);

        // With a second super admin it is allowed.
        User::factory()->create(['role' => Role::SuperAdmin, 'area_id' => null]);
        $this->delete('/settings/profile', ['password' => 'password'])->assertSessionHasNoErrors();
        $this->assertModelMissing($admin);
    }

    public function test_photos_with_huge_dimensions_are_refused_before_decoding()
    {
        $odp = Odp::factory()->for($this->home)->create();
        // A PNG header claiming 20000 x 20000 pixels (400 MP); the rest of the file doesn't matter.
        $ihdr = 'IHDR'.pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
        $file = UploadedFile::fake()->createWithContent('besar.png', $png);

        $this->actingAs($this->teknisi())
            ->post('/photos', ['photoable_type' => 'odp', 'photoable_id' => $odp->id, 'photo' => $file])
            ->assertSessionHasErrors('photo');
        $this->assertSame(0, $odp->photos()->count());
    }

    public function test_sales_search_does_not_look_into_notes_or_addresses()
    {
        Odp::factory()->for($this->home)->create(['code' => 'ODP-X', 'notes' => 'Pelanggan Budi 0812-3456', 'address' => 'Rumah Budi']);
        $this->actingAs(User::factory()->create(['role' => Role::Sales, 'area_id' => null]));

        $this->assertSame([], $this->getJson('/map/search?q=Budi')->assertOk()->json());
        $this->assertSame(['ODP-X'], array_column($this->getJson('/map/search?q=ODP-X')->json(), 'code'));
    }

    public function test_responses_carry_security_headers()
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
