<?php

namespace App\Services;

use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Turns a Google Maps link into coordinates, including short share links (maps.app.goo.gl) that only
 * reveal the location after their redirects are followed. Only Google hosts are ever requested.
 *
 * Precision tells how far the result can be trusted: "pin" is the place marker in the link,
 * "center" is just the middle of the map view the link was shared from.
 */
class GoogleMapsLink
{
    private const MAX_REDIRECTS = 6;

    /**
     * @return array{lat: float, lng: float, precision: 'pin'|'center'}|null
     */
    public function parse(string $text): ?array
    {
        $input = urldecode(trim($text));

        $patterns = [
            ['/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/', 'pin'], // place pin
            ['/[?&](?:q|query|ll|destination|daddr)=(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/', 'pin'],
            ['#/(?:place|search|dir)/(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)#', 'pin'],
            ['/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', 'center'], // map view center
            ['/[?&]center=(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/', 'center'],
        ];

        foreach ($patterns as [$pattern, $precision]) {
            if (preg_match($pattern, $input, $m) && $this->valid((float) $m[1], (float) $m[2])) {
                return ['lat' => (float) $m[1], 'lng' => (float) $m[2], 'precision' => $precision];
            }
        }

        return null;
    }

    /**
     * @return array{lat: float, lng: float, precision: 'pin'|'center'}
     *
     * @throws InvalidArgumentException with a message for the user
     */
    public function resolve(string $url): array
    {
        $current = trim($url);
        if (! $this->isGoogleUrl($current)) {
            throw new InvalidArgumentException('Hanya link Google Maps yang bisa dibaca.');
        }

        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            if ($found = $this->parse($current)) {
                return $found;
            }

            // Cookie consent page: the real link is in "continue".
            if (parse_url($current, PHP_URL_HOST) === 'consent.google.com') {
                parse_str((string) parse_url($current, PHP_URL_QUERY), $query);
                $current = (string) ($query['continue'] ?? '');
                if (! $this->isGoogleUrl($current)) {
                    break;
                }

                continue;
            }

            try {
                $response = Http::withoutRedirecting()
                    ->timeout(8)
                    // PHP on Windows often ships without a CA bundle; this finds the system one or falls back to a bundled copy.
                    ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; FiberManager/2.0)', 'Accept-Language' => 'id'])
                    ->get($current);
            } catch (ConnectionException) {
                throw new InvalidArgumentException('Link tidak bisa dibuka dari server (koneksi internet?). Masukkan koordinatnya langsung.');
            }

            $location = $response->header('Location');
            // The page body is not read: for a place name, Google shows a view near the server's own location.
            if (! $response->redirect() || ! $location) {
                break;
            }

            if (str_starts_with($location, '/')) {
                $location = parse_url($current, PHP_URL_SCHEME).'://'.parse_url($current, PHP_URL_HOST).$location;
            }
            if (! $this->isGoogleUrl($location)) {
                break;
            }
            $current = $location;
        }

        throw new InvalidArgumentException(
            'Link ini tidak berisi titik koordinat. Buka link-nya di Google Maps, tahan titik lokasi, lalu salin angka koordinatnya ke sini.'
        );
    }

    public function isGoogleUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($scheme, ['http', 'https'], true)
            && (in_array($host, ['maps.app.goo.gl', 'goo.gl', 'share.google'], true) || preg_match('/(^|\.)google\.(com|co\.id)$/', $host) === 1);
    }

    private function valid(float $lat, float $lng): bool
    {
        return abs($lat) <= 90 && abs($lng) <= 180 && ! ($lat == 0.0 && $lng == 0.0);
    }
}
