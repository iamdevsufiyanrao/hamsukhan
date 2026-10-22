<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer pages need a verified email, but only once the super admin switches it on
 * (Settings → Site), so accounts are never locked out while outgoing mail is not set up yet.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_admin && ! $user->hasVerifiedEmail() && config('hamsukhan.site.require_email_verification') && ! session('impersonator_id')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Please verify your email first.'], 403)
                : redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
