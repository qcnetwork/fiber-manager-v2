<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Distances and paths along the roads, from an OSRM server (OpenStreetMap data).
 *
 * Every method returns null when routing is off or the server fails, so callers fall back to straight lines.
 */
class RoutingService
{
    private const USER_AGENT = 'FiberManager/2.0 (QCNetwork ISP coverage check)';

    private const CACHE_TTL = 86400;

    private const DOWN_KEY = 'routing:down';

    private const DOWN_TTL = 300;

    public function enabled(): bool
    {
        return filled(config('odp.routing.url')) && ! Cache::has(self::DOWN_KEY);
    }

    /**
     * Road distance in meters from one point to each target, in the targets' order (null where unreachable).
     *
     * Includes the short walk from each point to the nearest road, since neither the customer nor the ODP sits exactly on it.
     *
     * @param  list<array{0: float, 1: float}>  $targets  [lat, lng] pairs
     * @return list<int|null>|null
     */
    public function distances(float $lat, float $lng, array $targets): ?array
    {
        if (! $targets) {
            return [];
        }

        $data = $this->request('table', [[$lat, $lng], ...$targets], ['sources' => 0, 'annotations' => 'distance']);
        if (! $data || ! isset($data['distances'][0])) {
            return null;
        }

        $fromRoad = (float) ($data['sources'][0]['distance'] ?? 0);

        // Destination 0 is the source itself.
        return array_map(function (int $i) use ($data, $fromRoad) {
            $along = $data['distances'][0][$i + 1] ?? null;

            return $along === null ? null : (int) round($fromRoad + $along + (float) ($data['destinations'][$i + 1]['distance'] ?? 0));
        }, array_keys($targets));
    }

    /**
     * The road path between two points as [lat, lng] pairs, joined to both points.
     *
     * @return list<array{0: float, 1: float}>|null
     */
    public function route(float $lat, float $lng, float $toLat, float $toLng): ?array
    {
        $data = $this->request('route', [[$lat, $lng], [$toLat, $toLng]], ['overview' => 'full', 'geometries' => 'geojson']);
        $coordinates = $data['routes'][0]['geometry']['coordinates'] ?? null;
        if (! $coordinates) {
            return null;
        }

        $path = array_map(fn (array $c) => [(float) $c[1], (float) $c[0]], $coordinates);

        return [[$lat, $lng], ...$path, [$toLat, $toLng]];
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points  [lat, lng] pairs
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function request(string $service, array $points, array $query): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        // OSRM wants lng,lat; rounding to ~1 m keeps the cache useful for repeated checks of the same spot.
        $coords = implode(';', array_map(fn (array $p) => round($p[1], 5).','.round($p[0], 5), $points));
        $url = rtrim(config('odp.routing.url'), '/')."/{$service}/v1/driving/{$coords}";
        $key = 'routing:'.md5($url.'?'.http_build_query($query));

        if (Cache::has($key)) {
            return Cache::get($key);
        }

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(config('odp.routing.timeout'))
                ->acceptJson()
                ->get($url, $query);
        } catch (Throwable $e) {
            // Unreachable or too slow: skip routing for a while instead of making every check wait for the timeout.
            Cache::put(self::DOWN_KEY, true, self::DOWN_TTL);
            Log::warning('Routing request failed', ['service' => $service, 'error' => $e->getMessage()]);

            return null;
        }

        $data = $response->json();
        if (! $response->successful() || ($data['code'] ?? null) !== 'Ok') {
            // Not cached, so the next check tries again.
            Log::warning('Routing request refused', ['service' => $service, 'status' => $response->status(), 'code' => $data['code'] ?? null]);

            return null;
        }

        Cache::put($key, $data, self::CACHE_TTL);

        return $data;
    }
}
