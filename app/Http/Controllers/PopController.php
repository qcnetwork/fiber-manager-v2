<?php

namespace App\Http\Controllers;

use App\Models\Area;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A POP is an area seen as a place: its location, OLTs and their PON ports.
 */
class PopController extends Controller
{
    public function show(Request $request, Area $pop): JsonResponse
    {
        abort_unless($request->user()->canAccessArea($pop->id), 404);

        $pop->load([
            'olts' => fn ($q) => $q->orderBy('code'),
            'olts.ports.odcs' => fn ($q) => $q->orderBy('code')->select('id', 'olt_port_id', 'code', 'name', 'status'),
            'photos.user:id,name',
        ]);

        return response()->json(['pop' => [...$pop->toArray(), 'lat' => $pop->lat, 'lng' => $pop->lng]]);
    }

    /**
     * Location and description of the POP; the area's code and name stay in the admin page.
     */
    public function update(Request $request, Area $pop): RedirectResponse
    {
        abort_unless($request->user()->canAccessArea($pop->id), 404);
        abort_unless($request->user()->canDeleteNetwork(), 403);

        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $pop->update([
            'center_lat' => $data['lat'],
            'center_lng' => $data['lng'],
            'address' => $data['address'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return back();
    }
}
