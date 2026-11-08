<?php

namespace App\Models;

use App\Enums\CoreStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fiber core of a cable. Its color follows from its number and the cable's tube size (shown by the frontend).
 */
class CableCore extends Model
{
    protected $fillable = ['cable_id', 'number', 'status', 'notes'];

    protected $attributes = [
        'status' => 'kosong',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => CoreStatus::class,
        ];
    }

    public function cable(): BelongsTo
    {
        return $this->belongsTo(Cable::class);
    }

    /**
     * Whether removing the core would lose information.
     */
    public function isRecorded(): bool
    {
        return $this->status !== CoreStatus::Kosong || filled($this->notes);
    }
}
