<?php

namespace App\Http\Controllers;

use App\Models\Odc;
use App\Models\Olt;
use App\Models\OltPort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OltPortController extends Controller
{
    /**
     * Marks a port broken or adds a note.
     */
    public function update(Request $request, OltPort $port): RedirectResponse
    {
        $this->olt($port);
        abort_unless($request->user()->canDeleteNetwork(), 403);

        $port->update($request->validate([
            'status' => ['required', Rule::in(OltPort::STATUSES)],
            'notes' => ['nullable', 'string', 'max:255'],
        ]));

        return back();
    }

    /**
     * Feeds an ODC from this port. A port may feed several ODCs.
     */
    public function connect(Request $request, OltPort $port): RedirectResponse
    {
        $olt = $this->olt($port);
        abort_unless($request->user()->canEditNetwork(), 403);

        $data = $request->validate(['odc_id' => ['required', 'integer']]);
        $odc = Odc::findOrFail($data['odc_id']);

        if ($odc->area_id !== $olt->area_id) {
            throw ValidationException::withMessages(['odc_id' => "ODC {$odc->code} ada di area lain; pilih ODC di POP ini."]);
        }

        $odc->update(['olt_port_id' => $port->id]);

        return back();
    }

    public function disconnect(Request $request, OltPort $port, Odc $odc): RedirectResponse
    {
        $this->olt($port);
        abort_unless($request->user()->canEditNetwork(), 403);
        abort_unless($odc->olt_port_id === $port->id, 404);

        $odc->update(['olt_port_id' => null]);

        return back();
    }

    /**
     * Ports have no area of their own; resolving the OLT through its area scope guards access.
     */
    private function olt(OltPort $port): Olt
    {
        return $port->olt()->firstOrFail();
    }
}
