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
}
