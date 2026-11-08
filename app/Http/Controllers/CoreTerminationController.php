<?php

namespace App\Http\Controllers;

use App\Enums\CoreStatus;
use App\Models\ActivityLog;
use App\Models\Cable;
use App\Models\CableCore;
use App\Models\CoreSplice;
use App\Models\CoreTermination;
use App\Models\Odp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A core ending at an ODP: on a splitter input or straight on an output port.
 */
class CoreTerminationController extends Controller
{
    public function store(Request $request, Odp $odp): RedirectResponse
    {
        abort_unless($request->user()->canEditNetwork(), 403);

        $data = $request->validate([
            'core_id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(CoreTermination::KINDS)],
            'port' => [
                'required', 'integer', 'min:1',
                'max:'.($request->input('kind') === 'input' ? CoreTermination::MAX_INPUTS : $odp->capacity),
                Rule::unique('core_terminations')->where('odp_id', $odp->id)->where('kind', $request->input('kind')),
            ],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'port.unique' => $request->input('kind') === 'input' ? 'Input ini sudah dipakai core lain.' : 'Port ini sudah dipakai core lain.',
        ]);

        $core = CableCore::find($data['core_id']);
        if (! $core || ! Cable::connectedTo($odp)->whereKey($core->cable_id)->exists()) {
            throw ValidationException::withMessages(['core_id' => 'Core harus milik kabel yang tersambung ke ODP ini.']);
        }
        $usedHere = CoreSplice::involving($core->id)->where('location_type', 'odp')->where('location_id', $odp->id)->exists()
            || CoreTermination::where('core_id', $core->id)->where('odp_id', $odp->id)->exists();
        if ($usedHere) {
            throw ValidationException::withMessages(['core_id' => "Core {$core->number} sudah tersambung di ODP ini."]);
        }
        if (CableCore::connectionCounts([$core->id])[$core->id] >= CableCore::MAX_CONNECTIONS) {
            throw ValidationException::withMessages(['core_id' => "Core {$core->number} sudah tersambung di kedua ujungnya."]);
        }

        DB::transaction(function () use ($data, $odp, $core) {
            CoreTermination::create([...$data, 'odp_id' => $odp->id]);
            if ($core->status === CoreStatus::Kosong) {
                $core->update(['status' => CoreStatus::Terpakai]);
            }
            ActivityLog::record('created', 'splice', null, $odp->code, $odp->area_id, [
                'sambungan' => [null, $this->summary($core, $data['kind'], $data['port'])],
            ]);
        });

        return back();
    }

    public function destroy(Request $request, CoreTermination $termination): RedirectResponse
    {
        abort_unless($request->user()->canEditNetwork(), 403);
        $odp = $termination->odp()->firstOrFail(); // area scope guards access
        $summary = $this->summary($termination->core, $termination->kind, $termination->port);

        $termination->delete();
        ActivityLog::record('deleted', 'splice', null, $odp->code, $odp->area_id, ['sambungan' => [$summary, null]]);

        return back();
    }

    private function summary(CableCore $core, string $kind, int $port): string
    {
        $cable = Cable::withoutGlobalScopes()->find($core->cable_id);

        return ($cable?->code ?? "Kabel #{$core->cable_id}")." core {$core->number} → ".($kind === 'input' ? "input {$port}" : "port {$port}");
    }
}
