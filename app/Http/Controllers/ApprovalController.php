<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\ChangeBatch;
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
 * Batches of changes from technicians: approvers approve all or some of a batch (the rest is rejected) or reject
 * it as a whole, and may change an item first; technicians follow their own.
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
        ]);
        $tab = $filters['tab'] ?? 'pending';

        $page = ChangeBatch::visibleTo($user)
            ->with(['user:id,name', 'reviewer:id,name', 'area:id,code,name', 'items'])
            ->when($tab === 'pending', fn ($q) => $q->pending()->oldest('submitted_at'), fn ($q) => $q->where('status', '!=', ChangeBatch::PENDING)->latest('reviewed_at')->latest('id'))
            ->when($filters['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->paginate(10)
            ->withQueryString();
        $page->getCollection()->transform(fn (ChangeBatch $batch) => $this->present($batch, $user->canApprove()));

        // Technicians have now seen what was decided on their batches.
        if ($user->needsApproval()) {
            ChangeBatch::where('user_id', $user->id)->whereNull('seen_at')->where('status', '!=', ChangeBatch::PENDING)->update(['seen_at' => now()]);
        }

        return Inertia::render('Approvals', [
            'batches' => $page,
            'filters' => ['tab' => $tab, 'area_id' => $filters['area_id'] ?? null],
            'areas' => $user->seesAllAreas() && $user->canApprove() ? Area::orderBy('name')->get(['id', 'code', 'name']) : [],
            'canApprove' => $user->canApprove(),
        ]);
    }

    /**
     * One change of a pending batch with the rest of the batch, for opening it in its map form.
     */
    public function show(Request $request, ChangeRequest $changeRequest): JsonResponse
    {
        $item = ChangeRequest::where('status', ChangeRequest::PENDING)
            ->whereHas('batch', fn ($q) => $q->reviewableBy($request->user())->pending())
            ->findOrFail($changeRequest->id);
        abort_unless(in_array($item->route_name, ChangeRequestService::EDITABLE_ROUTES, true), 404);

        $params = $item->route_params ?? [];

        return response()->json([
            'request' => [
                'id' => $item->id,
                'batch_id' => $item->batch_id,
                'route_name' => $item->route_name,
                'route_params' => $params,
                'payload' => $item->payload ?? [],
                'user' => $item->user?->name,
                'subject_label' => $item->subject_label,
                'context' => $item->route_name === 'ports.update' ? ['odp_id' => OdpPort::whereKey($params['port'] ?? null)->value('odp_id')] : [],
            ],
            // New assets of the batch, so the form can refer to them.
            'overlay' => $this->requests->overlay($item->batch->items()->get()),
        ]);
    }

    /**
     * A batch's changes on the map, for whoever may see the batch.
     */
    public function overlay(Request $request, ChangeBatch $batch): JsonResponse
    {
        $batch = ChangeBatch::visibleTo($request->user())->findOrFail($batch->id);

        return response()->json(['batch_id' => $batch->id, 'items' => $this->requests->overlay($batch->items()->get())]);
    }

    public function approve(Request $request, ChangeBatch $batch): RedirectResponse
    {
        $batch = $this->reviewable($request, $batch);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            // The changes to approve; the rest of the batch is rejected. Left out: all of them.
            'items' => ['sometimes', 'array'],
            'items.*' => ['integer', Rule::exists('change_requests', 'id')->where('batch_id', $batch->id)],
        ]);
        $itemIds = isset($data['items']) ? array_map('intval', $data['items']) : null;

        $error = $this->requests->approveBatch($batch, $request->user(), $data['note'] ?? null, $itemIds);
        if ($error !== null) {
            return back()->with('noticeError', "Paket #{$batch->id} gagal diterapkan, tidak ada yang disimpan — {$error}");
        }

        $rejected = $batch->items()->where('status', ChangeRequest::REJECTED)->count();

        return back()->with('notice', $rejected
            ? "Paket #{$batch->id} disetujui sebagian, {$rejected} perubahan ditolak."
            : "Paket #{$batch->id} disetujui.");
    }

    public function reject(Request $request, ChangeBatch $batch): RedirectResponse
    {
        $batch = $this->reviewable($request, $batch);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        $this->requests->rejectBatch($batch, $request->user(), $note);

        return back()->with('notice', "Paket #{$batch->id} ditolak.");
    }

    /**
     * A technician withdraws their own pending batch.
     */
    public function cancel(Request $request, ChangeBatch $batch): RedirectResponse
    {
        abort_unless($batch->user_id === $request->user()->id, 404);
        abort_unless($batch->isPending(), 422, 'Paket ini sudah diproses.');

        $this->requests->cancelBatch($batch);

        return back()->with('notice', "Paket #{$batch->id} dibatalkan.");
    }

    /**
     * A photo waiting in a change: for its author while it is a draft, else for whoever may see its batch.
     */
    public function file(Request $request, ChangeRequest $changeRequest, int $index): StreamedResponse
    {
        $user = $request->user();
        $visible = $changeRequest->batch_id
            ? ChangeBatch::visibleTo($user)->whereKey($changeRequest->batch_id)->exists()
            : $changeRequest->user_id === $user->id;
        abort_unless($visible, 404);

        $path = $changeRequest->files[$index]['path'] ?? null;
        abort_unless($path && Storage::disk(Photo::DISK)->exists($path), 404);

        return Storage::disk(Photo::DISK)->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    /**
     * A pending batch the user may decide on; anything else is a 404.
     */
    private function reviewable(Request $request, ChangeBatch $batch): ChangeBatch
    {
        $batch = ChangeBatch::reviewableBy($request->user())->findOrFail($batch->id);
        abort_unless($batch->isPending(), 422, 'Paket ini sudah diproses.');

        return $batch;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChangeBatch $batch, bool $canApprove): array
    {
        $pending = $batch->isPending();

        return [
            ...$batch->only('id', 'status', 'note', 'review_note'),
            'submitted_at' => $batch->submitted_at?->toIso8601String(),
            'reviewed_at' => $batch->reviewed_at?->toIso8601String(),
            'user' => $batch->user?->only('id', 'name'),
            'reviewer' => $batch->reviewer?->only('id', 'name'),
            'area' => $batch->area?->only('id', 'code', 'name'),
            'map' => $pending ? route('map', ['batch' => $batch->id]) : null,
            'items' => $batch->items->map(fn (ChangeRequest $item) => [
                ...$item->only('id', 'status', 'action', 'subject_type', 'subject_id', 'subject_label', 'preview'),
                // Changes of the batch this one builds on: those must be approved along with it.
                'needs' => $pending ? $batch->items->filter(fn (ChangeRequest $other) => $this->requests->buildsOn($item, $other))->modelKeys() : [],
                // A change to an existing photo: the photo as it is now.
                'current_photo' => $pending && $item->subject_type === 'photo' && $item->subject_id
                    ? Photo::find($item->subject_id)?->thumb_url : null,
                'photos' => collect($pending ? ($item->files ?? []) : [])->keys()
                    ->map(fn (int $i) => route('approvals.file', [$item->id, $i]))->all(),
                'conflict' => $canApprove && $pending ? $item->conflict() : null,
                'editable' => $canApprove && $pending && in_array($item->route_name, ChangeRequestService::EDITABLE_ROUTES, true),
            ])->values(),
        ];
    }
}
