<?php

namespace App\Models;

use App\Enums\PortStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdpPort extends Model
{
    protected $fillable = ['odp_id', 'number', 'status', 'customer_id', 'notes'];

    protected $attributes = [
        'status' => 'kosong',
    ];

    protected function casts(): array
    {
        return [
            'status' => PortStatus::class,
        ];
    }

    public function odp(): BelongsTo
    {
        return $this->belongsTo(Odp::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
