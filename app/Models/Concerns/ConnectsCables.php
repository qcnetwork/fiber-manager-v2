<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use App\Models\Cable;
use App\Models\CoreSplice;
use App\Models\CoreTermination;

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

        static::deleted(function ($asset) {
            ActivityLog::muted(fn () => Cable::followEndpoint($asset, deleted: true));
            // Core splices made here and cores ending here go with the asset.
            CoreSplice::where('location_type', $asset->getMorphClass())->where('location_id', $asset->getKey())->delete();
            CoreTermination::at($asset)->delete();
        });
    }
}
