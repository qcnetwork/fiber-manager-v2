<?php

namespace App\Http\Requests;

use App\Models\OltPort;
use Closure;
use Illuminate\Validation\Rule;

class OdcRequest extends NetworkAssetRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->assetRules(),
            'code' => ['required', 'string', 'max:100', Rule::unique('odcs', 'code')->ignore($this->route('odc'))],
            'capacity' => ['required', 'integer', 'min:1', 'max:1024'],
            'olt_port_id' => ['nullable', 'integer', $this->visiblePort()],
        ];
    }

    /**
     * The uplink must be a port of an OLT the user can see; it may sit at the POP of another area.
     */
    private function visiblePort(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            // The OLT relation goes through the area scope, so OLTs the user can't see resolve to null.
            $olt = OltPort::find($value)?->olt()->first();

            if (! $olt) {
                $fail('Port OLT tidak ditemukan.');
            }
        };
    }
}
