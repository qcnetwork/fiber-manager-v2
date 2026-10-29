<?php

namespace App\Models\Concerns;

use App\Models\Cable;

/**
 * For assets that cables can start or end at: moving the asset drags the cable ends along.
 */
trait ConnectsCables
{
    public static function bootConnectsCables(): void
    {
        static::updated(function ($asset) {
            if ($asset->wasChanged(['lat', 'lng'])) {
                Cable::followEndpoint($asset);
            }
        });

        static::deleted(fn ($asset) => Cable::followEndpoint($asset, deleted: true));
    }
}
