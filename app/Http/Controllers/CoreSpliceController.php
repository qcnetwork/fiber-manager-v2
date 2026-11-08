<?php

namespace App\Http\Controllers;

use App\Enums\CoreStatus;
use App\Http\Requests\CableRequest;
use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\Cable;
use App\Models\CableCore;
use App\Models\CoreSplice;
use App\Models\CoreTermination;
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

        $cables = Cable::connectedTo($location)->with('coreRows')->orderBy('code')->get();
        $counts = CableCore::connectionCounts($cables->flatMap(fn (Cable $c) => $c->coreRows->modelKeys()));
        $splices = $this->splicesAt($location)->with(['coreA', 'coreB'])->orderBy('id')->get();
        // At an ODP, cores can also end on a splitter input or an output port.
        $terminations = $location instanceof Odp
            ? CoreTermination::where('odp_id', $location->id)->with('core')->orderBy('kind')->orderBy('port')->get()
            : collect();
        // "Spliced here" means taken at this location, by a splice or a termination.
        $splicedHere = $splices->flatMap(fn (CoreSplice $s) => [$s->core_a_id, $s->core_b_id])
            ->merge($terminations->pluck('core_id'))
            ->flip();

        return response()->json([
            'odp' => $location instanceof Odp ? [
                'capacity' => $location->capacity,
                'splitter_ratio' => $location->splitter_ratio,
                'max_inputs' => CoreTermination::MAX_INPUTS,
                'ports' => $location->ports()->with('customer:id,name')->get()->map(fn (OdpPort $p) => [
                    'number' => $p->number,
                    'status' => $p->status,
                    'customer' => $p->customer?->name,
                ]),
            ] : null,
            'terminations' => $terminations->map(fn (CoreTermination $t) => [
                'id' => $t->id,
                'kind' => $t->kind,
                'port' => $t->port,
                'notes' => $t->notes,
                'core' => ['core_id' => $t->core->id, 'cable_id' => $t->core->cable_id, 'number' => $t->core->number],
            ])->values(),
            'max_splices' => CableCore::MAX_CONNECTIONS,
            'cables' => $cables->map(fn (Cable $cable) => [
                ...$cable->only('id', 'code', 'type', 'cores', 'tube_size'),
                'cores_list' => $cable->coreRows->map(fn (CableCore $core) => [
                    ...$core->only('id', 'number', 'status', 'notes'),
                    'splices' => $counts[$core->id] ?? 0,
                    'spliced_here' => $splicedHere->has($core->id),
                ])->values(),
            ])->values(),
            'splices' => $splices->map(fn (CoreSplice $s) => [
                'id' => $s->id,
                'notes' => $s->notes,
                'a' => ['core_id' => $s->coreA->id, 'cable_id' => $s->coreA->cable_id, 'number' => $s->coreA->number],
                'b' => ['core_id' => $s->coreB->id, 'cable_id' => $s->coreB->cable_id, 'number' => $s->coreB->number],
            ])->values(),
        ]);
    }

    /**
     * Splices one or more core pairs at a location; a run of consecutive cores is sent as several pairs.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canEditNetwork(), 403);
        $location = $this->location($request);

        $data = $request->validate([
            'pairs' => ['required', 'array', 'min:1', 'max:288'],
            'pairs.*.a' => ['required', 'integer'],
            'pairs.*.b' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        // Only cables that actually reach this location can be spliced here.
        $cableIds = Cable::connectedTo($location)->pluck('id')->flip();
        $coreIds = collect($data['pairs'])->flatMap(fn (array $p) => [(int) $p['a'], (int) $p['b']]);

        if ($coreIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['pairs' => 'Satu core hanya bisa dipakai sekali dalam satu penyambungan.']);
        }

        $cores = CableCore::whereKey($coreIds)->get()->keyBy('id');
        $counts = CableCore::connectionCounts($coreIds);
        $here = $this->splicesAt($location)->get()->flatMap(fn (CoreSplice $s) => [$s->core_a_id, $s->core_b_id])
            ->merge($location instanceof Odp ? CoreTermination::where('odp_id', $location->id)->pluck('core_id') : [])
            ->flip();

        foreach ($coreIds as $id) {
            $core = $cores[$id] ?? null;
            if (! $core || ! $cableIds->has($core->cable_id)) {
                throw ValidationException::withMessages(['pairs' => 'Core harus milik kabel yang tersambung ke titik ini.']);
            }
            if ($here->has($id)) {
                throw ValidationException::withMessages(['pairs' => "Core {$core->number} sudah disambung di titik ini."]);
            }
            if ($counts[$id] >= CableCore::MAX_CONNECTIONS) {
                throw ValidationException::withMessages(['pairs' => "Core {$core->number} sudah tersambung di kedua ujungnya."]);
            }
        }

        DB::transaction(function () use ($data, $location, $cores) {
            foreach ($data['pairs'] as $pair) {
                CoreSplice::create([
                    'location_type' => $location->getMorphClass(),
                    'location_id' => $location->getKey(),
                    'core_a_id' => $pair['a'],
                    'core_b_id' => $pair['b'],
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
        $location = Relation::getMorphedModel($splice->location_type)::findOrFail($splice->location_id);
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

        return response()->json($core->trace());
    }

    private function location(Request $request): Model
    {
        $data = $request->validate([
            'location_type' => ['required', Rule::in(CableRequest::ASSET_TYPES)],
            'location_id' => ['required', 'integer'],
        ]);

        return Relation::getMorphedModel($data['location_type'])::findOrFail($data['location_id']);
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
