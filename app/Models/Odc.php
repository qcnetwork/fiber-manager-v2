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
        'area_id', 'olt_id', 'olt_pon_port', 'code', 'name', 'lat', 'lng', 'address',
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

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }

    public function odps(): HasMany
    {
        return $this->hasMany(Odp::class);
    }
}
