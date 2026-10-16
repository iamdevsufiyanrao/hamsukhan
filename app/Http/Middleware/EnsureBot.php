<?php

namespace App\Http\Middleware;

use App\Services\BotProvisioner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer pages need a bot. Admin-only accounts (no bot) go to the payments queue;
 * any other account without a bot gets the default one created.
 */
class EnsureBot
{
    public function __construct(private BotProvisioner $provisioner) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->bot) {
            if ($user->is_admin) {
                return redirect()->route('admin.overview');
            }
            $user->setRelation('bot', $this->provisioner->provision($user));
        }

        return $next($request);
    }
}
