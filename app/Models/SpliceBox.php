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

    protected static function booted(): void
    {
        // Left blank, the code is generated: JB-<area>-01 / RS-<area>-01, numbered on from the highest in use.
        // Runs after the area trait's creating hook, so area_id is already set.
        $fill = function (self $box) {
            if (blank($box->code)) {
                $box->code = static::nextCode($box->type, $box->area_id);
            }
        };
        static::creating($fill);
        static::updating($fill);
    }

    public static function nextCode(SpliceBoxType|string|null $type, ?int $areaId): string
    {
        $type = $type instanceof SpliceBoxType ? $type : (SpliceBoxType::tryFrom((string) $type) ?? SpliceBoxType::JointBox);
        $areaCode = $areaId ? Area::whereKey($areaId)->value('code') : null;
        $prefix = implode('-', array_filter([$type === SpliceBoxType::Roset ? 'RS' : 'JB', $areaCode])).'-';

        // Codes are unique across areas, so look past the area scope.
        $highest = static::withoutGlobalScopes()
            ->where('code', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $prefix).'%')
            ->pluck('code')
            ->map(fn (string $code) => preg_match('/^\d+$/', substr($code, strlen($prefix))) ? (int) substr($code, strlen($prefix)) : 0)
            ->max() ?? 0;

        return $prefix.str_pad((string) ($highest + 1), 2, '0', STR_PAD_LEFT);
    }

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
