<?php

namespace App\Models;

use App\Enums\PortStatus;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdpPort extends Model
{
    use LogsActivity;

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

    public function activityLabel(): string
    {
        return "{$this->odp?->code} port {$this->number}";
    }

    public function activityAreaId(): ?int
    {
        return $this->odp?->area_id;
    }
}
