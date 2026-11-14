<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One change by a technician: first a draft only they see (shown on their map), then part of a batch
 * that waits for approval.
 *
 * It keeps the original request (route, parameters, payload, uploaded files) so it can be replayed
 * through the same controller, plus a preview of what it changes. A record a draft creates is referred
 * to by other drafts as the negative id of that draft (token), until the batch is applied.
 */
class ChangeRequest extends Model
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** Where uploaded photos wait, on the photos disk. */
    public const PENDING_DIR = 'pending';

    protected $fillable = [
        'batch_id', 'sequence', 'user_id', 'area_id', 'action', 'subject_type', 'subject_id', 'subject_label', 'route_name',
        'route_params', 'method', 'payload', 'files', 'preview', 'base_updated_at', 'status',
    ];

    protected $hidden = ['files', 'route_params'];

    protected function casts(): array
    {
        return [
            'route_params' => 'array',
            'payload' => 'array',
            'files' => 'array',
            'preview' => 'array',
            'base_updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (ChangeRequest $request) => $request->deleteFiles());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ChangeBatch::class, 'batch_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * How other drafts refer to the record this draft creates.
     */
    public function token(): int
    {
        return -$this->id;
    }

    /**
     * The user's drafts not sent yet, in the order they were made.
     */
    public function scopeDraftsOf(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id)->where('status', self::DRAFT)->orderBy('sequence')->orderBy('id');
    }

    /**
     * Whether the asset changed after the draft was made, so applying it may overwrite newer data.
     */
    public function conflict(): ?string
    {
        if (! in_array($this->status, [self::DRAFT, self::PENDING], true) || $this->subject_id === null || ! in_array($this->action, ['updated', 'deleted'], true)) {
            return null;
        }

        $class = array_search($this->subject_type, ActivityLog::TYPES, true);
        if (! $class) {
            return null;
        }

        $current = $class::withoutGlobalScopes()->find($this->subject_id);
        if (! $current) {
            return 'Data ini sudah dihapus sejak request dibuat.';
        }
        if ($this->base_updated_at && $current->updated_at && $current->updated_at->gt($this->base_updated_at)) {
            return 'Data ini sudah diubah orang lain sejak request dibuat ('.$current->updated_at->format('d/m/Y H:i').'). Periksa sebelum menyetujui.';
        }

        return null;
    }

    /**
     * Removes the photos still waiting on disk.
     */
    public function deleteFiles(): void
    {
        $paths = collect($this->files ?? [])->pluck('path')->filter()->all();
        if ($paths) {
            Storage::disk(Photo::DISK)->delete($paths);
        }
    }
}
