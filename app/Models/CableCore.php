<?php

namespace App\Models;

use App\Enums\CoreStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One fiber core of a cable. Its color follows from its number and the cable's tube size (shown by the frontend).
 * At each location its cable reaches, a core can be connected once per direction (towards the cable's start
 * or its end): spliced to another core, or ended on an ODP/ODC splitter input or output port. At a cable end only
 * one direction exists; where the cable passes through, the core can be cut and each direction used separately.
 */
class CableCore extends Model
{
    public const SIDES = ['from', 'to'];

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
     * Splices this core takes part in, on either side.
     *
     * @return Builder<CoreSplice>
     */
    public function splices(): Builder
    {
        return CoreSplice::involving($this->id);
    }

    /**
     * Whether removing the core would lose information.
     */
    public function isRecorded(): bool
    {
        return $this->status !== CoreStatus::Kosong || filled($this->notes) || $this->splices()->exists()
            || CoreTermination::where('core_id', $this->id)->exists();
    }

    /**
     * The fiber path through this core, end to end: the pieces of cores joined by splices, in order.
     * A core cut where its cable passes a location carries separate fibers on each piece; the path starts on the
     * piece leaving the given location in the given direction (by default: the piece at the cable's start).
     * `segments[i].span` is where that piece begins and ends, in path order; `joins[i]` sits between segments i and i+1.
     *
     * @return array<string, mixed>
     */
    public function trace(?string $atType = null, ?int $atId = null, ?string $side = null): array
    {
        $layout = self::layout($this->cable_id);
        $pos = $atType !== null ? ($layout['positions']["{$atType}:{$atId}"] ?? 0) : 0;
        $dir = $side ?? ($pos === $layout['last'] && $pos > 0 ? 'from' : 'to');
        $seen = [];

        $forward = self::walk($this->id, $pos, $dir, $seen);

        // Behind the seed: whatever this core is connected to at that point on that side.
        $backward = ['segments' => [], 'joins' => [], 'end' => $layout['points'][$pos] ?? null, 'termination' => null];
        $here = collect(self::connections($this->id))->first(fn (array $c) => $c['pos'] === $pos && $c['side'] === $dir);
        if ($here && $here['termination']) {
            $backward['termination'] = $here['termination']->describe();
        } elseif ($here) {
            $partner = self::crossSplice($here);
            if ($partner) {
                $walked = self::walk($partner['core_id'], $partner['pos'], $partner['side'], $seen);
                if ($walked['segments']) {
                    $backward = [...$walked, 'joins' => [$here['splice']->describeLocation(), ...$walked['joins']]];
                }
            }
        }

        $flip = fn (array $s) => [...$s, 'span' => ['from' => $s['span']['to'], 'to' => $s['span']['from']]];
        $segments = [...array_map($flip, array_reverse($backward['segments'])), ...$forward['segments']];

        return [
            'segments' => $segments,
            'joins' => [...array_reverse($backward['joins']), ...$forward['joins']],
            // Where the path begins and ends: a cable end, or where the core is cut and left unconnected.
            'start' => $backward['end'],
            'end' => $forward['end'],
            // Where the path really ends inside an ODP/ODC: a splitter input or an output port.
            'start_termination' => $backward['termination'],
            'end_termination' => $forward['termination'],
            // Every core on the path, so callers tracing many cores can skip ones already covered.
            'core_ids' => array_values(array_unique(array_column($segments, 'core_id'))),
            'pieces' => array_column($segments, 'piece'),
        ];
    }

    /**
     * One trace per piece of the core: a core cut along the way carries a separate fiber on each piece.
     *
     * @return list<array<string, mixed>>
     */
    public function traces(): array
    {
        $layout = self::layout($this->cable_id);
        // The pieces start at the cable's start and at every point along the way where the core is cut.
        $cuts = collect(self::connections($this->id))->pluck('pos')
            ->filter(fn (?int $p) => $p !== null && $p > 0 && $p < $layout['last'])
            ->unique()->sort()->values();

        $traces = [];
        $covered = [];
        foreach ([0, ...$cuts] as $start) {
            if (isset($covered["{$this->id}:{$start}"])) {
                continue;
            }
            $point = $layout['points'][$start] ?? null;
            $trace = $this->trace($point['kind'] ?? null, $point['id'] ?? null, 'to');
            foreach ($trace['pieces'] as $piece) {
                $covered[$piece] = true;
            }
            $traces[] = $trace;
        }

        return $traces;
    }

