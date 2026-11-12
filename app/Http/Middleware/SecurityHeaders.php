<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers on every response.
 *
 * The CSP only blocks framing, plugins and <base> tricks; scripts are not restricted because the map
 * loads tiles and services from several outside hosts.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(self), camera=(self), microphone=()');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'; object-src 'none'; base-uri 'self'");

        return $response;
    }
}
