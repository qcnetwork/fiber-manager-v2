<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Cable;
use App\Models\ChangeRequest;
use App\Models\Odc;
use App\Models\Olt;
use App\Models\OltPort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PopOltTest extends TestCase
{
    use RefreshDatabase;

    private Area $pop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pop = Area::factory()->create(['center_lat' => -7.0, 'center_lng' => 109.5]);
    }

    private function user(Role $role = Role::AdminArea, ?Area $area = null): User
    {
        return User::factory()->create(['area_id' => ($area ?? $this->pop)->id, 'role' => $role]);
    }

    private function olt(array $cards = [1 => 8]): Olt
    {
        $olt = Olt::create(['area_id' => $this->pop->id, 'code' => 'OLT-'.uniqid(), 'name' => 'OLT']);
        $olt->syncPorts($cards);

        return $olt;
    }

    private function port(Olt $olt, int $slot, int $port): OltPort
    {
        return $olt->ports()->where(['slot' => $slot, 'port' => $port])->sole();
    }

    public function test_admin_adds_an_olt_with_cards()
    {
        $this->actingAs($this->user())
            ->post('/olts', [
                'code' => 'ZTE-C320 KJN-01', 'brand' => 'ZTE C320', 'ip_address' => '10.0.0.2',
                'cards' => [['slot' => 1, 'ports' => 8], ['slot' => 2, 'ports' => 16]],
            ])
            ->assertSessionHasNoErrors();

        $olt = Olt::sole();
        $this->assertSame([$this->pop->id, 'ZTE-C320 KJN-01', 24], [$olt->area_id, $olt->name, $olt->pon_ports]);
        $this->assertSame([1 => 8, 2 => 16], $olt->cards());

        $this->post('/olts', ['code' => 'X', 'cards' => [['slot' => 1, 'ports' => 8], ['slot' => 1, 'ports' => 4]]])
            ->assertSessionHasErrors('cards.1.slot');
    }

    public function test_technicians_connect_odcs_but_do_not_manage_olts()
    {
        $olt = $this->olt();
        $port = $this->port($olt, 1, 3);
        [$a, $b] = Odc::factory()->for($this->pop)->count(2)->create();

        $this->actingAs($this->user(Role::Teknisi));
        $this->post('/olts', ['code' => 'X', 'cards' => [['slot' => 1, 'ports' => 8]]])->assertForbidden();
        $this->patch("/olt-ports/{$port->id}", ['status' => 'rusak'])->assertForbidden();

        // Connecting waits for an admin's approval.
        $this->post("/olt-ports/{$port->id}/odcs", ['odc_id' => $a->id])->assertSessionHas('notice');
        $this->assertNull($a->fresh()->olt_port_id);
        $this->assertSame(1, ChangeRequest::where('status', ChangeRequest::DRAFT)->count());

        // One PON port may feed several ODCs.
        $this->actingAs($this->user());
        $this->post("/olt-ports/{$port->id}/odcs", ['odc_id' => $a->id])->assertSessionHasNoErrors();
        $this->post("/olt-ports/{$port->id}/odcs", ['odc_id' => $b->id])->assertSessionHasNoErrors();
        $this->assertSame([$a->id, $b->id], $port->odcs()->orderBy('id')->pluck('id')->all());

        $this->delete("/olt-ports/{$port->id}/odcs/{$a->id}")->assertSessionHasNoErrors();
        $this->assertNull($a->fresh()->olt_port_id);
    }

    public function test_removing_a_card_that_feeds_an_odc_is_refused()
    {
        $olt = $this->olt([1 => 8, 2 => 8]);
        Odc::factory()->for($this->pop)->create(['olt_port_id' => $this->port($olt, 2, 5)->id]);

        $this->actingAs($this->user())
            ->put("/olts/{$olt->id}", ['code' => $olt->code, 'cards' => [['slot' => 1, 'ports' => 8]]])
            ->assertSessionHasErrors('cards');
        $this->assertSame([1 => 8, 2 => 8], $olt->fresh()->cards());

        // Growing a card and shrinking an unused one is fine.
        $this->put("/olts/{$olt->id}", ['code' => $olt->code, 'cards' => [['slot' => 1, 'ports' => 4], ['slot' => 2, 'ports' => 16]]])
            ->assertSessionHasNoErrors();
        $this->assertSame([1 => 4, 2 => 16], $olt->fresh()->cards());
    }

    public function test_odc_uplink_must_be_at_its_own_pop()
    {
        $here = $this->port($this->olt(), 1, 1);
        $other = Area::factory()->create();
        $elsewhere = Olt::create(['area_id' => $other->id, 'code' => 'OLT-LAIN', 'name' => 'x']);
        $elsewhere->syncPorts([1 => 4]);
        $odc = Odc::factory()->for($this->pop)->create();

        $payload = fn (int $portId) => ['code' => $odc->code, 'lat' => $odc->lat, 'lng' => $odc->lng, 'capacity' => 48, 'status' => 'aktif', 'olt_port_id' => $portId];

        $this->actingAs($this->user());
        $this->put("/odcs/{$odc->id}", $payload($elsewhere->ports()->first()->id))->assertSessionHasErrors('olt_port_id');
        $this->put("/odcs/{$odc->id}", $payload($here->id))->assertSessionHasNoErrors();

        $detail = $this->getJson("/odcs/{$odc->id}")->json('odc.olt_port');
        $this->assertSame([1, 1], [$detail['slot'], $detail['port']]);
    }

    public function test_pop_detail_and_map_features_respect_the_area()
    {
        $olt = $this->olt([1 => 4]);
        $odc = Odc::factory()->for($this->pop)->create(['lat' => -7.01, 'lng' => 109.5, 'olt_port_id' => $this->port($olt, 1, 2)->id]);
        $other = Area::factory()->create(['center_lat' => -7.0, 'center_lng' => 109.51]);

        $this->actingAs($this->user(Role::Teknisi));

        $features = $this->getJson('/map/features?bbox=109,-8,110,-6')->assertOk();
        $this->assertSame([$this->pop->id], array_column($features->json('pops'), 'id'));
        $this->assertSame([4, 1], [$features->json('pops.0.ports_total'), $features->json('pops.0.ports_used')]);
        $this->assertEquals([-7.0, 109.5], collect($features->json('odcs'))->firstWhere('id', $odc->id)['pop_latlng']);

        $pop = $this->getJson("/pops/{$this->pop->id}")->assertOk()->json('pop');
        $this->assertSame($odc->code, $pop['olts'][0]['ports'][1]['odcs'][0]['code']);
        $this->getJson("/pops/{$other->id}")->assertNotFound();

        $this->assertSame('pop', $this->getJson('/map/search?q='.urlencode($olt->code))->json('0.kind'));
    }

    public function test_moving_a_pop_drags_its_feeder_cables()
    {
        $odc = Odc::factory()->for($this->pop)->create(['lat' => -7.01, 'lng' => 109.5]);
        $cable = Cable::withoutGlobalScopes()->create([
            'area_id' => $this->pop->id, 'type' => 'feeder', 'cores' => 24, 'status' => 'aktif',
            'from_type' => 'pop', 'from_id' => $this->pop->id, 'to_type' => 'odc', 'to_id' => $odc->id,
            'path' => [[-7.0, 109.5], [-7.01, 109.5]],
        ]);

        $this->actingAs($this->user())
            ->patch("/pops/{$this->pop->id}", ['lat' => -7.002, 'lng' => 109.501, 'address' => 'Jl. Raya'])
            ->assertSessionHasNoErrors();

        $this->assertSame([-7.002, 109.501], $cable->fresh()->path[0]);
        $this->assertSame('Jl. Raya', $this->pop->fresh()->address);

        $this->actingAs($this->user(Role::Teknisi))
            ->patch("/pops/{$this->pop->id}", ['lat' => -7.0, 'lng' => 109.5])
            ->assertForbidden();
    }
}