    /**
     * Follows the fiber from a point on a core in a direction, through splices, until it ends.
     *
     * @param  array<string, true>  $seen  pieces already on the path (stops loops)
     * @return array{segments: list<array<string, mixed>>, joins: list<array<string, mixed>>, end: array<string, mixed>|null, termination: array<string, mixed>|null}
     */
    private static function walk(int $coreId, int $pos, string $dir, array &$seen): array
    {
        $segments = [];
        $joins = [];

        while (true) {
            $core = self::find($coreId);
            if (! $core) {
                return ['segments' => $segments, 'joins' => $joins, 'end' => null, 'termination' => null];
            }
            $layout = self::layout($core->cable_id);

            // The next point along the core where it is connected; the fiber stops there.
            $ahead = collect(self::connections($coreId))
                ->filter(fn (array $c) => $c['pos'] !== null && ($dir === 'to' ? $c['pos'] > $pos : $c['pos'] < $pos));
            $stop = $ahead->isEmpty() ? ($dir === 'to' ? $layout['last'] : 0) : ($dir === 'to' ? $ahead->min('pos') : $ahead->max('pos'));
            // Arriving there, the fiber enters the connection made on the side facing back towards it.
            $hit = $ahead->first(fn (array $c) => $c['pos'] === $stop && $c['side'] !== $dir);

            $piece = $coreId.':'.min($pos, $stop);
            if (isset($seen[$piece])) {
                return ['segments' => $segments, 'joins' => $joins, 'end' => null, 'termination' => null];
            }
            $seen[$piece] = true;
            $segments[] = [
                ...$core->describe(),
                'piece' => $piece,
                'span' => ['from' => $layout['points'][$pos] ?? null, 'to' => $layout['points'][$stop] ?? null],
            ];
            $end = $layout['points'][$stop] ?? null;

            // The cable's end, or cut here with nothing on this side.
            if (! $hit) {
                return ['segments' => $segments, 'joins' => $joins, 'end' => $end, 'termination' => null];
            }
            if ($hit['termination']) {
                return ['segments' => $segments, 'joins' => $joins, 'end' => $end, 'termination' => $hit['termination']->describe()];
            }
            $partner = self::crossSplice($hit);
            if (! $partner) {
                return ['segments' => $segments, 'joins' => $joins, 'end' => $end, 'termination' => null];
            }
            $joins[] = $hit['splice']->describeLocation();
            ['core_id' => $coreId, 'pos' => $pos, 'side' => $dir] = $partner;
        }
    }

    /**
     * The other side of a splice: its core, where the location sits on that core's cable, and the way it continues.
     *
     * @param  array<string, mixed>  $connection
     * @return array{core_id: int, pos: int, side: string}|null
     */
    private static function crossSplice(array $connection): ?array
    {
        $splice = $connection['splice'];
        $other = $connection['as'] === 'a' ? 'b' : 'a';
        $core = self::find($splice->{"core_{$other}_id"});
        $pos = $core ? (self::layout($core->cable_id)['positions']["{$splice->location_type}:{$splice->location_id}"] ?? null) : null;

        return $pos === null ? null : ['core_id' => $core->id, 'pos' => $pos, 'side' => $splice->{"core_{$other}_side"}];
    }

