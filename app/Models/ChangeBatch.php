<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The drafts a technician sent together. A super admin or the area's admin approves or rejects them as one.
 */
class ChangeBatch extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['user_id', 'area_id', 'status', 'note', 'submitted_at', 'reviewer_id', 'reviewed_at', 'review_note', 'seen_at'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
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

    /**
     * The changes in the order they were made; later ones may build on earlier ones.
     */
    public function items(): HasMany
    {
        return $this->hasMany(ChangeRequest::class, 'batch_id')->orderBy('sequence')->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * Technicians see their own batches; approvers those of their area (super admins: all).
     * A batch spanning several areas has no area and only approvers of all areas see it.
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
     * Batches the user may approve or reject.
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
     * Removes the photos of its changes still waiting on disk.
     */
    public function deleteFiles(): void
    {
        $this->items->each->deleteFiles();
    }
}
