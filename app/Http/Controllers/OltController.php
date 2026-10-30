<?php

namespace App\Http\Controllers;

use App\Models\Olt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * OLTs at a POP. Only super admins and area admins add or change them; technicians connect ODCs to their ports.
 */
class OltController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canDeleteNetwork(), 403);

        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $olt = Olt::create($data['olt']);
            $olt->syncPorts($data['cards']);
        });

        return back();
    }

    public function update(Request $request, Olt $olt): RedirectResponse
    {
        abort_unless($request->user()->canDeleteNetwork(), 403);

        $data = $this->validated($request, $olt);

        DB::transaction(function () use ($olt, $data) {
            $olt->update($data['olt']);
            if (! $olt->syncPorts($data['cards'])) {
                throw ValidationException::withMessages([
                    'cards' => 'Ada port yang masih tersambung ke ODC. Lepas dulu ODC-nya sebelum mengurangi kartu/port.',
                ]);
            }
        });

        return back();
    }

    /**
     * Ports go with the OLT; their ODCs stay on the map without an uplink.
     */
    public function destroy(Request $request, Olt $olt): RedirectResponse
    {
        abort_unless($request->user()->canDeleteNetwork(), 403);

        $olt->delete();

        return back();
    }

    /**
     * OLTs of an area with their ports and what is connected, for choosing an ODC's uplink.
     */
    public function options(Request $request): JsonResponse
    {
        $data = $request->validate(['area_id' => ['nullable', 'integer']]);

        $olts = Olt::query()
            ->when($data['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->with(['ports.odcs:id,olt_port_id,code'])
            ->orderBy('code')
            ->get(['id', 'area_id', 'code', 'name']);

        return response()->json($olts->map(fn (Olt $olt) => [
            ...$olt->only('id', 'area_id', 'code', 'name'),
            'ports' => $olt->ports->map(fn ($port) => [
                ...$port->only('id', 'slot', 'port', 'status'),
                'odcs' => $port->odcs->map->only('id', 'code')->values(),
            ]),
        ]));
    }

    /**
     * @return array{olt: array<string, mixed>, cards: array<int, int>}
     */
    private function validated(Request $request, ?Olt $olt = null): array
    {
        $data = $request->validate([
            // Users without an area pick the POP; everyone else is pinned to their own by BelongsToArea.
            'area_id' => $request->user()->seesAllAreas() && ! $olt ? ['required', 'exists:areas,id'] : ['exclude'],
            'code' => ['required', 'string', 'max:50', Rule::unique('olts', 'code')->ignore($olt)],
            'name' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'cards' => ['required', 'array', 'min:1', 'max:32'],
            'cards.*.slot' => ['required', 'integer', 'min:0', 'max:99', 'distinct'],
            'cards.*.ports' => ['required', 'integer', 'min:1', 'max:128'],
        ], [
            'cards.required' => 'Tambahkan minimal satu kartu PON.',
            'cards.*.slot.distinct' => 'Nomor slot tidak boleh sama.',
        ]);

        $cards = collect($data['cards'])->mapWithKeys(fn ($c) => [(int) $c['slot'] => (int) $c['ports']])->all();
        unset($data['cards']);
        $data['name'] = ($data['name'] ?? null) ?: $data['code'];

        return ['olt' => $data, 'cards' => $cards];
    }
}
