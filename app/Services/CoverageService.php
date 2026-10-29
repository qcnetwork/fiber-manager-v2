<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Models\Odp;
use Illuminate\Support\Collection;

/**
 * Answers "can we connect a customer here?" from the ODPs around a point.
 */
class CoverageService
{
    /** How far to look for the closest free ODP when a point is not covered, for context. */
    private const NEAREST_OUTSIDE_LIMIT_M = 3000;

    public function radius(): int
    {
        return (int) config('odp.coverage_radius');
    }

    /**
     * ODPs within the radius (any status except broken), closest first, with `distance`, `used_ports` and `free_ports`.
     *
     * @return Collection<int, Odp>
     */
    public function odpsNear(float $lat, float $lng, int $radius): Collection
    {
        $box = GeoService::boundingBox($lat, $lng, $radius);

        return Odp::query()
            ->withPortUsage()
            ->whereBetween('lat', [$box['minLat'], $box['maxLat']])
            ->whereBetween('lng', [$box['minLng'], $box['maxLng']])
            ->where('status', '!=', AssetStatus::Rusak)
            ->get(['id', 'code', 'name', 'lat', 'lng', 'capacity', 'status', 'address'])
            ->each(fn (Odp $odp) => $odp->distance = (int) round(GeoService::distance($lat, $lng, $odp->lat, $odp->lng)))
            ->filter(fn (Odp $odp) => $odp->distance <= $radius)
            ->sortBy('distance')
            ->values();
    }

    /**
     * @return array{
     *     status: 'tercover'|'penuh'|'tidak_tercover',
     *     radius: int,
     *     available: list<array<string, mixed>>,
     *     full: list<array<string, mixed>>,
     *     planned: list<array<string, mixed>>,
     *     nearest_outside: array<string, mixed>|null
     * }
     */
    public function check(float $lat, float $lng): array
    {
        $radius = $this->radius();
        $near = $this->odpsNear($lat, $lng, $radius);

        $active = $near->where('status', AssetStatus::Aktif);
        $available = $active->where('free_ports', '>', 0);
        $full = $active->where('free_ports', 0);
        // ODPs that are only planned don't make a location sellable yet, but sales should know about them.
        $planned = $near->where('status', AssetStatus::Rencana);

        $nearestOutside = null;
        if ($available->isEmpty()) {
            $nearestOutside = $this->odpsNear($lat, $lng, self::NEAREST_OUTSIDE_LIMIT_M)
                ->first(fn (Odp $odp) => $odp->status === AssetStatus::Aktif && $odp->free_ports > 0);
        }

        return [
            'status' => $available->isNotEmpty() ? 'tercover' : ($full->isNotEmpty() ? 'penuh' : 'tidak_tercover'),
            'radius' => $radius,
            'available' => $this->present($available->take(5)),
            'full' => $this->present($full->take(5)),
            'planned' => $this->present($planned->take(5)),
            'nearest_outside' => $nearestOutside ? $this->present(collect([$nearestOutside]))[0] : null,
        ];
    }

    /**
     * Only what sales needs: no customer data.
     *
     * @return list<array<string, mixed>>
     */
    private function present(Collection $odps): array
    {
        return $odps->map(fn (Odp $odp) => [
            ...$odp->only('id', 'code', 'lat', 'lng', 'capacity', 'distance', 'used_ports', 'free_ports'),
            'status' => $odp->status->value,
        ])->values()->all();
    }
}
