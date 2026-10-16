<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Suspended accounts are signed out, unless a super admin is viewing the account. */
class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->isSuspended() && ! $request->session()->has('impersonator_id')) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account is suspended. Contact support.']);
        }

        return $next($request);
    }
}
