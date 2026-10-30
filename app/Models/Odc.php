<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Models\Concerns\BelongsToArea;
use App\Models\Concerns\ConnectsCables;
use App\Models\Concerns\HasPhotos;
use Database\Factories\OdcFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Odc extends Model
{
    /** @use HasFactory<OdcFactory> */
    use BelongsToArea, ConnectsCables, HasFactory, HasPhotos;

    protected $fillable = [
        'area_id', 'olt_port_id', 'code', 'name', 'lat', 'lng', 'address',
        'capacity', 'splitter_ratio', 'status', 'installed_at', 'notes',
    ];

    protected $attributes = [
        'capacity' => 48,
        'status' => 'aktif',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'status' => AssetStatus::class,
            'installed_at' => 'date:Y-m-d',
        ];
    }

    /**
     * The OLT PON port this ODC is fed from.
     */
    public function oltPort(): BelongsTo
    {
        return $this->belongsTo(OltPort::class);
    }

    public function odps(): HasMany
    {
        return $this->hasMany(Odp::class);
    }
}
