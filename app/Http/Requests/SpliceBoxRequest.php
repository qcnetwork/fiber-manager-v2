<?php

namespace App\Http\Requests;

use App\Enums\SpliceBoxType;
use Illuminate\Validation\Rule;

class SpliceBoxRequest extends NetworkAssetRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->assetRules(),
            'type' => ['required', Rule::enum(SpliceBoxType::class)],
            'code' => ['required', 'string', 'max:100', Rule::unique('splice_boxes', 'code')->ignore($this->route('joint'))],
            'pole_number' => ['nullable', 'string', 'max:50'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'splitter_ratio' => ['exclude'],
        ];
    }
}
