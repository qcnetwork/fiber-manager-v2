<?php

namespace App\Http\Controllers;

use App\Http\Requests\OdpRequest;
use App\Models\CoreTermination;
use App\Models\Odp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OdpController extends Controller
{
    public function show(Odp $odp): JsonResponse
    {
        $odp->load(['area:id,code,name', 'odc:id,code,name', 'ports.customer', 'photos.user:id,name']);

        // Cores ending on this ODP's splitter inputs and output ports.
        $terminations = CoreTermination::where('odp_id', $odp->id)->with('core.cable:id,code,type,cores,tube_size')->orderBy('kind')->orderBy('port')->get()
            ->map(fn (CoreTermination $t) => [
                'kind' => $t->kind,
                'port' => $t->port,
                'core_id' => $t->core->id,
                'number' => $t->core->number,
                'cable' => $t->core->cable?->only('id', 'code', 'type', 'cores', 'tube_size'),
            ]);

        return response()->json(['odp' => [...$odp->toArray(), 'core_terminations' => $terminations]]);
    }

    public function store(OdpRequest $request): RedirectResponse
    {
        DB::transaction(fn () => $request->connectCables(Odp::create($request->assetData())));

        return back();
    }

    public function update(OdpRequest $request, Odp $odp): RedirectResponse
    {
        DB::transaction(function () use ($request, $odp) {
            $odp->update($request->assetData());

            if (! $odp->syncPorts()) {
                throw ValidationException::withMessages([
                    'capacity' => 'Kapasitas tidak bisa dikurangi: port bernomor di atas '.$odp->capacity.' masih terpakai.',
                ]);
            }

            $request->connectCables($odp);
        });

        return back();
    }

    public function destroy(Request $request, Odp $odp): RedirectResponse
    {
        abort_unless($request->user()->canDeleteNetwork(), 403);

        $odp->delete();

        return back();
    }
}
