<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Cable;
use App\Models\Customer;
use App\Models\Odc;
use App\Models\SpliceBox;
use App\Models\Odp;
use App\Models\OltPort;
use App\Services\CoverageService;
use App\Services\GoogleMapsLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class MapController extends Controller
{
    /**
     * Maximum markers per layer returned for one viewport.
     */
    private const FEATURE_LIMIT = 3000;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $areas = $user->seesAllAreas() ? Area::orderBy('name')->get() : Area::whereKey($user->area_id)->get();

        return Inertia::render('Map', [
            'areas' => $areas->map->only('id', 'code', 'name', 'center_lat', 'center_lng'),
            'coverageRadius' => config('odp.coverage_radius'),
            'can' => [
                'edit' => $user->canEditNetwork(),
                'delete' => $user->canDeleteNetwork(),
                'chooseArea' => $user->seesAllAreas(),
            ],
        ]);
    }

    /**
     * ODC and ODP markers inside the visible map bounds.
     */
    public function features(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bbox' => ['required', 'regex:/^-?[\d.]+,-?[\d.]+,-?[\d.]+,-?[\d.]+$/'],
            'area_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
        ]);

        [$west, $south, $east, $north] = array_map('floatval', explode(',', $data['bbox']));

        $inView = fn ($query) => $query
            ->whereBetween('lat', [$south, $north])
            ->whereBetween('lng', [$west, $east])
            ->when($data['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->limit(self::FEATURE_LIMIT);

        $odcs = $inView(Odc::query())
            ->withCount('odps')
            ->with('oltPort:id,olt_id', 'oltPort.olt:id,area_id')
            ->get(['id', 'olt_port_id', 'code', 'name', 'lat', 'lng', 'status', 'capacity']);

        $pops = $request->user()->isSales() ? collect() : $this->popsInView($request, $data, [$west, $south, $east, $north]);
        $popLocations = Area::whereIn('id', $odcs->pluck('oltPort.olt.area_id')->filter()->unique())->get(['id', 'center_lat', 'center_lng'])->keyBy('id');

        $odps = $inView(Odp::query())
            ->withPortUsage()
            ->with('odc:id,lat,lng')
            ->get(['id', 'odc_id', 'code', 'name', 'lat', 'lng', 'status', 'capacity']);

        // Joint boxes/rosettes are route details: not shown on the sales coverage map.
        $joints = $request->user()->isSales() ? collect() : $inView(SpliceBox::query())
            ->get(['id', 'type', 'code', 'name', 'lat', 'lng', 'status']);

        // A cable is in view when its bounding box overlaps the viewport.
        $cables = Cable::query()
            ->where('min_lat', '<=', $north)->where('max_lat', '>=', $south)
            ->where('min_lng', '<=', $east)->where('max_lng', '>=', $west)
            ->when($data['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->limit(self::FEATURE_LIMIT)
            ->get(['id', 'code', 'type', 'cores', 'status', 'path', 'length_m']);

        return response()->json([
            'cables' => $cables,
            'pops' => $pops,
            'odcs' => $odcs->map(function (Odc $odc) use ($popLocations) {
                $pop = $popLocations[$odc->oltPort?->olt?->area_id] ?? null;

                return [
                    ...$odc->only('id', 'code', 'name', 'lat', 'lng', 'status', 'capacity', 'odps_count'),
                    // For the guide line from the POP feeding this ODC.
                    'pop_latlng' => $pop?->center_lat !== null ? [$pop->center_lat, $pop->center_lng] : null,
                ];
            }),
            'odps' => $odps->map(fn (Odp $odp) => [
                ...$odp->only('id', 'odc_id', 'code', 'name', 'lat', 'lng', 'status', 'capacity', 'used_ports', 'free_ports'),
                'odc_latlng' => $odp->odc ? [$odp->odc->lat, $odp->odc->lng] : null,
            ]),
            'joints' => $joints,
            'truncated' => $odcs->count() >= self::FEATURE_LIMIT || $odps->count() >= self::FEATURE_LIMIT || $joints->count() >= self::FEATURE_LIMIT,
        ]);
    }

    /**
     * POPs (areas with a location) in view, with their OLT and PON port usage.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox  west, south, east, north
     */
    private function popsInView(Request $request, array $data, array $bbox): Collection
    {
        [$west, $south, $east, $north] = $bbox;
        $user = $request->user();

        $pops = Area::query()
            ->whereBetween('center_lat', [$south, $north])
            ->whereBetween('center_lng', [$west, $east])
            ->when(! $user->seesAllAreas(), fn ($q) => $q->whereKey($user->area_id))
            ->when($data['area_id'] ?? null, fn ($q, $areaId) => $q->whereKey($areaId))
            ->withCount('olts')
            ->get(['id', 'code', 'name', 'center_lat', 'center_lng']);

        $ports = OltPort::query()
            ->join('olts', 'olts.id', '=', 'olt_ports.olt_id')
            ->whereIn('olts.area_id', $pops->modelKeys())
            ->select('olt_ports.id', 'olts.area_id')
            ->withCount('odcs')
            ->get()
            ->groupBy('area_id');

        return $pops->map(fn (Area $pop) => [
            ...$pop->only('id', 'code', 'name', 'olts_count'),
            'lat' => $pop->center_lat,
            'lng' => $pop->center_lng,
            'ports_total' => $ports->get($pop->id)?->count() ?? 0,
            'ports_used' => $ports->get($pop->id)?->where('odcs_count', '>', 0)->count() ?? 0,
        ]);
    }

    /**
     * ODC/ODP whose code or name matches, and customers by name, ID or phone, for jumping to them on the map.
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $user = $request->user();
        $like = "%{$term}%";

        /*
         * Matches any of $columns; rows whose $primary columns (code/name) match come first, then the rest
         * (address, notes, ...). The matching secondary field is returned as `detail` so the user sees why it was found.
         */
        $find = function ($query, array $primary, array $secondary, int $limit) use ($like) {
            $hitPrimary = implode(' or ', array_map(fn ($c) => "{$c} like ?", $primary));

            return $query
                ->where(fn ($q) => collect([...$primary, ...$secondary])->each(fn ($c) => $q->orWhere($c, 'like', $like)))
                ->orderByRaw("case when {$hitPrimary} then 0 else 1 end", array_fill(0, count($primary), $like))
                ->orderBy($primary[0])
                ->limit($limit);
        };
        $detail = function ($model, array $secondary, array $primary = ['code', 'name']) use ($term): ?string {
            // Found by code/name: the result already shows why.
            foreach ($primary as $column) {
                if (mb_stripos((string) $model->{$column}, $term) !== false) {
                    return null;
                }
            }

            foreach ($secondary as $column => $label) {
                $value = (string) $model->{$column};
                $at = mb_stripos($value, $term);
                if ($at === false) {
                    continue;
                }
                // A short window around the match, so long notes stay on one line.
                $start = max(0, $at - 30);
                $snippet = mb_substr($value, $start, 90);

                return "{$label}: ".($start > 0 ? '…' : '').preg_replace('/\s+/', ' ', $snippet).($start + 90 < mb_strlen($value) ? '…' : '');
            }

            return null;
        };

        $assetFields = ['address' => 'Alamat', 'notes' => 'Catatan'];
        $odcs = $find(Odc::query(), ['code', 'name'], array_keys($assetFields), 10)
            ->get(['id', 'code', 'name', 'lat', 'lng', 'address', 'notes']);
        $odpFields = [...$assetFields, 'pole_number' => 'Tiang'];
        $odps = $find(Odp::query(), ['code', 'name'], array_keys($odpFields), 10)
            ->get(['id', 'code', 'name', 'lat', 'lng', 'address', 'notes', 'pole_number']);

        $joints = $user->isSales() ? collect() : $find(SpliceBox::query(), ['code', 'name'], array_keys($odpFields), 10)
            ->get(['id', 'type', 'code', 'name', 'lat', 'lng', 'address', 'notes', 'pole_number']);

        // Sales can search the network but never customers.
        $customerFields = ['address' => 'Alamat', 'ont_sn' => 'SN ONT', 'package' => 'Paket'];
        $customers = $user->isSales() ? collect() : $find(Customer::query(), ['name', 'customer_code', 'phone'], array_keys($customerFields), 10)
            ->with('port.odp:id,code,lat,lng')
            ->get(['id', 'name', 'customer_code', 'phone', 'lat', 'lng', 'address', 'ont_sn', 'package']);

        // POPs by area code/name/address/notes, and by the code of an OLT there.
        $pops = $user->isSales() ? collect() : Area::query()
            ->whereNotNull('center_lat')
            ->when(! $user->seesAllAreas(), fn ($q) => $q->whereKey($user->area_id))
            ->where(fn ($q) => $q->where('code', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('address', 'like', $like)
                ->orWhere('notes', 'like', $like)
                ->orWhereHas('olts', fn ($o) => $o->where('code', 'like', $like)))
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'code', 'name', 'center_lat', 'center_lng', 'address', 'notes']);

        // Cables by code or notes; shown at the middle of their route.
        $cables = $user->isSales() ? collect() : $find(Cable::query(), ['code'], ['notes'], 5)
            ->get(['id', 'code', 'type', 'min_lat', 'max_lat', 'min_lng', 'max_lng', 'notes']);

        return response()->json([
            ...$pops->map(fn (Area $pop) => [
                'kind' => 'pop', 'id' => $pop->id, 'code' => $pop->name, 'name' => $pop->code, 'lat' => $pop->lat, 'lng' => $pop->lng,
                'detail' => $detail($pop, $assetFields),
            ]),
            ...$odcs->map(fn (Odc $odc) => [
                'kind' => 'odc', 'id' => $odc->id, 'code' => $odc->code, 'name' => $odc->name, 'lat' => $odc->lat, 'lng' => $odc->lng,
                'detail' => $detail($odc, $assetFields),
            ]),
            ...$odps->map(fn (Odp $odp) => [
                'kind' => 'odp', 'id' => $odp->id, 'code' => $odp->code, 'name' => $odp->name, 'lat' => $odp->lat, 'lng' => $odp->lng,
                'detail' => $detail($odp, $odpFields),
            ]),
            ...$joints->map(fn (SpliceBox $joint) => [
                'kind' => 'joint', 'id' => $joint->id, 'code' => $joint->code, 'name' => $joint->type->label(), 'lat' => $joint->lat, 'lng' => $joint->lng,
                'detail' => $detail($joint, $odpFields),
            ]),
            ...$customers->map(fn (Customer $c) => [
                'kind' => 'customer',
                'id' => $c->id,
                'code' => $c->name,
                'name' => collect([$c->customer_code, $c->port?->odp ? "{$c->port->odp->code} port {$c->port->number}" : null])->filter()->implode(' · ') ?: null,
                'detail' => $detail($c, $customerFields, ['name', 'customer_code', 'phone']),
                // Customers without a location are found at their ODP.
                'lat' => $c->lat ?? $c->port?->odp?->lat,
                'lng' => $c->lng ?? $c->port?->odp?->lng,
            ]),
            ...$cables->map(fn (Cable $cable) => [
                'kind' => 'cable',
                'id' => $cable->id,
                'code' => $cable->code ?: "Kabel #{$cable->id}",
                'name' => $cable->type,
                'detail' => $detail($cable, ['notes' => 'Catatan'], ['code']),
                'lat' => ((float) $cable->min_lat + (float) $cable->max_lat) / 2,
                'lng' => ((float) $cable->min_lng + (float) $cable->max_lng) / 2,
            ]),
        ]);
    }

    /**
     * Coordinates from a Google Maps link, following short-link redirects.
     */
    public function resolveLink(Request $request, GoogleMapsLink $links): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2000']]);

        try {
            return response()->json($links->resolve($data['url']));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * ODPs around a point, closest first, for checking coverage of a new customer.
     */
    public function nearest(Request $request, CoverageService $coverage): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'integer', 'min:10', 'max:5000'],
            'only_available' => ['nullable', 'boolean'],
        ]);

        $radius = $data['radius'] ?? $coverage->radius();

        $odps = $coverage->odpsNear((float) $data['lat'], (float) $data['lng'], $radius)
            ->when($request->boolean('only_available', true), fn ($c) => $c->where('free_ports', '>', 0))
            ->values()
            ->take(20);

        return response()->json(['radius' => $radius, 'odps' => $odps]);
    }
}
