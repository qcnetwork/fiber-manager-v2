<?php

namespace App\Http\Controllers;

use App\Enums\PortStatus;
use App\Models\Customer;
use App\Models\OdpPort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OdpPortController extends Controller
{
    /**
     * Change a port's status and, when it is in use, the customer connected to it.
     */
    public function update(Request $request, OdpPort $port): RedirectResponse
    {
        // Ports have no area of their own; resolving the ODP through its area scope guards access.
        $odp = $port->odp()->firstOrFail();
        abort_unless($request->user()->canEditNetwork(), 403);

        $inUse = $request->input('status') === PortStatus::Terpakai->value;

        $data = $request->validate([
            'status' => ['required', Rule::enum(PortStatus::class)],
            'notes' => ['nullable', 'string', 'max:255'],
            'customer.name' => [Rule::requiredIf($inUse), 'nullable', 'string', 'max:255'],
            'customer.customer_code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('customers', 'customer_code')->ignore($port->customer_id),
            ],
            'customer.phone' => ['nullable', 'string', 'max:30'],
            'customer.address' => ['nullable', 'string', 'max:255'],
            'customer.package' => ['nullable', 'string', 'max:255'],
            'customer.ont_sn' => ['nullable', 'string', 'max:50'],
            'customer.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'customer.lng' => ['nullable', 'numeric', 'between:-180,180'],
        ], [
            'customer.name.required' => 'Nama pelanggan wajib diisi untuk port terpakai.',
        ]);

        DB::transaction(function () use ($port, $odp, $data, $inUse) {
            $customerId = null;

            if ($inUse) {
                $customer = $port->customer ?? new Customer(['area_id' => $odp->area_id]);
                $customer->fill($data['customer'])->save();
                $customerId = $customer->id;
            }

            $port->update([
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'customer_id' => $customerId,
            ]);
        });

        return back();
    }
}
