<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\SpliceBoxType;
use App\Models\Concerns\BelongsToArea;
use App\Models\Concerns\ConnectsCables;
use App\Models\Concerns\HasPhotos;
use App\Models\Concerns\LogsActivity;
use Database\Factories\SpliceBoxFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A joint box or rosette on the route where cables are spliced together.
 */
class SpliceBox extends Model
{
    /** @use HasFactory<SpliceBoxFactory> */
    use BelongsToArea, ConnectsCables, HasFactory, HasPhotos, LogsActivity;

    protected $fillable = [
        'area_id', 'type', 'code', 'name', 'lat', 'lng', 'address', 'pole_number', 'capacity', 'status', 'installed_at', 'notes',
    ];

    protected $attributes = [
        'type' => 'joint_box',
        'status' => 'aktif',
    ];

    protected function casts(): array
    {
        return [
            'type' => SpliceBoxType::class,
            'lat' => 'float',
            'lng' => 'float',
            'status' => AssetStatus::class,
            'installed_at' => 'date:Y-m-d',
        ];
    }
}
