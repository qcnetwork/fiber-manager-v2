<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\MapLayers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MapSettingsController extends Controller
{
    /**
     * Show the map defaults: which layers are ticked when the map opens.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/MapDefaults', [
            'layers' => $request->user()->mapLayers(),
            'defaults' => MapLayers::DEFAULTS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = ['base' => ['required', Rule::in(MapLayers::BASES)]];
        foreach (array_keys(MapLayers::DEFAULTS) as $key) {
            if ($key !== 'base') {
                $rules[$key] = ['required', 'boolean'];
            }
        }
        $data = $request->validate($rules);

        $user = $request->user();
        $user->preferences = [
            ...($user->preferences ?? []),
            'map_layers' => MapLayers::resolve(array_map(fn ($v) => is_string($v) ? $v : (bool) $v, $data)),
        ];
        $user->save();

        return back();
    }
}
