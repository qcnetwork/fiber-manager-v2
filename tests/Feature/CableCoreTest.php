<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Cable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CableCoreTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = Area::factory()->create();
        $this->actingAs(User::factory()->create(['area_id' => $this->area->id, 'role' => Role::AdminArea]));
    }

    private function cablePayload(array $overrides = []): array
    {
        return [
            'type' => 'distribusi', 'cores' => 24, 'tube_size' => 12, 'status' => 'aktif',
            'path' => [[-6.2, 106.8], [-6.2, 106.801]],
            ...$overrides,
        ];
    }

    public function test_new_cable_gets_one_row_per_core()
    {
        $this->post('/cables', $this->cablePayload())->assertSessionHasNoErrors();

        $cable = Cable::sole();
        $this->assertSame(12, $cable->tube_size);
        $this->assertSame(range(1, 24), $cable->coreRows()->pluck('number')->all());

        $cores = $this->getJson("/cables/{$cable->id}")->json('cable.core_list');
        $this->assertCount(24, $cores);
        $this->assertSame('kosong', $cores[0]['status']);
    }

    public function test_tube_size_never_exceeds_the_core_count()
    {
        $this->post('/cables', $this->cablePayload(['cores' => 4, 'tube_size' => 12]))->assertSessionHasNoErrors();

        $this->assertSame(4, Cable::sole()->tube_size);
    }

    public function test_core_status_and_notes_can_be_changed()
    {
        $cable = Cable::create($this->cablePayload());
        $core = $cable->coreRows()->where('number', 3)->sole();

        $this->patch("/cable-cores/{$core->id}", ['status' => 'terpakai', 'notes' => 'Ke ODP-07'])->assertSessionHasNoErrors();

        $core->refresh();
        $this->assertSame('terpakai', $core->status->value);
        $this->assertSame('Ke ODP-07', $core->notes);
    }

    public function test_core_count_changes_add_or_remove_rows_but_never_lose_data()
    {
        $cable = Cable::create($this->cablePayload());

        $this->put("/cables/{$cable->id}", $this->cablePayload(['cores' => 48]))->assertSessionHasNoErrors();
        $this->assertSame(48, $cable->coreRows()->count());

        $cable->coreRows()->where('number', 30)->update(['status' => 'terpakai']);
        $this->put("/cables/{$cable->id}", $this->cablePayload(['cores' => 24]))->assertSessionHasErrors('cores');
        $this->assertSame(48, $cable->fresh()->cores);
        $this->assertSame(48, $cable->coreRows()->count());

        $cable->coreRows()->where('number', 30)->update(['status' => 'kosong']);
        $this->put("/cables/{$cable->id}", $this->cablePayload(['cores' => 24]))->assertSessionHasNoErrors();
        $this->assertSame(24, $cable->coreRows()->count());
    }

    public function test_cores_of_a_cable_in_another_area_cannot_be_changed()
    {
        $cable = Cable::create($this->cablePayload());
        Cable::withoutGlobalScopes()->whereKey($cable->id)->update(['area_id' => Area::factory()->create()->id]);
        $core = $cable->coreRows()->first();

        $this->patch("/cable-cores/{$core->id}", ['status' => 'rusak'])->assertNotFound();
    }
}
