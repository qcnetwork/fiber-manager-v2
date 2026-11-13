<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\ChangeRequest;
use App\Models\OdpPort;
use App\Models\Photo;
use App\Services\ChangeRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Change requests from technicians: approvers approve, reject or edit them; technicians follow their own.
 */
class ApprovalController extends Controller
{
    public function __construct(private ChangeRequestService $requests) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canApprove() || $user->needsApproval(), 403);

        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['pending', 'history'])],
            'area_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'max:30'],
        ]);
        $tab = $filters['tab'] ?? 'pending';

        $query = ChangeRequest::visibleTo($user)
            ->with(['user:id,name', 'reviewer:id,name', 'area:id,code,name'])
            ->when($tab === 'pending', fn ($q) => $q->pending()->oldest(), fn ($q) => $q->where('status', '!=', ChangeRequest::PENDING)->latest('reviewed_at')->latest('id'))
            ->when($filters['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('subject_type', $type));

        $page = $query->paginate(20)->withQueryString();
        $page->getCollection()->transform(fn (ChangeRequest $cr) => $this->present($cr, $user->canApprove()));

        // Technicians have now seen what was decided on their requests.
        if ($user->needsApproval()) {
            ChangeRequest::where('user_id', $user->id)->whereNull('seen_at')->where('status', '!=', ChangeRequest::PENDING)->update(['seen_at' => now()]);
        }

        return Inertia::render('Approvals', [
            'requests' => $page,
            'filters' => ['tab' => $tab, 'area_id' => $filters['area_id'] ?? null, 'type' => $filters['type'] ?? null],
            'areas' => $user->seesAllAreas() && $user->canApprove() ? Area::orderBy('name')->get(['id', 'code', 'name']) : [],
            'canApprove' => $user->canApprove(),
        ]);
    }

    /**
     * The stored request, for opening it in the map form before approving.
     */
    public function show(Request $request, ChangeRequest $changeRequest): JsonResponse
    {
        $cr = $this->reviewable($request, $changeRequest);
        abort_unless(in_array($cr->route_name, ChangeRequestService::EDITABLE_ROUTES, true), 404);

        $params = $cr->route_params ?? [];
        $context = [];
        if ($cr->route_name === 'ports.update') {
            $context['odp_id'] = OdpPort::whereKey($params['port'] ?? null)->value('odp_id');
        }

        return response()->json(['request' => [
            'id' => $cr->id,
            'route_name' => $cr->route_name,
            'route_params' => $params,
            'payload' => $cr->payload ?? [],
            'user' => $cr->user?->name,
            'subject_label' => $cr->subject_label,
            'context' => $context,
        ]]);
    }

    public function approve(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $cr = $this->reviewable($request, $changeRequest);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        $error = $this->requests->approve($cr, $request->user(), $note);
        if ($error !== null) {
            return back()->with('noticeError', "Request #{$cr->id} gagal diterapkan: {$error}");
        }

        return back()->with('notice', "Request #{$cr->id} disetujui.");
    }

    public function reject(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $cr = $this->reviewable($request, $changeRequest);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        $this->requests->reject($cr, $request->user(), $note);

        return back()->with('notice', "Request #{$cr->id} ditolak.");
    }

    /**
     * A technician withdraws their own pending request.
     */
    public function cancel(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        abort_unless($changeRequest->user_id === $request->user()->id, 404);
        abort_unless($changeRequest->isPending(), 422, 'Request ini sudah diproses.');

        $this->requests->cancel($changeRequest);

        return back()->with('notice', "Request #{$changeRequest->id} dibatalkan.");
    }

    /**
     * A photo waiting in a request, for whoever may see the request.
     */
    public function file(Request $request, ChangeRequest $changeRequest, int $index): StreamedResponse
    {
        $cr = ChangeRequest::visibleTo($request->user())->findOrFail($changeRequest->id);
        $path = $cr->files[$index]['path'] ?? null;
        abort_unless($path && Storage::disk(Photo::DISK)->exists($path), 404);

        return Storage::disk(Photo::DISK)->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    /**
     * A pending request the user may decide on; anything else is a 404.
     */
    private function reviewable(Request $request, ChangeRequest $changeRequest): ChangeRequest
    {
        $cr = ChangeRequest::reviewableBy($request->user())->findOrFail($changeRequest->id);
        abort_unless($cr->isPending(), 422, 'Request ini sudah diproses.');

        return $cr;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChangeRequest $cr, bool $canApprove): array
    {
        return [
            ...$cr->only('id', 'action', 'subject_type', 'subject_id', 'subject_label', 'status', 'preview', 'review_note'),
            'created_at' => $cr->created_at?->toIso8601String(),
            'reviewed_at' => $cr->reviewed_at?->toIso8601String(),
            'user' => $cr->user?->only('id', 'name'),
            'reviewer' => $cr->reviewer?->only('id', 'name'),
            'area' => $cr->area?->only('id', 'code', 'name'),
            'photos' => collect($cr->isPending() ? ($cr->files ?? []) : [])->keys()
                ->map(fn (int $i) => route('approvals.file', [$cr->id, $i]))->all(),
            'conflict' => $canApprove ? $cr->conflict() : null,
            'editable' => $canApprove && $cr->isPending() && in_array($cr->route_name, ChangeRequestService::EDITABLE_ROUTES, true),
            'map' => $this->mapLink($cr),
        ];
    }

    /**
     * Where the request's asset is on the map, if it can be shown there.
     */
    private function mapLink(ChangeRequest $cr): ?string
    {
        $kind = match ($cr->subject_type) {
            'odc', 'odp', 'joint', 'cable', 'customer', 'pop' => $cr->subject_type,
            default => null,
        };
        if ($kind && $cr->subject_id) {
            return route('map', ['select' => "{$kind}:{$cr->subject_id}"]);
        }

        // A new asset: where it would be placed.
        $payload = $cr->payload ?? [];
        if (isset($payload['lat'], $payload['lng']) && is_numeric($payload['lat']) && is_numeric($payload['lng'])) {
            return route('map', ['at' => "{$payload['lat']},{$payload['lng']}"]);
        }

        return null;
    }
}