    /**
     * Where the core is connected: splices and terminations, each with its position along the cable and side.
     * A connection whose location the cable no longer reaches has a null position.
     *
     * @return list<array{splice: CoreSplice|null, termination: CoreTermination|null, as: string|null, side: string, pos: int|null}>
     */
    private static function connections(int $coreId): array
    {
        $core = self::find($coreId);
        $positions = $core ? self::layout($core->cable_id)['positions'] : [];
        $at = fn (Model $m) => $positions["{$m->location_type}:{$m->location_id}"] ?? null;
        $result = [];

        foreach (CoreSplice::involving($coreId)->orderBy('id')->get() as $splice) {
            $as = (int) $splice->core_a_id === $coreId ? 'a' : 'b';
            $result[] = ['splice' => $splice, 'termination' => null, 'as' => $as, 'side' => $splice->{"core_{$as}_side"}, 'pos' => $at($splice)];
        }
        foreach (CoreTermination::where('core_id', $coreId)->orderBy('id')->get() as $t) {
            $result[] = ['splice' => null, 'termination' => $t, 'as' => null, 'side' => $t->side, 'pos' => $at($t)];
        }

        return $result;
    }

    /**
     * The points along a cable: position (vertex) of each asset it reaches, and the asset at each position.
     *
     * @return array{last: int, positions: array<string, int>, points: array<int, array{kind: string, id: int, code: string}>}
     */
    private static function layout(int $cableId): array
    {
        return once(function () use ($cableId) {
            $cable = Cable::withoutGlobalScopes()->with(['from', 'to', 'stops.asset'])->find($cableId);
            $last = count($cable->path) - 1;
            $positions = [];
            $points = [];
            $add = function (?Model $asset, ?string $kind, int $pos) use (&$positions, &$points) {
                if (! $asset || ! $kind) {
                    return;
                }
                $positions["{$kind}:{$asset->id}"] ??= $pos;
                $points[$pos] ??= ['kind' => $kind, 'id' => $asset->id, 'code' => $asset instanceof Area ? $asset->name : $asset->code];
            };

            $add($cable->from, $cable->from_type, 0);
            foreach ($cable->stops as $stop) {
                if ($stop->vertex > 0 && $stop->vertex < $last) {
                    $add($stop->asset, $stop->asset_type, (int) $stop->vertex);
                }
            }
            $add($cable->to, $cable->to_type, $last);

            return ['last' => $last, 'positions' => $positions, 'points' => $points];
        });
    }

    /**
     * The core with its cable, for traces and splice lists.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'core_id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'notes' => $this->notes,
            'cable' => self::describeCable($this->cable_id),
        ];
    }

    /**
     * A cable with its ends; remembered for the request, since a splice sheet traces many cores of few cables.
     *
     * @return array<string, mixed>
     */
    private static function describeCable(int $cableId): array
    {
        return once(function () use ($cableId) {
            $cable = Cable::withoutGlobalScopes()->with(['from', 'to'])->find($cableId);
            $end = fn (?Model $asset, ?string $kind) => $asset ? ['kind' => $kind, 'id' => $asset->id, 'code' => $asset instanceof Area ? $asset->name : $asset->code] : null;

            return [
                'id' => $cable->id,
                'code' => $cable->code,
                'type' => $cable->type,
                'cores' => $cable->cores,
                'tube_size' => $cable->tube_size,
                'from' => $end($cable->from, $cable->from_type),
                'to' => $end($cable->to, $cable->to_type),
            ];
        });
    }

    /**
     * Connections per core id: splices plus ODP/ODC terminations, at any location and in either direction.
     *
     * @param  Collection<int, int>|list<int>  $coreIds
     * @return Collection<int, int>
     */
    public static function connectionCounts(Collection|array $coreIds): Collection
    {
        $ids = collect($coreIds)->all();
        $a = CoreSplice::whereIn('core_a_id', $ids)->selectRaw('core_a_id as core_id, count(*) as n')->groupBy('core_a_id')->pluck('n', 'core_id');
        $b = CoreSplice::whereIn('core_b_id', $ids)->selectRaw('core_b_id as core_id, count(*) as n')->groupBy('core_b_id')->pluck('n', 'core_id');
        $t = CoreTermination::whereIn('core_id', $ids)->selectRaw('core_id, count(*) as n')->groupBy('core_id')->pluck('n', 'core_id');

        return collect($ids)->mapWithKeys(fn (int $id) => [$id => (int) ($a[$id] ?? 0) + (int) ($b[$id] ?? 0) + (int) ($t[$id] ?? 0)]);
    }
}
