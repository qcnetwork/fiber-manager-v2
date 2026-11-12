<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Photo extends Model
{
    use LogsActivity;

    protected $fillable = ['user_id', 'path', 'thumb_path', 'caption', 'width', 'height', 'size'];

    protected $appends = ['url', 'thumb_url'];

    protected $hidden = ['path', 'thumb_path'];

    /** Private disk: photos are served only to logged-in users who may see their owner. */
    public const DISK = 'photos';

    protected static function booted(): void
    {
        static::deleted(fn (Photo $photo) => Storage::disk(self::DISK)->delete([$photo->path, $photo->thumb_path]));
    }

    public function photoable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activityLabel(): string
    {
        $owner = $this->photoable;
        $label = $owner && method_exists($owner, 'activityLabel') ? $owner->activityLabel() : '';

        return trim("Foto {$label}".($this->caption ? " ({$this->caption})" : ''));
    }

    public function activityAreaId(): ?int
    {
        $owner = $this->photoable;

        return $owner && method_exists($owner, 'activityAreaId') ? $owner->activityAreaId() : null;
    }

    /**
     * The label already says which photo; its file details mean nothing to a reader.
     */
    protected function activityIgnored(): array
    {
        return ['photoable_type', 'photoable_id', 'user_id', 'path', 'thumb_path', 'width', 'height', 'size'];
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn () => route('photos.file', $this));
    }

    protected function thumbUrl(): Attribute
    {
        return Attribute::get(fn () => route('photos.file', [$this, 'thumb' => 1]));
    }
}
