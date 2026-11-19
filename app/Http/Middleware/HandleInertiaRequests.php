<?php

namespace App\Http\Middleware;

use App\Models\ChangeBatch;
use App\Models\ChangeRequest;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user()?->load('area:id,code,name');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'isSuperAdmin' => (bool) $user?->isSuperAdmin(),
                'isAdministrator' => (bool) $user?->isAdministrator(),
                'needsApproval' => (bool) $user?->needsApproval(),
                'canApprove' => (bool) $user?->canApprove(),
            ],
            // Badge counts: batches waiting for an approver; a technician's unsent drafts and decisions not seen yet.
            'approvals' => fn () => $user ? [
                'pending' => $user->canApprove() ? ChangeBatch::reviewableBy($user)->pending()->count() : 0,
                'decided_unseen' => $user->needsApproval()
                    ? ChangeBatch::where('user_id', $user->id)->where('status', '!=', ChangeBatch::PENDING)->whereNull('seen_at')->count()
                    : 0,
                'drafts' => $user->needsApproval() ? ChangeRequest::draftsOf($user)->count() : 0,
            ] : null,
            'flash' => [
                'importResult' => fn () => $request->session()->get('importResult'),
                'notice' => fn () => $request->session()->get('notice'),
                'noticeError' => fn () => $request->session()->get('noticeError'),
            ],
        ]);
    }
}
