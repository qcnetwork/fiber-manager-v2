<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A core of one cable spliced to a core of another at a joint box/rosette, ODC, ODP or POP.
 */
class CoreSplice extends Model
{
    protected $fillable = ['location_type', 'location_id', 'core_a_id', 'core_a_side', 'core_b_id', 'core_b_side', 'notes'];

    public function location(): MorphTo
    {
        return $this->morphTo();
    }

    public function coreA(): BelongsTo
    {
        return $this->belongsTo(CableCore::class, 'core_a_id');
    }

    public function coreB(): BelongsTo
    {
        return $this->belongsTo(CableCore::class, 'core_b_id');
    }

    /**
     * Splices that involve the core, on either side.
     */
    public function scopeInvolving(Builder $query, int $coreId): void
    {
        $query->where(fn (Builder $q) => $q->where('core_a_id', $coreId)->orWhere('core_b_id', $coreId));
    }

    public function otherCoreId(int $coreId): int
    {
        return (int) $this->core_a_id === $coreId ? (int) $this->core_b_id : (int) $this->core_a_id;
    }

    /**
     * Where the splice is, for traces.
     *
     * @return array{splice_id: int, kind: string, id: int, code: string|null, notes: string|null}
     */
    public function describeLocation(): array
    {
        $class = Relation::getMorphedModel($this->location_type);
        $location = $class ? $class::withoutGlobalScopes()->find($this->location_id) : null;

        return [
            'splice_id' => $this->id,
            'kind' => $this->location_type,
            'id' => (int) $this->location_id,
            'code' => $location ? ($location instanceof Area ? $location->name : $location->code) : null,
            'notes' => $this->notes,
        ];
    }
}
