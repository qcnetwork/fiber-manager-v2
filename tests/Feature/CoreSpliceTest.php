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

    public function test_cable_detail_shows_how_often_each_core_is_spliced()
    {
        $this->splice('joint', $this->joint->id, [[$this->core($this->feeder, 1), $this->core($this->distribution, 1)]])->assertSessionHasNoErrors();

        $cores = $this->getJson("/cables/{$this->feeder->id}")->json('cable.core_list');
        $this->assertSame(1, $cores[0]['splices']);
        $this->assertSame(0, $cores[1]['splices']);
    }
}
