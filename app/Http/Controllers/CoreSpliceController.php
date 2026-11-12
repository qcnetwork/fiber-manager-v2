<?php

namespace App\Http\Controllers;

use App\Support\NetworkLocation;
use App\Enums\CoreStatus;
use App\Http\Requests\CableRequest;
use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\Cable;
use App\Models\CableCore;
use App\Models\CoreSplice;
use App\Models\CoreTermination;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\OdpPort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Core-to-core splices at a joint box/rosette, ODC, ODP or POP.
 */
class CoreSpliceController extends Controller
{
    /**
     * The cables reaching a location with their cores, and the splices made there.
     */
    public function index(Request $request): JsonResponse
    {
        $location = $this->location($request);

        $cables = Cable::reaching($location)->with(['coreRows', 'stops.asset', 'from', 'to'])->orderBy('code')->get();
        $counts = CableCore::connectionCounts($cables->flatMap(fn (Cable $c) => $c->coreRows->modelKeys()));
        $splices = $this->splicesAt($location)->with(['coreA', 'coreB'])->orderBy('id')->get();
        // At an ODP or ODC, cores can also end on a splitter input or an output port.
        $terminal = $location instanceof Odp || $location instanceof Odc;
        $terminations = $terminal ? CoreTermination::at($location)->with('core')->orderBy('kind')->orderBy('port')->get() : collect();
        $used = $this->usedSides($splices, $terminations);

        return response()->json([
            'terminal' => $terminal ? [
                'kind' => $location->getMorphClass(),
                'capacity' => $location->capacity,
                'splitter_ratio' => $location->splitter_ratio,
                'max_inputs' => CoreTermination::MAX_INPUTS,
                'ports' => $this->outputPorts($location),
            ] : null,
            'terminations' => $terminations->map(fn (CoreTermination $t) => [
                'id' => $t->id,
                'kind' => $t->kind,
                'port' => $t->port,
                'notes' => $t->notes,
                'core' => ['core_id' => $t->core->id, 'cable_id' => $t->core->cable_id, 'number' => $t->core->number, 'side' => $t->side],
            ])->values(),
            'cables' => $cables->map(fn (Cable $cable) => [
                ...$cable->only('id', 'code', 'type', 'cores', 'tube_size'),
                // One way at a cable end, two where the cable passes through.
                'sides' => $cable->sidesAt($location),
                'cores_list' => $cable->coreRows->map(fn (CableCore $core) => [
                    ...$core->only('id', 'number', 'status', 'notes'),
                    'splices' => $counts[$core->id] ?? 0,
                    'used_sides' => array_keys($used[$core->id] ?? []),
                ])->values(),
            ])->values(),
            'splices' => $splices->map(fn (CoreSplice $s) => [
                'id' => $s->id,
                'notes' => $s->notes,
                'a' => ['core_id' => $s->coreA->id, 'cable_id' => $s->coreA->cable_id, 'number' => $s->coreA->number, 'side' => $s->core_a_side],
                'b' => ['core_id' => $s->coreB->id, 'cable_id' => $s->coreB->cable_id, 'number' => $s->coreB->number, 'side' => $s->core_b_side],
            ])->values(),
        ]);
    }

    /**
     * Splices one or more core pairs at a location; a run of consecutive cores is sent as several pairs.
     * `side_a`/`side_b` say which way along their cable the A and B cores are taken; needed only where a cable
     * passes through (at a cable end there is just one way).
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canEditNetwork(), 403);
        $location = $this->location($request);

        $data = $request->validate([
            'pairs' => ['required', 'array', 'min:1', 'max:288'],
            'pairs.*.a' => ['required', 'integer'],
            'pairs.*.b' => ['required', 'integer'],
            'side_a' => ['nullable', Rule::in(CableCore::SIDES)],
            'side_b' => ['nullable', Rule::in(CableCore::SIDES)],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        // Only cables that actually reach this location can be spliced here.
        $cables = Cable::reaching($location)->with(['stops.asset', 'from', 'to'])->get()->keyBy('id');
        $coreIds = collect($data['pairs'])->flatMap(fn (array $p) => [(int) $p['a'], (int) $p['b']])->unique();
        $cores = CableCore::whereKey($coreIds)->get()->keyBy('id');
        $used = $this->usedSides(
            $this->splicesAt($location)->get(),
            $location instanceof Odp || $location instanceof Odc ? CoreTermination::at($location)->get() : collect(),
        );

        $rows = [];
        $taken = [];
        foreach ($data['pairs'] as $pair) {
            $row = [];
            foreach (['a', 'b'] as $end) {
                $core = $cores[(int) $pair[$end]] ?? null;
                if (! $core || ! $cables->has($core->cable_id)) {
                    throw ValidationException::withMessages(['pairs' => 'Core harus milik kabel yang tersambung ke titik ini.']);
                }
                $side = $this->resolveSide($cables[$core->cable_id], $location, $data["side_{$end}"] ?? null, 'pairs');
                $key = "{$core->id}:{$side}";
                if (isset($used[$core->id][$side]) || isset($taken[$key])) {
                    throw ValidationException::withMessages(['pairs' => "Core {$core->number} sudah disambung di titik ini pada arah tersebut."]);
                }
                $taken[$key] = true;
                $row["core_{$end}_id"] = $core->id;
                $row["core_{$end}_side"] = $side;
            }
            if ($row['core_a_id'] === $row['core_b_id']) {
                throw ValidationException::withMessages(['pairs' => 'Core tidak bisa disambung ke dirinya sendiri.']);
            }
            $rows[] = $row;
        }

        DB::transaction(function () use ($data, $rows, $location, $cores) {
            foreach ($rows as $row) {
                CoreSplice::create([
                    ...$row,
                    'location_type' => $location->getMorphClass(),
                    'location_id' => $location->getKey(),
                    'notes' => $data['notes'] ?? null,
                ]);
            }
            // A spliced core carries something.
            CableCore::whereKey($cores->keys())->where('status', CoreStatus::Kosong)->update(['status' => CoreStatus::Terpakai]);

            ActivityLog::record('created', 'splice', null, $this->locationLabel($location), $this->areaId($location), [
                'sambungan' => [null, $this->pairsSummary($data['pairs'], $cores)],
            ]);
        });

        return back();
    }

    public function destroy(Request $request, CoreSplice $splice): RedirectResponse
    {
        abort_unless($request->user()->canEditNetwork(), 403);
        // Resolving the location through its area scope guards access.
        $location = NetworkLocation::findOrFail($splice->location_type, $splice->location_id);
        $splice->load(['coreA', 'coreB']);
        $cores = collect([$splice->coreA->id => $splice->coreA, $splice->coreB->id => $splice->coreB]);
        $summary = $this->pairsSummary([['a' => $splice->core_a_id, 'b' => $splice->core_b_id]], $cores);

        $splice->delete();
        ActivityLog::record('deleted', 'splice', null, $this->locationLabel($location), $this->areaId($location), [
            'sambungan' => [$summary, null],
        ]);

        return back();
    }

    /**
     * Every core spliced to this one, end to end.
     */
    public function trace(CableCore $core): JsonResponse
    {
        $core->cable()->firstOrFail(); // area scope guards access

        // One path per piece: a core cut where its cable passes a joint carries a separate fiber each way.
        return response()->json(['traces' => $core->traces()]);
    }

