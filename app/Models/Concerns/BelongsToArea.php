<?php

namespace App\Models\Concerns;

use App\Models\Area;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Restricts every query to the signed-in user's area, unless they are a super admin.
 */
trait BelongsToArea
{
    public static function bootBelongsToArea(): void
    {
        static::addGlobalScope('area', function (Builder $query) {
            $user = auth()->user();

            if ($user && ! $user->seesAllAreas()) {
                $query->where($query->getModel()->qualifyColumn('area_id'), $user->area_id);
            }
        });

        static::creating(function ($model) {
            $user = auth()->user();

            if ($user && ! $user->seesAllAreas()) {
                $model->area_id = $user->area_id;
            }
        });
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
}
