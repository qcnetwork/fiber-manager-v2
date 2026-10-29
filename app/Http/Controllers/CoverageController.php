<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Services\CoverageService;
use Illuminate\Http\JsonResponse;
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
            'areas' => $areas->map->only('id', 'code', 'name', 'center_lat', 'center_lng'),
        ]);
    }

    public function check(Request $request, CoverageService $coverage): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json($coverage->check((float) $data['lat'], (float) $data['lng']));
    }
}
