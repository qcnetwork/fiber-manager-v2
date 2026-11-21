<?php

namespace App\Http\Controllers;

use App\Enums\PortStatus;
use App\Models\ActivityLog;
use App\Models\Cable;
use App\Models\Customer;
use App\Models\Odp;
use App\Models\OdpPort;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    /**
     * Customer markers are many and only drawn when asked for, so each viewport is capped.
     */
    private const MARKER_LIMIT = 2000;

    /**
     * Customers with a location inside the visible map bounds.
     */
    public function markers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bbox' => ['required', 'regex:/^-?[\d.]+,-?[\d.]+,-?[\d.]+,-?[\d.]+$/'],
            'area_id' => ['nullable', 'integer'],
        ]);

        [$west, $south, $east, $north] = array_map('floatval', explode(',', $data['bbox']));

        $customers = Customer::query()
            ->whereBetween('lat', [$south, $north])
            ->whereBetween('lng', [$west, $east])
            ->when($data['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->with('port.odp:id,lat,lng')
            ->limit(self::MARKER_LIMIT)
            ->get(['id', 'name', 'customer_code', 'lat', 'lng']);

        return response()->json([
            'customers' => $customers->map(fn (Customer $c) => [
                ...$c->only('id', 'name', 'customer_code', 'lat', 'lng'),
                'odp_latlng' => $c->port?->odp ? [$c->port->odp->lat, $c->port->odp->lng] : null,
            ]),
            'truncated' => $customers->count() >= self::MARKER_LIMIT,
        ]);
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->load(['area:id,code,name', 'port.odp:id,code,name,lat,lng', 'photos.user:id,name']);

        return response()->json([
            'customer' => [
                ...$customer->toArray(),
                // Drop cables running to the house.
                'cables' => Cable::reaching($customer)->orderBy('id')->get(['id', 'code', 'type', 'cores', 'length_m']),
            ],
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($request->user()->canEditNetwork(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'customer_code' => ['nullable', 'string', 'max:50', Rule::unique('customers', 'customer_code')->ignore($customer->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'package' => ['nullable', 'string', 'max:255'],
            'ont_sn' => ['nullable', 'string', 'max:50'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ], [
            'lat.required_with' => 'Isi latitude dan longitude, atau kosongkan keduanya.',
            'lng.required_with' => 'Isi latitude dan longitude, atau kosongkan keduanya.',
        ]);

        $customer->update($data);

        return back();
    }

    /**
     * Free ports of an ODP, for choosing where to move a customer.
     */
    public function freePorts(Odp $odp): JsonResponse
    {
        return response()->json([
            'odp' => $odp->only('id', 'code', 'name', 'capacity'),
            'ports' => $odp->ports()
                ->whereNull('customer_id')
                ->whereIn('status', [PortStatus::Kosong, PortStatus::Booking])
                ->orderBy('number')
                ->get(['id', 'number', 'status', 'notes']),
        ]);
    }

    /**
     * Moves a customer to another port, on the same or another ODP. The old port becomes free.
     */
    public function move(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($request->user()->canEditNetwork(), 403);

        $data = $request->validate(['port_id' => ['required', 'integer']]);

        $target = OdpPort::findOrFail($data['port_id']);
        // Ports have no area of their own; the ODP's area scope decides whether the user may use it.
        $odp = $target->odp()->firstOrFail();

        if ($target->customer_id !== null || ! in_array($target->status, [PortStatus::Kosong, PortStatus::Booking], true)) {
            throw ValidationException::withMessages(['port_id' => "Port {$target->number} di {$odp->code} sudah tidak kosong. Pilih port lain."]);
        }

        $from = $customer->port?->activityLabel();

        // One "moved" entry instead of the separate port and customer changes underneath.
        ActivityLog::muted(fn () => DB::transaction(function () use ($customer, $target, $odp) {
            $customer->port()->update(['customer_id' => null, 'status' => PortStatus::Kosong]);
            $target->update(['customer_id' => $customer->id, 'status' => PortStatus::Terpakai]);
            // The customer follows the ODP's area, so it stays visible to that area's team.
            $customer->update(['area_id' => $odp->area_id]);
        }));

        $customer->logActivity('moved', ['port' => [$from, $target->activityLabel()]]);

        return back();
    }
}
