<?php

namespace App\Http\Middleware;

use App\Services\ChangeRequestService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Technicians' changes to network data become requests that wait for an admin's approval.
 *
 * The change is first run through its controller inside a transaction that is rolled back, so the usual
 * validation and side effects apply and the preview shows exactly what would change. Approving replays the
 * stored request through the same route (marked with REPLAY so it is not held again).
 *
 * For approvers it also finishes a request they edited on the map before saving it (`change_request_id`).
 */
class HoldForApproval
{
    public const REPLAY = 'approval.replay';

    public function __construct(private ChangeRequestService $requests) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->attributes->get(self::REPLAY) || ! $user) {
            return $next($request);
        }

        if ($user->needsApproval()) {
            return $this->requests->hold($request, $next);
        }

        if ($user->canApprove() && $request->filled('change_request_id')) {
            return $this->requests->saveEdited($request, $next);
        }

        return $next($request);
    }
}
