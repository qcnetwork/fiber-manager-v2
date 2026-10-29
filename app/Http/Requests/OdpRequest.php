<?php

namespace App\Http\Requests;

use App\Models\Odc;
use Closure;
use Illuminate\Validation\Rule;

class OdpRequest extends NetworkAssetRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $odp = $this->route('odp');
        $odc = $this->filled('odc_id') ? Odc::find($this->input('odc_id')) : null;

        return [
            ...$this->assetRules(),
            'code' => ['required', 'string', 'max:100', Rule::unique('odps', 'code')->ignore($odp)],
            'pole_number' => ['nullable', 'string', 'max:50'],
            'capacity' => ['required', 'integer', 'min:1', 'max:64'],
            'odc_id' => [
                'nullable',
                function (string $attribute, mixed $value, Closure $fail) use ($odc) {
                    if (! $odc || (int) $odc->area_id !== $this->targetAreaId()) {
                        $fail('ODC tidak ditemukan di area ini.');
                    }
                },
            ],
            'odc_port' => [
                'nullable',
                'required_with:odc_id',
                'integer',
                'min:1',
                'max:'.($odc?->capacity ?? 1024),
                Rule::unique('odps', 'odc_port')->where('odc_id', $this->input('odc_id'))->ignore($odp),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'odc_port.unique' => 'Port ODC ini sudah dipakai ODP lain.',
        ];
    }
}
