<?php

namespace App\Http\Controllers;

use App\Support\NetworkLocation;
use App\Http\Requests\CableRequest;
use App\Models\Area;
use App\Models\Cable;
use App\Models\CableCore;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CableController extends Controller
{
    public function show(Cable $cable): JsonResponse
    {
        // Ends can be a POP (an area, located by center_lat/lng), so columns aren't picked in the query.
        $cable->load(['area:id,code,name', 'from', 'to', 'stops.asset', 'coreRows']);
        $end = fn ($asset) => $asset ? [
            'id' => $asset->id,
            'code' => $asset instanceof Area ? $asset->name : $asset->code,
            'lat' => $asset->lat,
            'lng' => $asset->lng,
        ] : null;

        $connectionCounts = CableCore::connectionCounts($cable->coreRows->modelKeys());

        return response()->json(['cable' => [
            ...Arr::except($cable->toArray(), ['stops', 'core_rows']),
            'from' => $end($cable->from),
            'to' => $end($cable->to),
            'route' => $cable->route(),
            'core_list' => $cable->coreRows->map(fn (CableCore $core) => [
                ...$core->only('id', 'number', 'status', 'notes'),
                'splices' => $connectionCounts[$core->id] ?? 0,
            ])->values(),
        ]]);
    }

    /**
     * Cables passing close to a point, offered for connecting an ODC/ODP being added or moved there.
     */
    public function nearby(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'area_id' => ['nullable', 'integer'],
            // The asset being edited, to flag cables it is already on.
            'kind' => ['nullable', Rule::in(['odc', 'odp', 'joint'])],
            'id' => ['nullable', 'integer'],
        ]);

        $asset = isset($data['kind'], $data['id']) ? NetworkLocation::find($data['kind'], $data['id']) : null;

        $cables = Cable::nearby((float) $data['lat'], (float) $data['lng'], config('odp.cable_connect_radius'))
            ->each(fn (array $hit) => $hit['cable']->loadMissing('area:id,code,name'))
            ->map(fn (array $hit) => [
                ...$hit['cable']->only('id', 'area_id', 'code', 'type', 'cores', 'length_m'),
                'area' => $hit['cable']->area?->only('id', 'code', 'name'),
                'distance_m' => (int) round($hit['distance']),
                'connected' => $asset ? $hit['cable']->connects($asset) : false,
            ]);

        return response()->json(['radius' => config('odp.cable_connect_radius'), 'cables' => $cables]);
    }

    public function store(CableRequest $request): RedirectResponse
    {
        [$data, $stops] = $this->attributes($request);

        DB::transaction(fn () => Cable::create($data)->syncStops($stops));

        return back();
    }

    public function update(CableRequest $request, Cable $cable): RedirectResponse
    {
        [$data, $stops] = $this->attributes($request);

        DB::transaction(function () use ($cable, $data, $stops) {
            $cable->update($data);
            $cable->syncStops($stops);

            if (! $cable->syncCores()) {
                throw ValidationException::withMessages([
                    'cores' => 'Jumlah core tidak bisa dikurangi: core bernomor di atas '.$cable->cores.' masih punya data (status/keterangan/sambungan).',
                ]);
            }
        });

        return back();
    }

    public function destroy(Request $request, Cable $cable): RedirectResponse
    {
        abort_unless($request->user()->canDeleteNetwork(), 403);

        $cable->delete();

        return back();
    }

    /**
     * Validated data with the path's vertices snapped onto the attached POP/ODC/ODP (ends and pass-through points),
     * and the pass-through points that lie on the path.
     *
     * @return array{0: array<string, mixed>, 1: list<array{vertex: int, type: string, id: int}>}
     */
    private function attributes(CableRequest $request): array
    {
        $data = $request->validated();
        $path = array_map(fn (array $point) => [(float) $point[0], (float) $point[1]], $data['path']);
        $last = count($path) - 1;
        $locate = fn (string $type, int $id) => NetworkLocation::findOrFail($type, $id);

        foreach (['from' => 0, 'to' => $last] as $end => $index) {
            $data["{$end}_type"] ??= null;
            $data["{$end}_id"] ??= null;

            if ($data["{$end}_id"]) {
                $asset = $locate($data["{$end}_type"], $data["{$end}_id"]);
                $path[$index] = [$asset->lat, $asset->lng];
            }
        }

        $stops = [];
        foreach ($data['stops'] ?? [] as $stop) {
            $vertex = (int) $stop['vertex'];
            if ($vertex <= 0 || $vertex >= $last) {
                continue; // the ends are from/to
            }
            $asset = $locate($stop['type'], (int) $stop['id']);
            if ($asset->lat !== null && $asset->lng !== null) {
                $path[$vertex] = [$asset->lat, $asset->lng];
            }
            $stops[] = ['vertex' => $vertex, 'type' => $stop['type'], 'id' => (int) $stop['id']];
        }

        unset($data['stops']);
        $data['path'] = $path;

        return [$data, $stops];
    }
}
