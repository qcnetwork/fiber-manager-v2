<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Photo extends Model
{
    protected $fillable = ['user_id', 'path', 'thumb_path', 'caption', 'width', 'height', 'size'];

    protected $appends = ['url', 'thumb_url'];

    protected $hidden = ['path', 'thumb_path'];

    protected static function booted(): void
    {
        static::deleted(fn (Photo $photo) => Storage::disk('public')->delete([$photo->path, $photo->thumb_path]));
    }

    public function photoable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn () => asset('storage/'.$this->path));
    }

    protected function thumbUrl(): Attribute
    {
        return Attribute::get(fn () => asset('storage/'.$this->thumb_path));
    }
}
