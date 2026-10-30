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
            'olt_port_id' => ['nullable', 'integer', $this->portAtOwnPop()],
        ];
    }

    /**
     * The uplink must be a port of an OLT at the ODC's own POP (area).
     */
    private function portAtOwnPop(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            // The OLT relation goes through the area scope, so OLTs the user can't see resolve to null.
            $olt = OltPort::find($value)?->olt()->first();

            if (! $olt || $olt->area_id !== $this->targetAreaId()) {
                $fail('Port OLT harus milik OLT di POP yang sama dengan ODC.');
            }
        };
    }
}
