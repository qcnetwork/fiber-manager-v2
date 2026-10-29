<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Cable;
use App\Models\Odc;
use App\Models\Odp;
use App\Services\CoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MapController extends Controller
{
    /**
     * Maximum markers per layer returned for one viewport.
     */
    private const FEATURE_LIMIT = 3000;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $areas = $user->seesAllAreas() ? Area::orderBy('name')->get() : Area::whereKey($user->area_id)->get();

        return Inertia::render('Map', [
            'areas' => $areas->map->only('id', 'code', 'name', 'center_lat', 'center_lng'),
            'coverageRadius' => config('odp.coverage_radius'),
            'can' => [
                'edit' => $user->canEditNetwork(),
                'delete' => $user->canDeleteNetwork(),
                'chooseArea' => $user->seesAllAreas(),
            ],
        ]);
    }

    /**
     * ODC and ODP markers inside the visible map bounds.
     */
    public function features(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bbox' => ['required', 'regex:/^-?[\d.]+,-?[\d.]+,-?[\d.]+,-?[\d.]+$/'],
            'area_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
        ]);

        [$west, $south, $east, $north] = array_map('floatval', explode(',', $data['bbox']));

        $inView = fn ($query) => $query
            ->whereBetween('lat', [$south, $north])
            ->whereBetween('lng', [$west, $east])
            ->when($data['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->limit(self::FEATURE_LIMIT);

        $odcs = $inView(Odc::query())
            ->withCount('odps')
            ->get(['id', 'code', 'name', 'lat', 'lng', 'status', 'capacity']);

        $odps = $inView(Odp::query())
            ->withPortUsage()
            ->with('odc:id,lat,lng')
            ->get(['id', 'odc_id', 'code', 'name', 'lat', 'lng', 'status', 'capacity']);

        // A cable is in view when its bounding box overlaps the viewport.
        $cables = Cable::query()
            ->where('min_lat', '<=', $north)->where('max_lat', '>=', $south)
            ->where('min_lng', '<=', $east)->where('max_lng', '>=', $west)
            ->when($data['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->limit(self::FEATURE_LIMIT)
            ->get(['id', 'code', 'type', 'cores', 'status', 'path', 'length_m']);

        return response()->json([
            'cables' => $cables,
            'odcs' => $odcs,
            'odps' => $odps->map(fn (Odp $odp) => [
                ...$odp->only('id', 'odc_id', 'code', 'name', 'lat', 'lng', 'status', 'capacity', 'used_ports', 'free_ports'),
                'odc_latlng' => $odp->odc ? [$odp->odc->lat, $odp->odc->lng] : null,
            ]),
            'truncated' => $odcs->count() >= self::FEATURE_LIMIT || $odps->count() >= self::FEATURE_LIMIT,
        ]);
    }

    /**
     * ODC/ODP whose code or name matches, for jumping to them on the map.
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $match = fn ($query) => $query
            ->where(fn ($q) => $q->where('code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"))
            ->orderBy('code')
            ->limit(10)
            ->get(['id', 'code', 'name', 'lat', 'lng']);

        return response()->json([
            ...$match(Odc::query())->map(fn (Odc $odc) => ['kind' => 'odc', ...$odc->toArray()]),
            ...$match(Odp::query())->map(fn (Odp $odp) => ['kind' => 'odp', ...$odp->toArray()]),
        ]);
    }

    /**
     * ODPs around a point, closest first, for checking coverage of a new customer.
     */
    public function nearest(Request $request, CoverageService $coverage): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'integer', 'min:10', 'max:5000'],
            'only_available' => ['nullable', 'boolean'],
        ]);

        $radius = $data['radius'] ?? $coverage->radius();

        $odps = $coverage->odpsNear((float) $data['lat'], (float) $data['lng'], $radius)
            ->when($request->boolean('only_available', true), fn ($c) => $c->where('free_ports', '>', 0))
            ->values()
            ->take(20);

        return response()->json(['radius' => $radius, 'odps' => $odps]);
    }
}
