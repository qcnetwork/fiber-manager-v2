<?php

namespace App\Services;

class GeoService
{
    public const EARTH_RADIUS_M = 6371000;

    /**
     * Great-circle distance between two coordinates in meters (haversine).
     */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Total length in meters of a polyline given as [[lat, lng], ...].
     *
     * @param  list<array{0: float, 1: float}>  $path
     */
    public static function pathLength(array $path): float
    {
        $length = 0.0;
        for ($i = 1; $i < count($path); $i++) {
            $length += self::distance($path[$i - 1][0], $path[$i - 1][1], $path[$i][0], $path[$i][1]);
        }

        return $length;
    }

    /**
     * Box that contains every point within the radius, used to pre-filter rows on the lat/lng index.
     *
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    public static function boundingBox(float $lat, float $lng, float $radiusM): array
    {
        $dLat = rad2deg($radiusM / self::EARTH_RADIUS_M);
        $dLng = rad2deg($radiusM / (self::EARTH_RADIUS_M * max(cos(deg2rad($lat)), 0.000001)));

        return [
            'minLat' => $lat - $dLat,
            'maxLat' => $lat + $dLat,
            'minLng' => $lng - $dLng,
            'maxLng' => $lng + $dLng,
        ];
    }

    /**
     * The point on a polyline closest to a coordinate: which segment (index of its first vertex), how far along it
     * (0 = at that vertex, 1 = at the next one), and the distance in meters. Uses a flat projection around the
     * coordinate, which is accurate at the tens-of-meters scale this is used for.
     *
     * @param  list<array{0: float, 1: float}>  $path
     * @return array{segment: int, t: float, distance: float}
     */
    public static function nearestOnPath(float $lat, float $lng, array $path): array
    {
        $mPerDegLat = deg2rad(1) * self::EARTH_RADIUS_M;
        $mPerDegLng = $mPerDegLat * cos(deg2rad($lat));
        $project = fn (array $p) => [($p[1] - $lng) * $mPerDegLng, ($p[0] - $lat) * $mPerDegLat];

        $best = ['segment' => 0, 't' => 0.0, 'distance' => INF];
        for ($i = 0; $i < count($path) - 1; $i++) {
            [$ax, $ay] = $project($path[$i]);
            [$bx, $by] = $project($path[$i + 1]);
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $lengthSq = $dx * $dx + $dy * $dy;
            // The coordinate is the origin of the projection.
            $t = $lengthSq > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $lengthSq)) : 0.0;
            $distance = hypot($ax + $t * $dx, $ay + $t * $dy);
            if ($distance < $best['distance']) {
                $best = ['segment' => $i, 't' => $t, 'distance' => $distance];
            }
        }

        return $best;
    }
}
