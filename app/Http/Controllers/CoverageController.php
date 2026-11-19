<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Services\CoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CoverageController extends Controller
{
    public function index(Request $request, CoverageService $coverage): Response
    {
        $user = $request->user();
        $areas = $user->seesAllAreas() ? Area::orderBy('name')->get() : Area::whereKey($user->area_id)->get();

        return Inertia::render('Coverage', [
            'radius' => $coverage->radius(),
            'radiusLimits' => [CoverageService::MIN_RADIUS, CoverageService::MAX_RADIUS],
            'routingAvailable' => $coverage->routingAvailable(),
            'canEditRadius' => $user->isAdministrator(),
            'areas' => $areas->map->only('id', 'code', 'name', 'center_lat', 'center_lng'),
        ]);
    }

    public function check(Request $request, CoverageService $coverage): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'road' => ['nullable', 'boolean'],
        ]);

        return response()->json($coverage->check((float) $data['lat'], (float) $data['lng'], $request->boolean('road', true)));
    }

    /**
     * Super admin: the maximum ODP–customer distance for every coverage check.
     */
    public function updateRadius(Request $request, CoverageService $coverage): RedirectResponse
    {
        $data = $request->validate([
            'radius' => ['required', 'integer', 'min:'.CoverageService::MIN_RADIUS, 'max:'.CoverageService::MAX_RADIUS],
        ], [], ['radius' => 'batas jarak']);

        $coverage->setRadius($data['radius']);

        return back();
    }
}
