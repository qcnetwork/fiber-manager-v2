<?php

namespace App\Http\Controllers;

use App\Http\Requests\OdcRequest;
use App\Models\CoreTermination;
use App\Models\Odc;
use App\Models\Odp;
use App\Services\GeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OdcController extends Controller
{
    public function show(Odc $odc): JsonResponse
    {
        $odc->load(['area:id,code,name', 'oltPort:id,olt_id,slot,port,status', 'oltPort.olt:id,area_id,code,name', 'photos.user:id,name']);
        $odps = $odc->odps()->withPortUsage()->orderBy('odc_port')->get(['id', 'odc_id', 'odc_port', 'code', 'capacity', 'status']);

        return response()->json([
            'odc' => $odc,
            'odps' => $odps,
            'used_odc_ports' => $odps->pluck('odc_port')->filter()->values(),
            'customer_count' => $odps->sum('used_ports'),
            // Cores ending on this ODC's splitter inputs and output ports.
            'core_terminations' => CoreTermination::listFor($odc),
        ]);
    }

    public function store(OdcRequest $request): RedirectResponse
    {
        DB::transaction(fn () => $request->connectCables(Odc::create($request->assetData())));

        return back();
    }

    public function update(OdcRequest $request, Odc $odc): RedirectResponse
    {
        DB::transaction(function () use ($request, $odc) {
            $odc->update($request->assetData());

            // An output port with a core terminated on it can't disappear.
            if (CoreTermination::at($odc)->where('kind', 'output')->where('port', '>', $odc->capacity)->exists()) {
                throw ValidationException::withMessages([
                    'capacity' => 'Kapasitas tidak bisa dikurangi: port bernomor di atas '.$odc->capacity.' masih tersambung ke core kabel.',
                ]);
            }
            $request->connectCables($odc);
        });

        return back();
    }

    public function destroy(Request $request, Odc $odc): RedirectResponse
    {
        abort_unless($request->user()->canDeleteNetwork(), 403);

        // ODPs stay on the map but lose their uplink, so free their ODC port numbers too.
        Odp::where('odc_id', $odc->id)->update(['odc_port' => null]);
        $odc->delete();

        return back();
    }

    /**
     * ODCs to choose from when connecting an ODP, closest to the given point first; from every area unless one is given.
     */
    public function options(Request $request): JsonResponse
    {
        $data = $request->validate([
            'area_id' => ['nullable', 'integer'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
        ]);

        $odcs = Odc::query()
            ->when($data['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->with(['odps:id,odc_id,odc_port', 'area:id,code,name'])
            ->get(['id', 'area_id', 'code', 'name', 'lat', 'lng', 'capacity'])
            ->map(fn (Odc $odc) => [
                ...$odc->only('id', 'area_id', 'code', 'name', 'capacity'),
                'area' => $odc->area?->only('id', 'code', 'name'),
                'used_ports' => $odc->odps->pluck('odc_port')->filter()->values(),
                'distance' => isset($data['lat'], $data['lng'])
                    ? round(GeoService::distance((float) $data['lat'], (float) $data['lng'], $odc->lat, $odc->lng))
                    : null,
            ])
            ->sortBy('distance')
            ->values();

        return response()->json($odcs);
    }
}
