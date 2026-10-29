<?php

namespace App\Http\Requests;

use App\Enums\AssetStatus;
use Illuminate\Foundation\Http\FormRequest;
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
        ];
    }

    /**
     * Area the asset will belong to once saved.
     */
    protected function targetAreaId(): ?int
    {
        return $this->user()->seesAllAreas() ? (int) $this->input('area_id') : $this->user()->area_id;
    }
}
