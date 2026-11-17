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

        // A port can be in use by a customer nobody has recorded yet (e.g. found connected in the field).
        $withCustomer = $request->input('status') === PortStatus::Terpakai->value && ! $request->boolean('customer_unknown');

        $data = $request->validate([
            'status' => ['required', Rule::enum(PortStatus::class)],
            'customer_unknown' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
            'customer.name' => [Rule::requiredIf($withCustomer), 'nullable', 'string', 'max:255'],
            'customer.customer_code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('customers', 'customer_code')->ignore($port->customer_id),
            ],
            'customer.phone' => ['nullable', 'string', 'max:30'],
            'customer.address' => ['nullable', 'string', 'max:255'],
            'customer.package' => ['nullable', 'string', 'max:255'],
            'customer.ont_sn' => ['nullable', 'string', 'max:50'],
            'customer.lat' => ['nullable', 'required_with:customer.lng', 'numeric', 'between:-90,90'],
            'customer.lng' => ['nullable', 'required_with:customer.lat', 'numeric', 'between:-180,180'],
        ], [
            'customer.name.required' => 'Nama pelanggan wajib diisi, atau centang "Pelanggan belum diketahui".',
            'customer.lat.required_with' => 'Isi latitude dan longitude, atau kosongkan keduanya.',
            'customer.lng.required_with' => 'Isi latitude dan longitude, atau kosongkan keduanya.',
        ]);

        DB::transaction(function () use ($port, $odp, $data, $withCustomer) {
            $customerId = null;

            if ($withCustomer) {
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
