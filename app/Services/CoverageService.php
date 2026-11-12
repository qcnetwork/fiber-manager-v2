<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Models\Odp;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Answers "can we connect a customer here?" from the ODPs around a point.
 */
class CoverageService
{
    /** How far to look for the closest free ODP when a point is not covered, for context. */
    private const NEAREST_OUTSIDE_LIMIT_M = 3000;

    public function __construct(private RoutingService $routing) {}

    /** Limits for the radius the super admin can set, in meters. */
    public const MIN_RADIUS = 10;

    public const MAX_RADIUS = 2000;

    /**
     * Maximum ODP–customer distance: the super admin's setting, else the configured default.
     */
    public function radius(): int
    {
        return (int) Setting::get('coverage_radius', config('odp.coverage_radius'));
    }

    public function setRadius(int $meters): void
    {
        Setting::put('coverage_radius', $meters);
    }

    /**
     * Whether distances can follow the roads at all (a routing server is configured).
     */
    public function routingAvailable(): bool
    {
        return filled(config('odp.routing.url'));
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
    public function check(float $lat, float $lng, bool $byRoad = true): array
    {
        $radius = $this->radius();
        $near = $this->odpsNear($lat, $lng, $radius);

        // A road is never shorter than the straight line, so the ODPs within the radius are the only candidates.
        // Straight lines when the user turned road distances off (faster).
        $byRoad = $byRoad ? $this->measureByRoad($lat, $lng, $near) : null;
        if ($byRoad !== null) {
            $near = $byRoad->filter(fn (Odp $odp) => $odp->distance <= $radius)->sortBy('distance')->values();
        }

        $active = $near->where('status', AssetStatus::Aktif);
        $available = $active->where('free_ports', '>', 0);
        $full = $active->where('free_ports', 0);
        // ODPs that are only planned don't make a location sellable yet, but sales should know about them.
        $planned = $near->where('status', AssetStatus::Rencana);

        $nearestOutside = null;
        if ($available->isEmpty()) {
            // Straight line beyond the radius; ODPs measured by road keep their road distance.
            $measured = $byRoad?->keyBy('id') ?? collect();
            $nearestOutside = $this->odpsNear($lat, $lng, self::NEAREST_OUTSIDE_LIMIT_M)
                ->map(fn (Odp $odp) => $measured[$odp->id] ?? $odp)
                ->filter(fn (Odp $odp) => $byRoad === null || $odp->distance > $radius)
                ->sortBy('distance')
                ->first(fn (Odp $odp) => $odp->status === AssetStatus::Aktif && $odp->free_ports > 0);
        }

        // The road path to the ODP the page highlights.
        $best = $available->first() ?? $full->first();
        if ($byRoad !== null && $best) {
            $best->route = $this->routing->route($lat, $lng, $best->lat, $best->lng);
        }

        return [
            'status' => $available->isNotEmpty() ? 'tercover' : ($full->isNotEmpty() ? 'penuh' : 'tidak_tercover'),
            'radius' => $radius,
            'distance_mode' => $byRoad !== null ? 'jalan' : 'lurus',
            'available' => $this->present($available->take(5)),
            'full' => $this->present($full->take(5)),
            'planned' => $this->present($planned->take(5)),
            'nearest_outside' => $nearestOutside ? $this->present(collect([$nearestOutside]))[0] : null,
        ];
    }

    /**
     * The ODPs with `distance` along the roads (`straight_distance` keeps the straight line), or null when routing is unavailable.
     *
     * ODPs no road reaches are dropped. The closest ones by straight line are measured; the rest are left out.
     *
     * @param  Collection<int, Odp>  $odps
     * @return Collection<int, Odp>|null
     */
    private function measureByRoad(float $lat, float $lng, Collection $odps): ?Collection
    {
        if (! $this->routing->enabled()) {
            return null;
        }

        $candidates = $odps->take((int) config('odp.routing.max_candidates'))->values();
        $distances = $this->routing->distances($lat, $lng, $candidates->map(fn (Odp $odp) => [$odp->lat, $odp->lng])->all());
        if ($distances === null) {
            return null;
        }

        return $candidates
            ->each(function (Odp $odp, int $i) use ($distances) {
                $odp->straight_distance = $odp->distance;
                $odp->distance = $distances[$i];
            })
            ->filter(fn (Odp $odp) => $odp->distance !== null)
            ->values();
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
            'straight_distance' => $odp->straight_distance ?? null,
            'route' => $odp->route ?? null,
        ])->values()->all();
    }
}
