<?php

namespace App\Http\Controllers;

use App\Enums\CoreStatus;
use App\Models\ActivityLog;
use App\Models\Cable;
use App\Models\CableCore;
use App\Models\CoreSplice;
use App\Models\CoreTermination;
use App\Models\Odc;
use App\Models\Odp;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A core ending at an ODP or ODC: on a splitter input or straight on an output port.
 */
class CoreTerminationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canEditNetwork(), 403);

        $where = $request->validate([
            'location_type' => ['required', Rule::in(CoreTermination::LOCATIONS)],
            'location_id' => ['required', 'integer'],
        ]);
        /** @var Odp|Odc $location Resolving through the area scope guards access. */
        $location = Relation::getMorphedModel($where['location_type'])::findOrFail($where['location_id']);

        $data = $request->validate([
            'core_id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(CoreTermination::KINDS)],
            'port' => [
                'required', 'integer', 'min:1',
                'max:'.($request->input('kind') === 'input' ? CoreTermination::MAX_INPUTS : $location->capacity),
                Rule::unique('core_terminations')
                    ->where('location_type', $where['location_type'])
                    ->where('location_id', $location->id)
                    ->where('kind', $request->input('kind')),
            ],
            'side' => ['nullable', Rule::in(CableCore::SIDES)],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'port.unique' => $request->input('kind') === 'input' ? 'Input ini sudah dipakai core lain.' : 'Port ini sudah dipakai core lain.',
        ]);

        $label = strtoupper($where['location_type']);
        $core = CableCore::find($data['core_id']);
        $cable = $core ? Cable::connectedTo($location)->with(['stops.asset', 'from', 'to'])->find($core->cable_id) : null;
        if (! $cable) {
            throw ValidationException::withMessages(['core_id' => "Core harus milik kabel yang tersambung ke {$label} ini."]);
        }
        // Which way along the cable the core is taken; only asked where the cable passes through.
        $side = CoreSpliceController::resolveSide($cable, $location, $data['side'] ?? null, 'side');
        $used = CoreSpliceController::usedSides(
            CoreSplice::involving($core->id)->where('location_type', $where['location_type'])->where('location_id', $location->id)->get(),
            CoreTermination::at($location)->where('core_id', $core->id)->get(),
        );
        if (isset($used[$core->id][$side])) {
            throw ValidationException::withMessages(['core_id' => "Core {$core->number} sudah tersambung di {$label} ini pada arah tersebut."]);
        }
        $data['side'] = $side;

        DB::transaction(function () use ($data, $where, $location, $core) {
            CoreTermination::create([...$data, ...$where]);
            if ($core->status === CoreStatus::Kosong) {
                $core->update(['status' => CoreStatus::Terpakai]);
            }
            ActivityLog::record('created', 'splice', null, $location->code, $location->area_id, [
                'sambungan' => [null, $this->summary($core, $data['kind'], $data['port'])],
            ]);
        });

        return back();
    }

    public function destroy(Request $request, CoreTermination $termination): RedirectResponse
    {
        abort_unless($request->user()->canEditNetwork(), 403);
        $location = Relation::getMorphedModel($termination->location_type)::findOrFail($termination->location_id); // area scope guards access
        $summary = $this->summary($termination->core, $termination->kind, $termination->port);

        $termination->delete();
        ActivityLog::record('deleted', 'splice', null, $location->code, $location->area_id, ['sambungan' => [$summary, null]]);

        return back();
    }

    private function summary(CableCore $core, string $kind, int $port): string
    {
        $cable = Cable::withoutGlobalScopes()->find($core->cable_id);

        return ($cable?->code ?? "Kabel #{$core->cable_id}")." core {$core->number} → ".($kind === 'input' ? "input {$port}" : "port {$port}");
    }
}
