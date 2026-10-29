<?php

namespace App\Http\Controllers;

use App\Http\Requests\CableRequest;
use App\Models\Cable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CableController extends Controller
{
    public function show(Cable $cable): JsonResponse
    {
        $cable->load(['area:id,code,name', 'from:id,code,lat,lng', 'to:id,code,lat,lng']);

        return response()->json(['cable' => $cable]);
    }

    public function store(CableRequest $request): RedirectResponse
    {
        Cable::create($this->attributes($request));

        return back();
    }

    public function update(CableRequest $request, Cable $cable): RedirectResponse
    {
        $cable->update($this->attributes($request));

        return back();
    }

    public function destroy(Request $request, Cable $cable): RedirectResponse
    {
        abort_unless($request->user()->canDeleteNetwork(), 403);

        $cable->delete();

        return back();
    }

    /**
     * Validated data with the path's end vertices snapped onto the attached ODC/ODP.
     *
     * @return array<string, mixed>
     */
    private function attributes(CableRequest $request): array
    {
        $data = $request->validated();
        $path = array_map(fn (array $point) => [(float) $point[0], (float) $point[1]], $data['path']);

        foreach (['from' => 0, 'to' => count($path) - 1] as $end => $index) {
            $data["{$end}_type"] ??= null;
            $data["{$end}_id"] ??= null;

            if ($data["{$end}_id"]) {
                $asset = Relation::getMorphedModel($data["{$end}_type"])::findOrFail($data["{$end}_id"]);
                $path[$index] = [$asset->lat, $asset->lng];
            }
        }

        $data['path'] = $path;

        return $data;
    }
}
