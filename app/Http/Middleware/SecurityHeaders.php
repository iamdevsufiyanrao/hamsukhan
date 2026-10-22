<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for every Laravel response (widget.js is a static file and is not affected).
 * No CSP yet: the Vapi SDK loads from esm.sh and opens Daily.co media connections; add one only after testing a real call with it.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;

        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Our own pages need the microphone (voice cloning, test calls, demo); nothing else may use it.
        $h->set('Permissions-Policy', 'microphone=(self), camera=(), geolocation=(), payment=()');
        if (! $request->is('api/*')) {
            $h->set('X-Frame-Options', 'SAMEORIGIN');
        }
        if ($request->isSecure() && app()->isProduction()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
