<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What team members may do in their owner's account:
 * - admin: everything except billing (the owner's wallet) and the team itself
 * - operator: view everything, work leads (status, AI call back), live calls, test calls
 * - viewer: view only
 * The account owner (no team_role) is never restricted here.
 */
class EnsureTeamPermission
{
    private const OWNER_ONLY = ['billing', 'top-ups.store'];

    private const OPERATOR_WRITES = ['bookings.update', 'bookings.ai-callback', 'live-calls.say', 'live-calls.instruct', 'live-calls.end', 'test-call.finish'];

    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->user()?->team_role;
        if (! $role) {
            return $next($request);
        }
        $name = (string) $request->route()?->getName();

        $allowed = match (true) {
            in_array($name, self::OWNER_ONLY, true) => false,
            $request->isMethodSafe() => true,
            $role === 'admin' => true,
            $role === 'operator' => in_array($name, self::OPERATOR_WRITES, true),
            default => false,
        };
        if ($allowed) {
            return $next($request);
        }

        $message = in_array($name, self::OWNER_ONLY, true) ? 'Only the account owner can manage billing.' : 'Your role ('.$role.') cannot change this. Ask the account owner.';

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['message' => $message], 403)
            : ($request->isMethodSafe() ? redirect()->route('dashboard')->with('error', $message) : back()->with('error', $message));
    }
}
