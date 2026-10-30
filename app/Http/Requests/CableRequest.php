<?php

namespace App\Http\Requests;

use App\Enums\AssetStatus;
use App\Enums\CableType;
use App\Models\Area;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canEditNetwork();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'area_id' => $this->user()->seesAllAreas() ? ['required', 'exists:areas,id'] : ['exclude'],
            'code' => ['nullable', 'string', 'max:100', Rule::unique('cables', 'code')->ignore($this->route('cable'))],
            'type' => ['required', Rule::enum(CableType::class)],
            'cores' => ['required', 'integer', Rule::in([1, 2, 4, 6, 8, 12, 24, 48, 72, 96, 144, 288])],
            'status' => ['required', Rule::enum(AssetStatus::class)],
            'path' => ['required', 'array', 'min:2', 'max:2000'],
            'path.*' => ['required', 'array', 'size:2'],
            'path.*.0' => ['required', 'numeric', 'between:-90,90'],
            'path.*.1' => ['required', 'numeric', 'between:-180,180'],
            'from_type' => ['nullable', 'required_with:from_id', Rule::in(['pop', 'odc', 'odp'])],
            'from_id' => ['nullable', 'integer', $this->endpointInArea('from')],
            'to_type' => ['nullable', 'required_with:to_id', Rule::in(['pop', 'odc', 'odp'])],
            'to_id' => ['nullable', 'integer', $this->endpointInArea('to')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'path.min' => 'Jalur kabel minimal terdiri dari 2 titik.',
        ];
    }

    /**
     * The POP/ODC/ODP a cable end is attached to must be visible to the user and in the cable's area (a POP is the area itself).
     */
    private function endpointInArea(string $end): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($end) {
            $class = Relation::getMorphedModel((string) $this->input("{$end}_type"));
            $asset = $class ? $class::find($value) : null;
            $areaId = $this->user()->seesAllAreas() ? (int) $this->input('area_id') : $this->user()->area_id;

            $assetArea = $asset instanceof Area ? $asset->id : $asset?->area_id;

            if (! $asset || (int) $assetArea !== $areaId) {
                $fail('Ujung kabel harus POP/ODC/ODP di area yang sama.');
            }
        };
    }
}
