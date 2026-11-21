<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Cable;
use App\Models\Customer;
use App\Models\Odp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DropCableTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private Odp $odp;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = Area::factory()->create();
        $this->actingAs(User::factory()->create(['area_id' => $this->area->id, 'role' => Role::AdminArea]));
        $this->odp = Odp::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.8]);
        $this->customer = Customer::create([
            'area_id' => $this->area->id, 'name' => 'Budi', 'customer_code' => 'PLG-01', 'lat' => -6.2005, 'lng' => 106.8005, 'status' => 'aktif',
        ]);
    }

    private function dropCable(array $overrides = []): array
    {
        return [
            'type' => 'drop', 'cores' => 1, 'status' => 'aktif',
            'from_type' => 'odp', 'from_id' => $this->odp->id, 'to_type' => 'customer', 'to_id' => $this->customer->id,
            'path' => [[-6.20001, 106.80001], [-6.2003, 106.8002], [-6.20049, 106.80049]],
            ...$overrides,
        ];
    }

    public function test_a_drop_cable_can_end_at_a_customer()
    {
        $this->post('/cables', $this->dropCable())->assertSessionHasNoErrors();

        $cable = Cable::sole();
        $this->assertSame('customer', $cable->to_type);
        $this->assertSame([-6.2005, 106.8005], $cable->path[2]);
        $this->assertSame('Budi (PLG-01)', last($cable->route())['asset']['code']);
        $this->assertSame('customer', last($cable->route())['kind']);
    }

    public function test_only_drop_cables_reach_a_customer()
    {
        $this->post('/cables', $this->dropCable(['type' => 'distribusi', 'cores' => 12]))
            ->assertSessionHasErrors(['to_type' => 'Hanya kabel drop yang bisa disambung ke pelanggan.']);

        $this->assertSame(0, Cable::count());
    }

    public function test_a_customer_is_never_a_pass_through_point()
    {
        $this->post('/cables', $this->dropCable([
            'to_type' => null, 'to_id' => null,
            'stops' => [['vertex' => 1, 'type' => 'customer', 'id' => $this->customer->id]],
        ]))->assertSessionHasErrors('stops.0.type');
    }

    public function test_the_cable_end_follows_the_customer_and_lets_go_when_it_is_deleted()
    {
        $this->post('/cables', $this->dropCable());
        $cable = Cable::sole();

        $this->customer->update(['lat' => -6.201, 'lng' => 106.801]);
        $this->assertSame([-6.201, 106.801], $cable->fresh()->path[2]);

        $this->customer->delete();
        $cable->refresh();
        $this->assertNull($cable->to_type);
        $this->assertNull($cable->to_id);
        $this->assertSame('odp', $cable->from_type);
    }

    public function test_the_customer_detail_lists_its_drop_cables()
    {
        $this->post('/cables', $this->dropCable(['code' => 'DROP-01']));

        $this->getJson("/customers/{$this->customer->id}")
            ->assertOk()
            ->assertJsonPath('customer.cables.0.code', 'DROP-01')
            ->assertJsonPath('customer.name', 'Budi');
    }

    public function test_exports_handle_cables_ending_at_a_customer()
    {
        $this->post('/cables', $this->dropCable(['code' => 'DROP-01']));

        $this->get('/data/export/xlsx')->assertOk()->streamedContent();
        $kml = $this->get('/data/export/kml')->assertOk()->streamedContent();
        $this->assertStringContainsString('Budi (PLG-01)', $kml);
    }
}
