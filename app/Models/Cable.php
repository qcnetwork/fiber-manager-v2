<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\CableType;
use App\Models\Concerns\BelongsToArea;
use App\Models\Concerns\LogsActivity;
use App\Services\GeoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Cable extends Model
{
    use BelongsToArea, LogsActivity;

    protected $fillable = [
        'area_id', 'code', 'type', 'cores', 'status', 'from_type', 'from_id', 'to_type', 'to_id', 'path', 'notes',
    ];

    protected $attributes = [
        'status' => 'aktif',
    ];

    protected function casts(): array
    {
        return [
            'type' => CableType::class,
            'status' => AssetStatus::class,
            'path' => 'array',
            'min_lat' => 'float',
            'max_lat' => 'float',
            'min_lng' => 'float',
            'max_lng' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // Length and bounding box always follow the path.
        static::saving(function (Cable $cable) {
            $path = $cable->path;
            $cable->length_m = (int) round(GeoService::pathLength($path));
            $cable->min_lat = min(array_column($path, 0));
            $cable->max_lat = max(array_column($path, 0));
            $cable->min_lng = min(array_column($path, 1));
            $cable->max_lng = max(array_column($path, 1));
        });
    }

    protected function activityIgnored(): array
    {
        return ['min_lat', 'max_lat', 'min_lng', 'max_lng'];
    }

    /**
     * The route is a long list of points; the log only says it changed (the length shows by how much).
     */
    protected function activityMasked(): array
    {
        return ['path'];
    }

    public function from(): MorphTo
    {
        return $this->morphTo();
    }

    public function to(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Keeps connected cable ends on a POP/ODC/ODP after it is moved, and lets go of it when it is deleted.
     */
    public static function followEndpoint(Area|Odc|Odp $asset, bool $deleted = false): void
    {
        $type = $asset->getMorphClass();

        foreach (['from', 'to'] as $end) {
            $cables = static::withoutGlobalScope('area')
                ->where("{$end}_type", $type)
                ->where("{$end}_id", $asset->getKey())
                ->get();

            foreach ($cables as $cable) {
                if ($deleted) {
                    $cable->fill(["{$end}_type" => null, "{$end}_id" => null]);
                } else {
                    $path = $cable->path;
                    if ($asset->lat === null || $asset->lng === null) {
                        continue; // a POP whose location was cleared: leave the line where it is
                    }
                    $path[$end === 'from' ? 0 : count($path) - 1] = [$asset->lat, $asset->lng];
                    $cable->path = $path;
                }
                $cable->save();
            }
        }
    }
}
