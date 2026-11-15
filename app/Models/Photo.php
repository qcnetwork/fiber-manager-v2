<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
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
        // Only once the deletion is committed: a rolled-back delete (a dry run, a batch that failed) keeps its row, so it keeps its files.
        static::deleted(fn (Photo $photo) => DB::afterCommit(fn () => Storage::disk(self::DISK)->delete([$photo->path, $photo->thumb_path])));
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

    // Browsers keep photo files (they are served as immutable): a replaced image needs a new URL, so the URL names the file.
    protected function url(): Attribute
    {
        return Attribute::get(fn () => route('photos.file', [$this, 'v' => $this->version()]));
    }

    protected function thumbUrl(): Attribute
    {
        return Attribute::get(fn () => route('photos.file', [$this, 'thumb' => 1, 'v' => $this->version()]));
    }

    private function version(): string
    {
        return substr(md5((string) $this->path), 0, 8);
    }
}
