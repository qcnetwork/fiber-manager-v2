<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A POP/ODC/ODP a cable passes through on its way, pinned to one vertex of the cable's path.
 */
class CableStop extends Model
{
    protected $fillable = ['cable_id', 'vertex', 'asset_type', 'asset_id'];

    protected function casts(): array
    {
        return ['vertex' => 'integer'];
    }

    public function cable(): BelongsTo
    {
        return $this->belongsTo(Cable::class);
    }

    public function asset(): MorphTo
    {
        return $this->morphTo();
    }
}
