<?php

namespace App\Http\Controllers;

use App\Http\Requests\CableRequest;
use App\Models\Area;
use App\Models\Cable;
use App\Models\CableCore;
use App\Models\CoreSplice;
use App\Models\SpliceBox;
use Illuminate\Database\Eloquent\Relations\Relation;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Printable splice sheets: every splice at a joint box/rosette, ODC, ODP or POP, or every core of a cable,
 * each with the full path of the fiber from where it starts to where it ends.
 */
class SpliceSheetController extends Controller
{
    public function show(string $kind, int $id): Response
    {
        abort_unless($kind === 'cable' || in_array($kind, CableRequest::ASSET_TYPES, true), 404);

        return $kind === 'cable' ? $this->cableSheet($id) : $this->locationSheet($kind, $id);
    }

    /**
     * One row per splice made at the location.
     */
    private function locationSheet(string $kind, int $id): Response
    {
        // Resolving through the area scope guards access.
        $location = Relation::getMorphedModel($kind)::findOrFail($id);

        $splices = CoreSplice::where('location_type', $kind)->where('location_id', $id)->with(['coreA', 'coreB'])->get();
        $cables = Cable::connectedTo($location)->orderBy('code')->get(['id', 'code', 'type', 'cores', 'tube_size']);
        $splicedPerCable = $splices->flatMap(fn (CoreSplice $s) => [$s->coreA, $s->coreB])->countBy('cable_id');

        $rows = $splices
            ->map(fn (CoreSplice $s) => [
                'splice_id' => $s->id,
                'notes' => $s->notes,
                'a' => $s->coreA->describe(),
                'b' => $s->coreB->describe(),
                'trace' => $s->coreA->trace(),
            ])
            // Grouped by the first cable, in core order, like a paper splice sheet.
            ->sortBy(fn (array $row) => sprintf('%s|%05d', $row['a']['cable']['code'] ?? '~'.$row['a']['cable']['id'], $row['a']['number']))
            ->values();

        return Inertia::render('SpliceSheet', [
            'sheet' => [
                'kind' => $kind,
                'id' => $id,
                'title' => $location instanceof Area ? $location->name : $location->code,
                'subtitle' => match (true) {
                    $location instanceof SpliceBox => $location->type->label(),
                    $location instanceof Area => 'POP',
                    default => strtoupper($kind),
                },
                'address' => $location->address,
                'area' => $location instanceof Area ? $location->name : $location->area?->name,
            ],
            'cables' => $cables->map(fn (Cable $c) => [
                ...$c->only('id', 'code', 'type', 'cores', 'tube_size'),
                'spliced_here' => $splicedPerCable[$c->id] ?? 0,
            ]),
            'rows' => $rows,
            'generatedAt' => now()->format('d-m-Y H:i'),
        ]);
    }

    /**
     * One row per core of the cable, traced end to end.
     */
    private function cableSheet(int $id): Response
    {
        $cable = Cable::with(['area:id,name', 'coreRows'])->findOrFail($id);

        return Inertia::render('SpliceSheet', [
            'sheet' => [
                'kind' => 'cable',
                'id' => $cable->id,
                'title' => $cable->code ?? "Kabel #{$cable->id}",
                'subtitle' => "Kabel {$cable->type->value} · {$cable->cores} core",
                'address' => null,
                'area' => $cable->area?->name,
            ],
            'cables' => [[...$cable->only('id', 'code', 'type', 'cores', 'tube_size'), 'spliced_here' => 0]],
            'rows' => $cable->coreRows->map(fn (CableCore $core) => [
                'splice_id' => null,
                'notes' => null,
                'a' => $core->describe(),
                'b' => null,
                'trace' => $core->trace(),
            ])->values(),
            'generatedAt' => now()->format('d-m-Y H:i'),
        ]);
    }
}
