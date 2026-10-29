<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sales accounts only check coverage; they don't see customers, ports or exports.
 * Their page visits go to the coverage page instead.
 */
class EnsureNotSales
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role === Role::Sales) {
            abort_if($request->expectsJson() || ! $request->isMethod('GET'), 403);

            return redirect()->route('coverage');
        }

        return $next($request);
    }
}