    /**
     * The ways each core is already taken at the location: [core id => [side => true]].
     *
     * @param  Collection<int, CoreSplice>  $splices
     * @param  Collection<int, CoreTermination>  $terminations
     * @return array<int, array<string, true>>
     */
    public static function usedSides(Collection $splices, Collection $terminations): array
    {
        $used = [];
        foreach ($splices as $s) {
            $used[(int) $s->core_a_id][$s->core_a_side] = true;
            $used[(int) $s->core_b_id][$s->core_b_side] = true;
        }
        foreach ($terminations as $t) {
            $used[(int) $t->core_id][$t->side] = true;
        }

        return $used;
    }

    /**
     * The way a core of the cable is taken at the location: the one given, or the only one there is.
     */
    public static function resolveSide(Cable $cable, Model $location, ?string $side, string $errorKey): string
    {
        $sides = array_column($cable->sidesAt($location), 'side');
        $name = $cable->code ?? "Kabel #{$cable->id}";

        if ($side === null) {
            if (count($sides) !== 1) {
                throw ValidationException::withMessages([$errorKey => "Pilih arah core untuk {$name}: kabel ini lewat di titik ini."]);
            }

            return $sides[0];
        }
        if (! in_array($side, $sides, true)) {
            throw ValidationException::withMessages([$errorKey => "Arah tersebut tidak ada untuk {$name} di titik ini."]);
        }

        return $side;
    }

    /**
     * An ODP's or ODC's output ports with what each one feeds: an ODP port's customer, an ODC port's ODP.
     *
     * @return list<array{number: int, status: string, feeds: string|null}>
     */
    private function outputPorts(Odp|Odc $location): array
    {
        if ($location instanceof Odp) {
            return $location->ports()->with('customer:id,name')->get()->map(fn (OdpPort $p) => [
                'number' => $p->number,
                'status' => $p->status->value,
                'feeds' => $p->customer?->name,
            ])->all();
        }

        $odps = Odp::where('odc_id', $location->id)->whereNotNull('odc_port')->pluck('code', 'odc_port');

        return array_map(fn (int $n) => [
            'number' => $n,
            'status' => isset($odps[$n]) ? 'terpakai' : 'kosong',
            'feeds' => $odps[$n] ?? null,
        ], range(1, max(1, (int) $location->capacity)));
    }

    private function location(Request $request): Model
    {
        $data = $request->validate([
            'location_type' => ['required', Rule::in(CableRequest::ASSET_TYPES)],
            'location_id' => ['required', 'integer'],
        ]);

        return NetworkLocation::findOrFail($data['location_type'], $data['location_id']);
    }

    /**
     * @return Builder<CoreSplice>
     */
    private function splicesAt(Model $location): Builder
    {
        return CoreSplice::where('location_type', $location->getMorphClass())->where('location_id', $location->getKey());
    }

    private function locationLabel(Model $location): string
    {
        return $location instanceof Area ? $location->name : (string) $location->code;
    }

    private function areaId(Model $location): ?int
    {
        return $location instanceof Area ? $location->id : $location->area_id;
    }

    /**
     * "KBL-01 core 3 ↔ KBL-02 core 5, …" for the activity log.
     *
     * @param  list<array{a: int|string, b: int|string}>  $pairs
     * @param  Collection<int, CableCore>  $cores
     */
    private function pairsSummary(array $pairs, Collection $cores): string
    {
        $cableCodes = Cable::withoutGlobalScopes()->whereKey($cores->pluck('cable_id')->unique())->pluck('code', 'id');
        $name = fn (int $id) => ($cableCodes[$cores[$id]->cable_id] ?? 'Kabel #'.$cores[$id]->cable_id).' core '.$cores[$id]->number;

        return collect($pairs)->map(fn (array $p) => $name((int) $p['a']).' ↔ '.$name((int) $p['b']))->implode(', ');
    }
}
