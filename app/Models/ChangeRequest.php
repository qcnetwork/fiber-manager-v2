<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A change a technician sent that waits for a super admin or the area's admin to approve it.
 *
 * It keeps the original request (route, parameters, payload, uploaded files) so approving replays it
 * through the same controller, plus a preview of what it would change.
 */
class ChangeRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** Where uploaded photos wait, on the photos disk. */
    public const PENDING_DIR = 'pending';

    protected $fillable = [
        'user_id', 'area_id', 'action', 'subject_type', 'subject_id', 'subject_label', 'route_name', 'route_params',
        'method', 'payload', 'files', 'preview', 'base_updated_at', 'status', 'reviewer_id', 'reviewed_at', 'review_note', 'seen_at',
    ];

    protected $hidden = ['payload', 'files', 'route_params'];

    protected function casts(): array
    {
        return [
            'route_params' => 'array',
            'payload' => 'array',
            'files' => 'array',
            'preview' => 'array',
            'base_updated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'seen_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * Technicians see their own requests; approvers see the ones in their area (super admins: all).
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->canApprove()) {
            if (! $user->seesAllAreas()) {
                $query->where('area_id', $user->area_id);
            }

            return;
        }

        $query->where('user_id', $user->id);
    }

    /**
     * Requests the user may approve or reject.
     */
    public function scopeReviewableBy(Builder $query, User $user): void
    {
        $user->canApprove() ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }

    /**
     * Whether the asset changed after the request was made, so the request may overwrite newer data.
     */
    public function conflict(): ?string
    {
        if (! $this->isPending() || $this->subject_id === null || ! in_array($this->action, ['updated', 'deleted'], true)) {
            return null;
        }

        $class = array_search($this->subject_type, ActivityLog::TYPES, true);
        if (! $class) {
            return null;
        }

        $current = $class::withoutGlobalScopes()->find($this->subject_id);
        if (! $current) {
            return 'Data ini sudah dihapus sejak request dikirim.';
        }
        if ($this->base_updated_at && $current->updated_at && $current->updated_at->gt($this->base_updated_at)) {
            return 'Data ini sudah diubah orang lain sejak request dikirim ('.$current->updated_at->format('d/m/Y H:i').'). Periksa sebelum menyetujui.';
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
