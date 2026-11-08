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
            'from_id' => ['nullable', 'integer', $this->assetInArea()],
            'to_type' => ['nullable', 'required_with:to_id', Rule::in(['pop', 'odc', 'odp'])],
            'to_id' => ['nullable', 'integer', $this->assetInArea()],
            // POPs/ODCs/ODPs the cable passes through, each pinned to a vertex of the path.
            'stops' => ['nullable', 'array', 'max:500'],
            'stops.*.vertex' => ['required', 'integer', 'min:0', 'distinct'],
            'stops.*.type' => ['required', Rule::in(['pop', 'odc', 'odp'])],
            'stops.*.id' => ['required', 'integer', $this->assetInArea()],
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
     * A POP/ODC/ODP a cable end or pass-through point is attached to must be visible to the user and in the cable's area (a POP is the area itself).
     */
    private function assetInArea(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            // from_id goes with from_type, stops.3.id with stops.3.type.
            $class = Relation::getMorphedModel((string) $this->input(preg_replace('/id$/', 'type', $attribute)));
            $asset = $class ? $class::find($value) : null;
            $areaId = $this->user()->seesAllAreas() ? (int) $this->input('area_id') : $this->user()->area_id;

            $assetArea = $asset instanceof Area ? $asset->id : $asset?->area_id;

            if (! $asset || (int) $assetArea !== $areaId) {
                $fail(str_starts_with($attribute, 'stops') ? 'Titik sambung harus POP/ODC/ODP di area yang sama.' : 'Ujung kabel harus POP/ODC/ODP di area yang sama.');
            }
        };
    }
}
