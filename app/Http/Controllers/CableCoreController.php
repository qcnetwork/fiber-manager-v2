<?php

namespace App\Http\Controllers;

use App\Enums\CoreStatus;
use App\Models\CableCore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CableCoreController extends Controller
{
    /**
     * Change a core's status and what it is used for.
     */
    public function update(Request $request, CableCore $core): RedirectResponse
    {
        // Cores have no area of their own; resolving the cable through its area scope guards access.
        $core->cable()->firstOrFail();
        abort_unless($request->user()->canEditNetwork(), 403);

        $core->update($request->validate([
            'status' => ['required', Rule::enum(CoreStatus::class)],
            'notes' => ['nullable', 'string', 'max:255'],
        ]));

        return back();
    }
}
