<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasPhotos
{
    public static function bootHasPhotos(): void
    {
        // Delete one by one so each photo removes its files from storage. The owner's own log entry covers them.
        static::deleting(fn ($model) => ActivityLog::muted(fn () => $model->photos->each->delete()));
    }

    public function photos(): MorphMany
    {
        return $this->morphMany(Photo::class, 'photoable')->latest();
    }
}
