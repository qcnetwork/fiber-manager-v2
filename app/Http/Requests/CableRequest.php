<?php

namespace App\Http\Requests;

use App\Support\NetworkLocation;
use App\Enums\AssetStatus;
use App\Enums\CableType;
use App\Models\Area;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CableRequest extends FormRequest
{
    /** What a cable end or pass-through point can attach to (morph aliases). */
    public const ASSET_TYPES = ['pop', 'odc', 'odp', 'joint'];

    /** A cable end may also be a customer's house: the drop cable from the ODP. */
    public const END_TYPES = [...self::ASSET_TYPES, 'customer'];

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
            'tube_size' => ['nullable', 'integer', Rule::in([1, 2, 4, 6, 8, 12, 24])],
            'status' => ['required', Rule::enum(AssetStatus::class)],
            'path' => ['required', 'array', 'min:2', 'max:2000'],
            'path.*' => ['required', 'array', 'size:2'],
            'path.*.0' => ['required', 'numeric', 'between:-90,90'],
            'path.*.1' => ['required', 'numeric', 'between:-180,180'],
            'from_type' => ['nullable', 'required_with:from_id', Rule::in(self::END_TYPES)],
            'from_id' => ['nullable', 'integer', $this->assetInArea()],
            'to_type' => ['nullable', 'required_with:to_id', Rule::in(self::END_TYPES)],
            'to_id' => ['nullable', 'integer', $this->assetInArea()],
            // POPs/ODCs/ODPs the cable passes through, each pinned to a vertex of the path.
            'stops' => ['nullable', 'array', 'max:500'],
            'stops.*.vertex' => ['required', 'integer', 'min:0', 'distinct'],
            'stops.*.type' => ['required', Rule::in(self::ASSET_TYPES)],
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
            'stops.*.type.in' => 'Pelanggan hanya bisa jadi ujung kabel drop, bukan titik yang dilewati.',
        ];
    }

    /**
     * Only a drop cable runs to a customer's house.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function ($validator) {
                if ($this->input('type') === CableType::Drop->value) {
                    return;
                }
                foreach (['from_type', 'to_type'] as $end) {
                    if ($this->input($end) === 'customer') {
                        $validator->errors()->add($end, 'Hanya kabel drop yang bisa disambung ke pelanggan.');
                    }
                }
            },
        ];
    }

    /**
     * A POP/ODC/ODP/joint (or, at an end, a customer) a cable is attached to must exist and be visible to the user.
     * It may lie in another area than the cable: cables link areas together.
     */
    private function assetInArea(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            // from_id goes with from_type, stops.3.id with stops.3.type.
            $type = (string) $this->input(preg_replace('/id$/', 'type', $attribute));

            // Assets the user can't see (other areas' POPs included) don't count.
            if (! NetworkLocation::find($type, $value)) {
                $fail(str_starts_with($attribute, 'stops') ? 'Titik sambung tidak ditemukan.' : 'Aset di ujung kabel tidak ditemukan.');
            }
        };
    }
}
