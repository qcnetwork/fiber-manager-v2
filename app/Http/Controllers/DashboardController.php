<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\PortStatus;
use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\Cable;
use App\Models\Customer;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\OdpPort;
use App\Models\OltPort;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            ? Area::withCount(['odcs', 'odps', 'customers'])->orderBy('name')->get()->map(function (Area $area) {
                $areaPorts = OdpPort::whereHas('odp', fn ($q) => $q->where('area_id', $area->id));

                return [
                    ...$area->only('id', 'code', 'name', 'odcs_count', 'odps_count', 'customers_count'),
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

        $odps = Odp::withPortUsage()
            ->with('area:id,code')
            ->get(['id', 'area_id', 'code', 'capacity', 'status', 'lat', 'lng']);
        $almostFull = $odps
            ->filter(fn (Odp $odp) => $odp->free_ports <= 1)
            ->sortBy('free_ports')
            ->take(10)
            ->values();

        // How full the ODPs are, grouped so empty and full ones stand out.
        $fill = fn (Odp $odp) => $odp->capacity ? $odp->used_ports / $odp->capacity : 1;
        $odpFill = [
            'empty' => $odps->filter(fn (Odp $odp) => $odp->used_ports === 0)->count(),
            'low' => $odps->filter(fn (Odp $odp) => $odp->used_ports > 0 && $fill($odp) < 0.5)->count(),
            'high' => $odps->filter(fn (Odp $odp) => $odp->used_ports > 0 && $fill($odp) >= 0.5 && $odp->free_ports > 0)->count(),
            'full' => $odps->filter(fn (Odp $odp) => $odp->used_ports > 0 && $odp->free_ports === 0)->count(),
        ];

        $portStatus = OdpPort::whereHas('odp')->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');

        // New customers per month for the last 12 months (grouped here so it works on any database).
        $since = now()->startOfMonth()->subMonths(11);
        $joined = Customer::where('created_at', '>=', $since)->pluck('created_at')
            ->countBy(fn (Carbon $at) => $at->format('Y-m'));
        $growth = collect(range(0, 11))->map(function (int $i) use ($since, $joined) {
            $month = $since->copy()->addMonths($i);

            return ['month' => $month->format('Y-m'), 'count' => $joined[$month->format('Y-m')] ?? 0];
        });

        // Records that still miss something, so the team knows what to complete.
        $gaps = [
            'customers_without_location' => Customer::whereNull('lat')->count(),
            'customers_without_port' => Customer::doesntHave('port')->count(),
            'odps_without_odc' => Odp::whereNull('odc_id')->count(),
            'odcs_without_olt' => Odc::whereNull('olt_port_id')->count(),
            'odps_without_photo' => Odp::doesntHave('photos')->count(),
            'odps_not_active' => $odps->filter(fn (Odp $odp) => $odp->status !== AssetStatus::Aktif)->count(),
        ];

        $user = $request->user();
        $activity = $user->canDeleteNetwork()
            ? ActivityLog::visibleTo($user)->latest('id')->limit(8)
                ->get(['id', 'user_name', 'action', 'subject_type', 'subject_label', 'created_at'])
                ->map(fn (ActivityLog $log) => [
                    ...$log->only('id', 'user_name', 'action', 'subject_type', 'subject_label'),
                    'created_at' => $log->created_at->toIso8601String(),
                ])
            : null;

        return Inertia::render('Dashboard', [
            'odpFill' => $odpFill,
            'portStatus' => collect(PortStatus::cases())->mapWithKeys(fn (PortStatus $s) => [$s->value => (int) ($portStatus[$s->value] ?? 0)]),
            'growth' => $growth,
            'gaps' => $gaps,
            'activity' => $activity,
            'stats' => [
                'olts' => $pops->sum('olts_count'),
                'pon_ports' => $pops->sum('total_ports'),
                'pon_used' => $pops->sum('used_ports'),
                'customers_this_month' => $growth->last()['count'],
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
