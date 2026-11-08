<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\Cable;
use App\Models\CableCore;
use App\Models\CoreSplice;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\SpliceBox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoreSpliceTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private Odc $odc;

    private SpliceBox $joint;

    private Odp $odp;

    /** ODC → JB-01 (feeder, 24 core) */
    private Cable $feeder;

    /** JB-01 → ODP (distribution, 12 core) */
    private Cable $distribution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = Area::factory()->create();
        $this->actingAs(User::factory()->create(['area_id' => $this->area->id, 'role' => Role::AdminArea]));

        $this->odc = Odc::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.8]);
        $this->joint = SpliceBox::factory()->for($this->area)->create(['code' => 'JB-01', 'lat' => -6.2, 'lng' => 106.801]);
        $this->odp = Odp::factory()->for($this->area)->create(['lat' => -6.2, 'lng' => 106.802]);

        $this->feeder = Cable::create([
            'code' => 'FDR-01', 'type' => 'feeder', 'cores' => 24, 'tube_size' => 12,
            'from_type' => 'odc', 'from_id' => $this->odc->id, 'to_type' => 'joint', 'to_id' => $this->joint->id,
            'path' => [[-6.2, 106.8], [-6.2, 106.801]],
        ]);
        $this->distribution = Cable::create([
            'code' => 'DST-01', 'type' => 'distribusi', 'cores' => 12,
            'from_type' => 'joint', 'from_id' => $this->joint->id, 'to_type' => 'odp', 'to_id' => $this->odp->id,
            'path' => [[-6.2, 106.801], [-6.2, 106.802]],
        ]);
    }

    private function core(Cable $cable, int $number): CableCore
    {
        return $cable->coreRows()->where('number', $number)->sole();
    }

    private function splice(string $type, int $id, array $pairs): \Illuminate\Testing\TestResponse
    {
        return $this->post('/splices', [
            'location_type' => $type, 'location_id' => $id,
            'pairs' => array_map(fn ($p) => ['a' => $p[0]->id, 'b' => $p[1]->id], $pairs),
        ]);
    }

    private function terminate(string $type, int $id, CableCore $core, string $kind, int $port): \Illuminate\Testing\TestResponse
    {
        return $this->post('/terminations', ['location_type' => $type, 'location_id' => $id, 'core_id' => $core->id, 'kind' => $kind, 'port' => $port]);
    }

    public function test_cores_of_cables_meeting_at_a_joint_can_be_spliced()
    {
        $this->splice('joint', $this->joint->id, [
            [$this->core($this->feeder, 1), $this->core($this->distribution, 1)],
            [$this->core($this->feeder, 2), $this->core($this->distribution, 2)],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, CoreSplice::count());
        $this->assertSame('terpakai', $this->core($this->feeder, 1)->status->value); // spliced cores are in use
        $this->assertSame(1, ActivityLog::where('subject_type', 'splice')->count());

        $data = $this->getJson("/splices?location_type=joint&location_id={$this->joint->id}")->assertOk()->json();
        $this->assertSame(['DST-01', 'FDR-01'], array_column($data['cables'], 'code'));
        $this->assertCount(2, $data['splices']);
        $feederCores = collect($data['cables'])->firstWhere('code', 'FDR-01')['cores_list'];
        $this->assertTrue($feederCores[0]['spliced_here']);
        $this->assertFalse($feederCores[2]['spliced_here']);
    }

    public function test_only_cables_reaching_the_location_can_be_spliced_there()
    {
        // The feeder doesn't reach the ODP.
        $this->splice('odp', $this->odp->id, [[$this->core($this->feeder, 1), $this->core($this->distribution, 1)]])
            ->assertSessionHasErrors('pairs');

        $this->assertSame(0, CoreSplice::count());
    }

    public function test_a_core_is_spliced_once_per_location_and_at_most_twice()
    {
        $f1 = $this->core($this->feeder, 1);
        $this->splice('joint', $this->joint->id, [[$f1, $this->core($this->distribution, 1)]])->assertSessionHasNoErrors();

        $this->splice('joint', $this->joint->id, [[$f1, $this->core($this->distribution, 2)]])->assertSessionHasErrors('pairs');
        $this->splice('joint', $this->joint->id, [[$f1, $f1]])->assertSessionHasErrors('pairs');
    }

    public function test_trace_follows_a_core_through_every_splice()
    {
        // ODC → feeder core 5 → JB-01 → distribution core 3 → ODP → drop core 1
        $drop = Cable::create([
            'code' => 'DRP-01', 'type' => 'drop', 'cores' => 1,
            'from_type' => 'odp', 'from_id' => $this->odp->id,
            'path' => [[-6.2, 106.802], [-6.2005, 106.802]],
        ]);
        $this->splice('joint', $this->joint->id, [[$this->core($this->feeder, 5), $this->core($this->distribution, 3)]])->assertSessionHasNoErrors();
        $this->splice('odp', $this->odp->id, [[$this->core($this->distribution, 3), $this->core($drop, 1)]])->assertSessionHasNoErrors();

        // Traced from the middle, the chain still comes out whole and in order.
        $trace = $this->getJson('/cable-cores/'.$this->core($this->distribution, 3)->id.'/trace')->assertOk()->json();

        $chain = array_map(fn ($s) => $s['cable']['code'].'#'.$s['number'], $trace['segments']);
        $this->assertTrue(in_array($chain, [['FDR-01#5', 'DST-01#3', 'DRP-01#1'], ['DRP-01#1', 'DST-01#3', 'FDR-01#5']], true));
        $this->assertCount(2, $trace['joins']);
        $this->assertSame(
            $chain[0] === 'FDR-01#5' ? ['JB-01', $this->odp->code] : [$this->odp->code, 'JB-01'],
            array_column($trace['joins'], 'code'),
        );
    }

    public function test_spliced_cores_count_as_recorded_and_splices_can_be_removed()
    {
        $this->splice('joint', $this->joint->id, [[$this->core($this->feeder, 20), $this->core($this->distribution, 1)]])->assertSessionHasNoErrors();
        CableCore::query()->update(['status' => 'kosong']);

        // Core 20 still has a splice, so the feeder can't shrink to 12 cores.
        $this->assertFalse($this->feeder->fresh()->forceFill(['cores' => 12])->syncCores());

        $splice = CoreSplice::sole();
        $this->delete("/splices/{$splice->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, CoreSplice::count());
        $this->assertTrue($this->feeder->fresh()->forceFill(['cores' => 12])->syncCores());
    }

    public function test_splice_sheets_trace_every_core_from_start_to_end()
    {
        // ODC → feeder core 5 → JB-01 → distribution core 3 → ODP → drop core 1 → (free end)
        $drop = Cable::create([
            'code' => 'DRP-01', 'type' => 'drop', 'cores' => 1,
            'from_type' => 'odp', 'from_id' => $this->odp->id,
            'path' => [[-6.2, 106.802], [-6.2005, 106.802]],
        ]);
        $this->splice('joint', $this->joint->id, [[$this->core($this->feeder, 5), $this->core($this->distribution, 3)]])->assertSessionHasNoErrors();
        $this->splice('odp', $this->odp->id, [[$this->core($this->distribution, 3), $this->core($drop, 1)]])->assertSessionHasNoErrors();

        // At the joint: one row, carrying the whole path across three cables.
        $this->get("/splice-sheet/joint/{$this->joint->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('SpliceSheet')
            ->where('sheet.title', 'JB-01')
            ->where('sheet.subtitle', 'Joint box')
            ->has('rows', 1)
            ->where('rows.0.a.cable.code', 'FDR-01')
            ->where('rows.0.b.cable.code', 'DST-01')
            ->has('rows.0.trace.segments', 3)
            ->where('cables', fn ($cables) => collect($cables)->pluck('spliced_here', 'code')->all() === ['DST-01' => 1, 'FDR-01' => 1]));

        $trace = $this->getJson('/cable-cores/'.$this->core($this->feeder, 5)->id.'/trace')->json();
        $ends = [$trace['start']['code'] ?? null, $trace['end']['code'] ?? null];
        // The drop's far end is free, so one side ends at the ODC and the other has no asset.
        $this->assertContains($this->odc->code, $ends);
        $this->assertContains(null, $ends);

        // The feeder's sheet lists all 24 cores; unspliced ones are their own one-cable path.
        $this->get("/splice-sheet/cable/{$this->feeder->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->has('rows', 24)
            ->has('rows.4.trace.segments', 3)
            ->has('rows.0.trace.segments', 1)
            ->where('rows.0.trace.start.code', $this->odc->code)
            ->where('rows.0.trace.end.code', 'JB-01'));
    }

    public function test_splice_sheet_rejects_unknown_kinds_and_other_areas()
    {
        $this->get('/splice-sheet/customer/1')->assertNotFound();

        $foreign = SpliceBox::factory()->create();
        SpliceBox::withoutGlobalScopes()->whereKey($foreign->id)->update(['area_id' => Area::factory()->create()->id]);
        $this->get("/splice-sheet/joint/{$foreign->id}")->assertNotFound();
    }

    public function test_core_can_end_on_an_odp_input_or_output_port()
    {
        $port = $this->odp->ports()->where('number', 3)->sole();
        $customer = \App\Models\Customer::create(['area_id' => $this->area->id, 'name' => 'Budi']);
        $port->update(['status' => 'terpakai', 'customer_id' => $customer->id]);

        // Feeder core 1 → JB-01 → distribution core 1 → ODP input 1; distribution core 2 straight to port 3.
        $this->splice('joint', $this->joint->id, [[$this->core($this->feeder, 1), $this->core($this->distribution, 1)]])->assertSessionHasNoErrors();
        $d1 = $this->core($this->distribution, 1);
        $d2 = $this->core($this->distribution, 2);
        $this->terminate('odp', $this->odp->id, $d1, 'input', 1)->assertSessionHasNoErrors();
        $this->terminate('odp', $this->odp->id, $d2, 'output', 3)->assertSessionHasNoErrors();

        $this->assertSame('terpakai', $d2->fresh()->status->value);

        // Traced from the feeder, the path ends on the ODP's splitter input.
        $trace = $this->getJson('/cable-cores/'.$this->core($this->feeder, 1)->id.'/trace')->json();
        $termination = $trace['start_termination'] ?? $trace['end_termination'];
        $this->assertSame(['input', 1, $this->odp->code], [$termination['kind'], $termination['port'], $termination['location']['code']]);

        // The direct core reaches the customer on port 3.
        $trace = $this->getJson("/cable-cores/{$d2->id}/trace")->json();
        $termination = $trace['start_termination'] ?? $trace['end_termination'];
        $this->assertSame('Budi', $termination['customer']['name']);

        // The ODP panel and splice sheet show both.
        $this->assertCount(2, $this->getJson("/odps/{$this->odp->id}")->json('odp.core_terminations'));
        $this->get("/splice-sheet/odp/{$this->odp->id}")->assertOk()->assertInertia(fn ($page) => $page->has('rows', 2));
        $data = $this->getJson("/splices?location_type=odp&location_id={$this->odp->id}")->json();
        $this->assertCount(2, $data['terminations']);
        $this->assertSame(8, count($data['terminal']['ports']));
    }

    public function test_termination_rules()
    {
        $d1 = $this->core($this->distribution, 1);
        $d2 = $this->core($this->distribution, 2);
        $odp = $this->odp->id;
        $this->terminate('odp', $odp, $d1, 'input', 1)->assertSessionHasNoErrors();

        // One core per input/port, and a core ends on an ODP only once.
        $this->terminate('odp', $odp, $d2, 'input', 1)->assertSessionHasErrors('port');
        $this->terminate('odp', $odp, $d1, 'output', 2)->assertSessionHasErrors('core_id');
        // Ports beyond the ODP's capacity don't exist.
        $this->terminate('odp', $odp, $d2, 'output', 99)->assertSessionHasErrors('port');
        // The feeder doesn't reach this ODP.
        $this->terminate('odp', $odp, $this->core($this->feeder, 1), 'output', 2)->assertSessionHasErrors('core_id');
        // Joints have no splitter or ports.
        $this->terminate('joint', $this->joint->id, $d2, 'input', 1)->assertSessionHasErrors('location_type');

        // A terminated core can't be spliced at the same ODP, and the ODP can't shrink below a terminated port.
        $this->terminate('odp', $odp, $d2, 'output', 8)->assertSessionHasNoErrors();
        $this->assertFalse($this->odp->fresh()->forceFill(['capacity' => 4])->syncPorts());

        $termination = \App\Models\CoreTermination::where('port', 8)->sole();
        $this->delete("/terminations/{$termination->id}")->assertSessionHasNoErrors();
        $this->assertTrue($this->odp->fresh()->forceFill(['capacity' => 4])->syncPorts());
    }

    public function test_core_can_end_on_an_odc_input_or_an_output_port_feeding_an_odp()
    {
        // A feeder from the POP side ends at the ODC; ODC port 2 feeds our ODP.
        $this->odp->update(['odc_id' => $this->odc->id, 'odc_port' => 2]);
        $this->terminate('odc', $this->odc->id, $this->core($this->feeder, 1), 'input', 1)->assertSessionHasNoErrors();
        $this->terminate('odc', $this->odc->id, $this->core($this->feeder, 2), 'output', 2)->assertSessionHasNoErrors();

        $trace = $this->getJson('/cable-cores/'.$this->core($this->feeder, 2)->id.'/trace')->json();
        $termination = $trace['start_termination'];
        $this->assertSame(['odc', 'output', 2, $this->odp->code], [
            $termination['location']['kind'], $termination['kind'], $termination['port'], $termination['odp']['code'],
        ]);

        $odc = $this->getJson("/odcs/{$this->odc->id}")->json();
        $this->assertCount(2, $odc['core_terminations']);
        $ports = $this->getJson("/splices?location_type=odc&location_id={$this->odc->id}")->json('terminal.ports');
        $this->assertSame($this->odp->code, $ports[1]['feeds']);
        $this->get("/splice-sheet/odc/{$this->odc->id}")->assertOk()->assertInertia(fn ($page) => $page->has('rows', 2));

        // The ODC can't shrink below a port with a core on it; deleting it removes its terminations.
        $this->put("/odcs/{$this->odc->id}", [
            'code' => $this->odc->code, 'status' => 'aktif', 'capacity' => 1, 'lat' => -6.2, 'lng' => 106.8,
        ])->assertSessionHasErrors('capacity');
        $this->delete("/odcs/{$this->odc->id}");
        $this->assertSame(0, \App\Models\CoreTermination::count());
    }

    public function test_cable_detail_shows_how_often_each_core_is_spliced()
    {
        $this->splice('joint', $this->joint->id, [[$this->core($this->feeder, 1), $this->core($this->distribution, 1)]])->assertSessionHasNoErrors();

        $cores = $this->getJson("/cables/{$this->feeder->id}")->json('cable.core_list');
        $this->assertSame(1, $cores[0]['splices']);
        $this->assertSame(0, $cores[1]['splices']);
    }
}
