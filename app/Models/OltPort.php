<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A PON port on an OLT card ("slot"). Several ODCs may hang off one port.
 */
class OltPort extends Model
{
    public const STATUSES = ['normal', 'rusak'];

    protected $fillable = ['olt_id', 'slot', 'port', 'status', 'notes'];

    protected $attributes = [
        'status' => 'normal',
    ];

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }

    public function odcs(): HasMany
    {
        return $this->hasMany(Odc::class);
    }
}
