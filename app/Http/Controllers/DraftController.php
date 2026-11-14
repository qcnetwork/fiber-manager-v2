<?php

namespace App\Http\Controllers;

use App\Models\ChangeRequest;
use App\Services\ChangeRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A technician's drafts: shown on their map until they send them together for approval.
 */
class DraftController extends Controller
{
    public function __construct(private ChangeRequestService $requests) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->needsApproval(), 403);

        return response()->json(['items' => $this->requests->overlay(ChangeRequest::draftsOf($request->user())->get())]);
    }

    public function submit(Request $request): RedirectResponse
    {
        abort_unless($request->user()->needsApproval(), 403);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        $error = $this->requests->submit($request->user(), $note);
        if ($error !== null) {
            return back()->with('noticeError', $error);
        }

        return back()->with('notice', 'Draft dikirim ke approval. Kamu akan melihat hasilnya di menu Request Saya.');
    }

    public function destroy(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $draft = ChangeRequest::draftsOf($request->user())->findOrFail($changeRequest->id);

        $error = $this->requests->discardDraft($draft);
        if ($error !== null) {
            return back()->with('noticeError', $error);
        }

        return back()->with('notice', 'Draft dihapus.');
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        $this->requests->discardAll($request->user());

        return back()->with('notice', 'Semua draft dibuang.');
    }
}
