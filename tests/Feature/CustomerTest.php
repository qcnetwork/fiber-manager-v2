<?php

namespace Tests\Feature;

use App\Enums\PortStatus;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Odp;
use App\Models\OdpPort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private Odp $odp;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = Area::factory()->create();
        $this->odp = Odp::factory()->for($this->area)->create(['code' => 'ODP-A', 'lat' => -6.2, 'lng' => 106.8]);
        $this->customer = $this->connect('Budi Santoso', $this->odp->ports()->where('number', 1)->first(), ['customer_code' => 'PLG-001', 'lat' => -6.2005, 'lng' => 106.8005]);
    }

    private function connect(string $name, OdpPort $port, array $attributes = []): Customer
    {
        $customer = Customer::create(['area_id' => $port->odp->area_id, 'name' => $name, ...$attributes]);
        $port->update(['status' => PortStatus::Terpakai, 'customer_id' => $customer->id]);

        return $customer;
    }

    private function user(Role $role = Role::AdminArea, ?Area $area = null): User
    {
        return User::factory()->create(['area_id' => ($area ?? $this->area)->id, 'role' => $role]);
    }

    public function test_customer_markers_are_only_returned_for_the_requested_view()
    {
        $this->connect('Tanpa Lokasi', $this->odp->ports()->where('number', 2)->first());

        $this->actingAs($this->user());

        $customers = $this->getJson('/map/customers?bbox=106.79,-6.21,106.81,-6.19')->assertOk()->json('customers');
        $this->assertSame(['Budi Santoso'], array_column($customers, 'name'));
        $this->assertSame([-6.2, 106.8], $customers[0]['odp_latlng']);

        $this->assertSame([], $this->getJson('/map/customers?bbox=110,-7,111,-6')->json('customers'));
    }

    public function test_search_looks_in_address_and_notes_and_says_where_it_matched()
    {
        $this->odp->update(['address' => 'Jl. Melati No. 5', 'notes' => 'Tiang dekat masjid Al-Ikhlas']);
        $byCode = Odp::factory()->for($this->area)->create(['code' => 'ODP-MELATI']);
        $this->customer->update(['address' => 'Gg. Kenanga RT 02']);

        $this->actingAs($this->user());

        $results = collect($this->getJson('/map/search?q=melati')->json())->where('kind', 'odp')->values();
        // A code match ranks before an address match, and needs no explanation.
        $this->assertSame([$byCode->code, 'ODP-A'], $results->pluck('code')->all());
        $this->assertNull($results[0]['detail']);
        $this->assertSame('Alamat: Jl. Melati No. 5', $results[1]['detail']);

        $notes = collect($this->getJson('/map/search?q=masjid')->json())->firstWhere('kind', 'odp');
        $this->assertSame('Catatan: Tiang dekat masjid Al-Ikhlas', $notes['detail']);

        $customer = collect($this->getJson('/map/search?q=kenanga')->json())->firstWhere('kind', 'customer');
        $this->assertSame('Budi Santoso', $customer['code']);
        $this->assertSame('Alamat: Gg. Kenanga RT 02', $customer['detail']);
    }

    public function test_search_finds_customers_but_not_for_sales()
    {
        $this->actingAs($this->user());
        $found = collect($this->getJson('/map/search?q=PLG-0')->json())->where('kind', 'customer');
        $this->assertSame(['Budi Santoso'], $found->pluck('code')->values()->all());
        $this->assertStringContainsString('ODP-A port 1', $found->first()['name']);

        $sales = User::factory()->create(['role' => Role::Sales, 'area_id' => null]);
        $this->actingAs($sales);
        $this->assertSame([], collect($this->getJson('/map/search?q=Budi')->json())->where('kind', 'customer')->all());
        $this->getJson("/customers/{$this->customer->id}")->assertForbidden();
        $this->getJson('/map/customers?bbox=106,-7,107,-6')->assertForbidden();
    }

    public function test_editing_sets_or_clears_the_location()
    {
        $this->actingAs($this->user())
            ->patch("/customers/{$this->customer->id}", ['name' => 'Budi S.', 'lat' => -6.25, 'lng' => 106.85])
            ->assertSessionHasNoErrors();
        $this->assertSame([-6.25, 106.85], [$this->customer->fresh()->lat, $this->customer->fresh()->lng]);

        $this->patch("/customers/{$this->customer->id}", ['name' => 'Budi S.', 'lat' => -6.25])->assertSessionHasErrors('lng');

        $this->patch("/customers/{$this->customer->id}", ['name' => 'Budi S.', 'lat' => null, 'lng' => null])->assertSessionHasNoErrors();
        $this->assertNull($this->customer->fresh()->lat);

        $this->actingAs($this->user(Role::Viewer))
            ->patch("/customers/{$this->customer->id}", ['name' => 'X'])
            ->assertForbidden();
    }

    public function test_moving_to_another_odp_frees_the_old_port()
    {
        $otherArea = Area::factory()->create();
        $target = Odp::factory()->for($otherArea)->create();
        $port = $target->ports()->where('number', 3)->first();
        $port->update(['status' => PortStatus::Booking]);

        $admin = User::factory()->create(['role' => Role::SuperAdmin, 'area_id' => null]);
        $this->actingAs($admin)
            ->post("/customers/{$this->customer->id}/move", ['port_id' => $port->id])
            ->assertSessionHasNoErrors();

        $old = $this->odp->ports()->where('number', 1)->first();
        $this->assertNull($old->customer_id);
        $this->assertSame(PortStatus::Kosong, $old->status);
        $this->assertSame($this->customer->id, $port->fresh()->customer_id);
        $this->assertSame(PortStatus::Terpakai, $port->fresh()->status);
        $this->assertSame($otherArea->id, $this->customer->fresh()->area_id);
    }

    public function test_moving_to_a_used_port_or_another_area_is_refused()
    {
        $taken = $this->odp->ports()->where('number', 2)->first();
        $this->connect('Siti', $taken);
        // Created before signing in: the area scope would otherwise put it in the user's own area.
        $elsewhere = Odp::factory()->for(Area::factory()->create())->create()->ports()->first();

        $this->actingAs($this->user())
            ->post("/customers/{$this->customer->id}/move", ['port_id' => $taken->id])
            ->assertSessionHasErrors('port_id');
        $this->assertSame($this->customer->id, $this->odp->ports()->where('number', 1)->first()->customer_id);

        $this->post("/customers/{$this->customer->id}/move", ['port_id' => $elsewhere->id])->assertNotFound();

        $this->getJson("/odps/{$this->odp->id}/free-ports")->assertOk()->assertJsonCount(6, 'ports'); // 8 ports, 2 used
    }

    public function test_photos_can_be_added_to_a_customer()
    {
        Storage::fake('photos');

        $this->actingAs($this->user())
            ->post('/photos', [
                'photoable_type' => 'customer',
                'photoable_id' => $this->customer->id,
                'photo' => UploadedFile::fake()->image('rumah.jpg', 800, 600),
            ])
            ->assertSessionHasNoErrors();

        $photos = $this->getJson("/customers/{$this->customer->id}")->assertOk()->json('customer.photos');
        $this->assertCount(1, $photos);
    }
}
