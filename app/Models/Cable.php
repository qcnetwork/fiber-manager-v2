<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\CableType;
use App\Models\Concerns\BelongsToArea;
use App\Models\Concerns\LogsActivity;
use App\Services\GeoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

class Cable extends Model
{
    use BelongsToArea, LogsActivity;

    protected $fillable = [
        'area_id', 'code', 'type', 'cores', 'tube_size', 'status', 'from_type', 'from_id', 'to_type', 'to_id', 'path', 'notes',
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
            // A tube can't hold more cores than the cable has.
            $cable->tube_size = max(1, min((int) ($cable->tube_size ?: 12), (int) $cable->cores));
        });

        static::created(fn (Cable $cable) => $cable->syncCores());
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
     * POPs/ODCs/ODPs the cable passes through between its ends, in path order.
     */
    public function stops(): HasMany
    {
        return $this->hasMany(CableStop::class)->orderBy('vertex');
    }

    public function coreRows(): HasMany
    {
        return $this->hasMany(CableCore::class)->orderBy('number');
    }

    /**
     * Makes one core row per core. Returns false (and changes nothing) when a core that would be removed
     * by lowering the core count still holds information.
     */
    public function syncCores(): bool
    {
        $existing = $this->coreRows()->get()->keyBy('number');

        $toRemove = $existing->filter(fn (CableCore $core) => $core->number > $this->cores);
        if ($toRemove->contains(fn (CableCore $core) => $core->isRecorded())) {
            return false;
        }
        $this->coreRows()->whereKey($toRemove->modelKeys())->delete();

        $missing = collect(range(1, max(1, $this->cores)))->diff($existing->keys());
        $this->coreRows()->createMany($missing->map(fn (int $number) => ['number' => $number])->all());

        return true;
    }

    /**
     * Replaces the pass-through points; vertices at either end are skipped (those are from/to).
     *
     * @param  list<array{vertex: int, type: string, id: int}>  $stops
     */
    public function syncStops(array $stops): void
    {
        $last = count($this->path) - 1;
        $this->stops()->delete();

        foreach ($stops as $stop) {
            if ($stop['vertex'] > 0 && $stop['vertex'] < $last) {
                $this->stops()->create(['vertex' => $stop['vertex'], 'asset_type' => $stop['type'], 'asset_id' => $stop['id']]);
            }
        }
    }

    /**
     * Cables that start, end or pass through the asset.
     */
    public function scopeConnectedTo(Builder $query, Model $asset): void
    {
        $type = $asset->getMorphClass();
        $id = $asset->getKey();

        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('from_type', $type)->where('from_id', $id))
            ->orWhere(fn (Builder $q) => $q->where('to_type', $type)->where('to_id', $id))
            ->orWhereHas('stops', fn (Builder $q) => $q->where('asset_type', $type)->where('asset_id', $id)));
    }

    /**
     * Whether the cable already starts, ends or passes through the asset.
     */
    public function connects(Model $asset): bool
    {
        $type = $asset->getMorphClass();
        $id = $asset->getKey();

        return ($this->from_type === $type && (int) $this->from_id === $id)
            || ($this->to_type === $type && (int) $this->to_id === $id)
            || $this->stops()->where('asset_type', $type)->where('asset_id', $id)->exists();
    }

    /**
     * Where the asset sits along the cable, as a vertex: 0 at the start, the pass-through point's vertex,
     * the last vertex at the end; null when the cable doesn't reach it.
     */
    public function positionOf(string $type, int $id): ?int
    {
        if ($this->from_type === $type && (int) $this->from_id === $id) {
            return 0;
        }
        $stop = $this->stops->first(fn (CableStop $s) => $s->asset_type === $type && (int) $s->asset_id === $id);
        if ($stop) {
            return (int) $stop->vertex;
        }

        return $this->to_type === $type && (int) $this->to_id === $id ? count($this->path) - 1 : null;
    }

    /**
     * The ways a core can be taken from the asset: towards the cable's start ("from") and/or its end ("to"),
     * each with the next point that way. At a cable end there is one way; where the cable passes through, two.
     *
     * @return list<array{side: string, toward: string|null}>
     */
    public function sidesAt(Model $asset): array
    {
        $pos = $this->positionOf($asset->getMorphClass(), $asset->getKey());
        if ($pos === null) {
            return [];
        }
        $route = collect($this->route());
        $sides = [];
        if ($pos > 0) {
            $sides[] = ['side' => 'from', 'toward' => $route->last(fn (array $p) => $p['vertex'] < $pos)['asset']['code'] ?? null];
        }
        if ($pos < count($this->path) - 1) {
            $sides[] = ['side' => 'to', 'toward' => $route->first(fn (array $p) => $p['vertex'] > $pos)['asset']['code'] ?? null];
        }

        return $sides;
    }

    /**
     * Cables in the area passing within the radius of a point, nearest first, with their distance in meters.
     *
     * @return Collection<int, array{cable: Cable, distance: float}>
     */
    public static function nearby(float $lat, float $lng, int $areaId, float $radius): Collection
    {
        $box = GeoService::boundingBox($lat, $lng, $radius);

        return static::query()
            ->where('area_id', $areaId)
            ->where('min_lat', '<=', $box['maxLat'])->where('max_lat', '>=', $box['minLat'])
            ->where('min_lng', '<=', $box['maxLng'])->where('max_lng', '>=', $box['minLng'])
            ->get()
            ->map(fn (Cable $cable) => ['cable' => $cable, 'distance' => GeoService::nearestOnPath($lat, $lng, $cable->path)['distance']])
            ->filter(fn (array $hit) => $hit['distance'] <= $radius)
            ->sortBy('distance')
            ->values();
    }

    /**
     * Connects a POP/ODC/ODP lying next to the cable: a free end close to it attaches as that end; otherwise the
     * line gets a point on the asset (a plain bend right there is reused, else a new vertex is inserted on the
     * nearest segment) and the asset becomes a pass-through point. Does nothing when already connected.
     */
    public function connect(Area|Odc|Odp|SpliceBox $asset): void
    {
        if ($asset->lat === null || $asset->lng === null || $this->connects($asset)) {
            return;
        }

        $path = $this->path;
        $last = count($path) - 1;
        $nearest = GeoService::nearestOnPath($asset->lat, $asset->lng, $path);
        $type = $asset->getMorphClass();
        $at = [$asset->lat, $asset->lng];

        // The closest vertex to the nearest point on the line, if that point practically sits on it.
        $vertex = $nearest['t'] < 0.5 ? $nearest['segment'] : $nearest['segment'] + 1;
        $onLine = $this->pointOnSegment($path, $nearest);
        $atVertex = GeoService::distance($path[$vertex][0], $path[$vertex][1], $onLine[0], $onLine[1]) <= 2;

        if ($atVertex && $vertex === 0 && ! $this->from_id) {
            $path[0] = $at;
            $this->fill(['from_type' => $type, 'from_id' => $asset->getKey(), 'path' => $path])->save();

            return;
        }
        if ($atVertex && $vertex === $last && ! $this->to_id) {
            $path[$last] = $at;
            $this->fill(['to_type' => $type, 'to_id' => $asset->getKey(), 'path' => $path])->save();

            return;
        }

        $taken = $this->stops()->pluck('vertex')->all();
        if (! $atVertex || $vertex === 0 || $vertex === $last || in_array($vertex, $taken, true)) {
            // Insert a new vertex after the nearest segment's start; later pass-through points shift by one.
            $vertex = $nearest['segment'] + 1;
            array_splice($path, $vertex, 0, [$at]);
            $this->stops()->where('vertex', '>=', $vertex)->orderByDesc('vertex')->get()
                ->each(fn (CableStop $stop) => $stop->update(['vertex' => $stop->vertex + 1]));
        } else {
            $path[$vertex] = $at;
        }

        $this->path = $path;
        $this->save();
        $this->stops()->create(['vertex' => $vertex, 'asset_type' => $type, 'asset_id' => $asset->getKey()]);
    }

    /**
     * @param  list<array{0: float, 1: float}>  $path
     * @param  array{segment: int, t: float}  $nearest
     * @return array{0: float, 1: float}
     */
    private function pointOnSegment(array $path, array $nearest): array
    {
        [$a, $b] = [$path[$nearest['segment']], $path[$nearest['segment'] + 1] ?? $path[$nearest['segment']]];

        return [$a[0] + ($b[0] - $a[0]) * $nearest['t'], $a[1] + ($b[1] - $a[1]) * $nearest['t']];
    }

    /**
     * Everything the cable connects, start to end: the from end, the pass-through points and the to end,
     * each with its distance along the cable. Unattached ends are included with a null asset.
     *
     * @return list<array{role: string, kind: ?string, asset: ?array{id: int, code: string}, vertex: int, distance_m: int}>
     */
    public function route(): array
    {
        $this->loadMissing(['from', 'to', 'stops.asset']);
        $path = $this->path;
        $last = count($path) - 1;

        // Distance from the start to each vertex.
        $along = [0.0];
        for ($i = 1; $i <= $last; $i++) {
            $along[$i] = $along[$i - 1] + GeoService::pathLength([$path[$i - 1], $path[$i]]);
        }

        $point = fn (string $role, ?string $kind, ?Model $asset, int $vertex) => [
            'role' => $role,
            'kind' => $asset ? $kind : null,
            'asset' => $asset ? ['id' => $asset->id, 'code' => $asset instanceof Area ? $asset->name : $asset->code] : null,
            'vertex' => $vertex,
            'distance_m' => (int) round($along[$vertex]),
        ];

        return [
            $point('from', $this->from_type, $this->from, 0),
            ...$this->stops
                ->filter(fn (CableStop $stop) => $stop->asset && $stop->vertex < $last)
                ->map(fn (CableStop $stop) => $point('via', $stop->asset_type, $stop->asset, $stop->vertex))
                ->values()->all(),
            $point('to', $this->to_type, $this->to, $last),
        ];
    }

    /**
     * Keeps cable ends and pass-through points on a POP/ODC/ODP after it is moved, and lets go of it when it is deleted.
     */
    public static function followEndpoint(Area|Odc|Odp|SpliceBox $asset, bool $deleted = false): void
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

        $stops = CableStop::where('asset_type', $type)->where('asset_id', $asset->getKey());

        if ($deleted) {
            $stops->delete(); // the vertex stays behind as a plain bend in the line

            return;
        }

        if ($asset->lat === null || $asset->lng === null) {
            return;
        }

        foreach ($stops->get()->groupBy('cable_id') as $cableId => $cableStops) {
            $cable = static::withoutGlobalScope('area')->find($cableId);
            $path = $cable->path;
            foreach ($cableStops as $stop) {
                if (isset($path[$stop->vertex])) {
                    $path[$stop->vertex] = [$asset->lat, $asset->lng];
                }
            }
            $cable->path = $path;
            $cable->save();
        }
    }
}
