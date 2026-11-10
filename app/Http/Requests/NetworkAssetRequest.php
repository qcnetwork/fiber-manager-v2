<?php

namespace App\Http\Requests;

use App\Enums\AssetStatus;
use App\Models\Cable;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\SpliceBox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Fields shared by every asset that sits on the map (ODC, ODP).
 */
abstract class NetworkAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canEditNetwork();
    }

    /**
     * @return array<string, mixed>
     */
    protected function assetRules(): array
    {
        return [
            // Users without an area pick it; everyone else is pinned to their own by BelongsToArea.
            'area_id' => $this->user()->seesAllAreas() ? ['required', 'exists:areas,id'] : ['exclude'],
            'name' => ['nullable', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:255'],
            'splitter_ratio' => ['nullable', Rule::in(['1:2', '1:4', '1:8', '1:16', '1:32'])],
            'status' => ['required', Rule::enum(AssetStatus::class)],
            'installed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Cables passing nearby to connect the asset to (it becomes a cable end or pass-through point).
            'connect_cables' => ['nullable', 'array', 'max:20'],
            'connect_cables.*' => ['integer', Rule::exists('cables', 'id')->where('area_id', $this->targetAreaId())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'connect_cables.*.exists' => 'Kabel yang dipilih tidak ada di area ini.',
        ];
    }

    /**
     * Validated asset fields, without the cables to connect.
     *
     * @return array<string, mixed>
     */
    public function assetData(): array
    {
        return Arr::except($this->validated(), 'connect_cables');
    }

    /**
     * Connects the saved asset to the cables the user ticked.
     */
    public function connectCables(Odc|Odp|SpliceBox $asset): void
    {
        $ids = $this->validated('connect_cables') ?? [];
        if ($ids) {
            Cable::whereKey($ids)->get()->each(fn (Cable $cable) => $cable->connect($asset));
        }
    }

    /**
     * Area the asset will belong to once saved.
     */
    protected function targetAreaId(): ?int
    {
        return $this->user()->seesAllAreas() ? (int) $this->input('area_id') : $this->user()->area_id;
    }
}
