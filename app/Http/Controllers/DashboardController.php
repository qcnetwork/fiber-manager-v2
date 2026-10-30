<?php

namespace App\Http\Controllers;

use App\Enums\PortStatus;
use App\Models\Area;
use App\Models\Cable;
use App\Models\Customer;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\OdpPort;
use App\Models\OltPort;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $ports = OdpPort::whereHas('odp');
        $totalPorts = (clone $ports)->count();
        $usedPorts = (clone $ports)->where('status', '!=', PortStatus::Kosong)->count();

        $areas = $request->user()->seesAllAreas()
            ? Area::withCount(['odcs', 'odps'])->orderBy('name')->get()->map(function (Area $area) {
                $areaPorts = OdpPort::whereHas('odp', fn ($q) => $q->where('area_id', $area->id));

                return [
                    ...$area->only('id', 'code', 'name', 'odcs_count', 'odps_count'),
                    'total_ports' => (clone $areaPorts)->count(),
                    'used_ports' => (clone $areaPorts)->where('status', '!=', PortStatus::Kosong)->count(),
                ];
            })
            : null;

        // PON ports per POP: a port is in use when at least one ODC hangs off it.
        $ponPorts = OltPort::query()
            ->join('olts', 'olts.id', '=', 'olt_ports.olt_id')
            ->whereHas('olt') // area scope of the OLT
            ->select('olt_ports.id', 'olt_ports.status', 'olts.area_id')
            ->withCount('odcs')
            ->get()
            ->groupBy('area_id');
        $pops = Area::whereIn('id', $ponPorts->keys())->withCount('olts')->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn (Area $pop) => [
                ...$pop->only('id', 'code', 'name', 'olts_count'),
                'total_ports' => $ponPorts[$pop->id]->count(),
                'used_ports' => $ponPorts[$pop->id]->where('odcs_count', '>', 0)->count(),
                'broken_ports' => $ponPorts[$pop->id]->where('status', 'rusak')->count(),
            ]);

        $almostFull = Odp::withPortUsage()
            ->with('area:id,code')
            ->get(['id', 'area_id', 'code', 'capacity', 'lat', 'lng'])
            ->filter(fn (Odp $odp) => $odp->free_ports <= 1)
            ->sortBy('free_ports')
            ->take(10)
            ->values();

        return Inertia::render('Dashboard', [
            'stats' => [
                'odcs' => Odc::count(),
                'odps' => Odp::count(),
                'customers' => Customer::count(),
                'total_ports' => $totalPorts,
                'used_ports' => $usedPorts,
                'cable_m' => (int) Cable::sum('length_m'),
            ],
            'cableByType' => Cable::selectRaw('type, count(*) as count, sum(length_m) as length_m')
                ->groupBy('type')
                ->get()
                ->map(fn (Cable $c) => ['type' => $c->type->value, 'count' => (int) $c->count, 'length_m' => (int) $c->length_m]),
            'areas' => $areas,
            'pops' => $pops,
            'almostFull' => $almostFull,
        ]);
    }
}
