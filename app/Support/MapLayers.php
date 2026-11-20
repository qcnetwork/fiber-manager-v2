<?php

namespace App\Support;

/**
 * Which layers the network map starts with: the ticks in the layer menu (top right) when the map opens.
 * Each user sets their own in Pengaturan → Peta; anything not set falls back to these defaults.
 */
final class MapLayers
{
    public const BASES = ['peta', 'satelit'];

    /** @var array<string, bool|string> */
    public const DEFAULTS = [
        'base' => 'peta',
        'cables' => true,
        'pops' => true,
        'odcs' => true,
        'odps' => true,
        'joints' => true,
        'links' => false,
        'customers' => false,
        'cluster' => true,
    ];

    /**
     * Saved preferences merged over the defaults; unknown keys and wrong types are ignored.
     *
     * @param  array<string, mixed>|null  $saved
     * @return array<string, bool|string>
     */
    public static function resolve(?array $saved): array
    {
        $layers = self::DEFAULTS;

        foreach ($saved ?? [] as $key => $value) {
            if ($key === 'base') {
                if (in_array($value, self::BASES, true)) {
                    $layers['base'] = $value;
                }
            } elseif (array_key_exists($key, $layers) && is_bool($value)) {
                $layers[$key] = $value;
            }
        }

        return $layers;
    }
}
