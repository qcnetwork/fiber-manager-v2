<?php

namespace App\Models;

use App\Enums\CoreStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One fiber core of a cable. Its color follows from its number and the cable's tube size (shown by the frontend).
 * A core is spliced to cores of other cables at most twice (once towards each end of its cable).
 */
class CableCore extends Model
{
    public const MAX_SPLICES = 2;

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
        return $this->status !== CoreStatus::Kosong || filled($this->notes) || $this->splices()->exists();
    }

    /**
     * The whole fiber path this core is part of: the chain of cores joined by splices, end to end.
     * `segments` are the cores in order; `joins[i]` is where segments i and i+1 are spliced.
     *
     * @return array{segments: list<array<string, mixed>>, joins: list<array<string, mixed>>}
     */
    public function trace(): array
    {
        $visited = [$this->id => true];
        $first = $this->splices()->orderBy('id')->get();

        // Walks from this core through one of its splices until the chain ends (or loops back).
        $walk = function (?CoreSplice $splice) use (&$visited): array {
            $steps = [];
            $current = $this;
            while ($splice) {
                $next = CableCore::find($splice->otherCoreId($current->id));
                if (! $next || isset($visited[$next->id])) {
                    break;
                }
                $visited[$next->id] = true;
                $steps[] = [$splice, $next];
                $current = $next;
                $splice = $next->splices()->whereKeyNot($splice->id)->first();
            }

            return $steps;
        };

        $forward = $walk($first->get(0));
        $backward = array_reverse($walk($first->get(1)));

        $segments = [...array_map(fn ($step) => $step[1], $backward), $this, ...array_map(fn ($step) => $step[1], $forward)];
        $joins = [...array_map(fn ($step) => $step[0], $backward), ...array_map(fn ($step) => $step[0], $forward)];

        $segments = array_map(fn (CableCore $core) => $core->describe(), $segments);
        $joins = array_map(fn (CoreSplice $splice) => $splice->describeLocation(), $joins);

        return [
            'segments' => $segments,
            'joins' => $joins,
            'start' => self::outerEnd($segments[0], $joins[0] ?? null, 'from'),
            'end' => self::outerEnd($segments[count($segments) - 1], $joins[count($joins) - 1] ?? null, 'to'),
            // Every core on the path, so callers tracing many cores can skip ones already covered.
            'core_ids' => array_column($segments, 'core_id'),
        ];
    }

    /**
     * Where the path leaves an outer segment: the end of its cable that isn't the splice next to it.
     * Without any splice, a lone core simply runs from its cable's start to its end.
     *
     * @param  array<string, mixed>  $segment
     * @param  array<string, mixed>|null  $join
     * @return array{kind: string, id: int, code: string}|null
     */
    private static function outerEnd(array $segment, ?array $join, string $fallback): ?array
    {
        $cable = $segment['cable'];
        if (! $join) {
            return $cable[$fallback];
        }
        foreach ([$cable['from'], $cable['to']] as $end) {
            if ($end && ! ($end['kind'] === $join['kind'] && $end['id'] === $join['id'])) {
                return $end;
            }
        }

        return null;
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
     * Splice counts per core id, for marking spliced cores in lists.
     *
     * @param  Collection<int, int>|list<int>  $coreIds
     * @return Collection<int, int>
     */
    public static function spliceCounts(Collection|array $coreIds): Collection
    {
        $ids = collect($coreIds)->all();
        $a = CoreSplice::whereIn('core_a_id', $ids)->selectRaw('core_a_id as core_id, count(*) as n')->groupBy('core_a_id')->pluck('n', 'core_id');
        $b = CoreSplice::whereIn('core_b_id', $ids)->selectRaw('core_b_id as core_id, count(*) as n')->groupBy('core_b_id')->pluck('n', 'core_id');

        return collect($ids)->mapWithKeys(fn (int $id) => [$id => (int) ($a[$id] ?? 0) + (int) ($b[$id] ?? 0)]);
    }
}
