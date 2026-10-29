<?php

namespace App\Http\Requests;

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
        ];
    }
}
