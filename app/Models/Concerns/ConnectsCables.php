<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use App\Models\Cable;

/**
 * For assets that cables can start or end at: moving the asset drags the cable ends along.
 */
trait ConnectsCables
{
    public static function bootConnectsCables(): void
    {
        static::updated(function ($asset) {
            // POPs (areas) keep their location in center_lat/center_lng.
            if ($asset->wasChanged(['lat', 'lng', 'center_lat', 'center_lng'])) {
                // Cable ends following along are part of the asset's own change, not separate edits.
                ActivityLog::muted(fn () => Cable::followEndpoint($asset));
            }
        });

        static::deleted(fn ($asset) => ActivityLog::muted(fn () => Cable::followEndpoint($asset, deleted: true)));
    }
}
