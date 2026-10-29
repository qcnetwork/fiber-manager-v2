<?php

namespace App\Models\Concerns;

use App\Models\Photo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasPhotos
{
    public static function bootHasPhotos(): void
    {
        // Delete one by one so each photo removes its files from storage.
        static::deleting(fn ($model) => $model->photos->each->delete());
    }

    public function photos(): MorphMany
    {
        return $this->morphMany(Photo::class, 'photoable')->latest();
    }
}
