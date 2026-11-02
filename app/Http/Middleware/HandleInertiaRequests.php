<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** Props every page receives: the signed-in user, plan, stores, badge counts and flash messages. */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => $user ? [
                'user' => [
                    'name' => $user->name,
                    'firstName' => explode(' ', trim($user->name))[0],
                    'email' => $user->email,
                    'initials' => $user->initials(),
                    'avatarUrl' => $user->avatarUrl(),
                    'isAdmin' => $user->is_admin,
                    'verified' => $user->hasVerifiedEmail(),
                    'teamRole' => $user->team_role,
                ],
                'plan' => $this->plan($user->account()),
                'bot' => $user->bot ? ['id' => $user->bot->id, 'publicKey' => $user->bot->public_key, 'name' => $user->bot->business_name ?: 'Store #'.$user->bot->id] : null,
                // For the store switcher in the sidebar.
                'stores' => $user->is_admin ? [] : $user->bots()->get(['id', 'business_name'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->business_name ?: 'Store #'.$b->id]),
                'impersonator' => ($id = $request->session()->get('impersonator_id')) ? \App\Models\User::find($id, ['id', 'name'])?->only('name') : null,
                'badges' => [
                    'newBookings' => $user->bot?->bookings()->where('status', 'new')->count() ?? 0,
                    'pendingTopUps' => $user->is_admin ? \App\Models\TopUp::where('status', 'pending')->count() : 0,
                    'newMessages' => $user->is_admin ? \App\Models\ContactMessage::where('status', 'new')->count() : 0,
                ],
            ] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'site' => [
                'name' => config('hamsukhan.site.name'),
                'announcement' => config('hamsukhan.site.announcement'),
                'allowSignups' => (bool) config('hamsukhan.site.allow_signups'),
                'termsUrl' => '/terms', // /terms redirects to the admin's external URL when one is set
                'privacyUrl' => '/privacy',
                'supportEmail' => config('hamsukhan.site.support_email'),
                'supportPhone' => config('hamsukhan.site.support_phone'),
                'whatsapp' => config('hamsukhan.site.whatsapp'),
                // Public bot key of the landing-page demo bot (public anyway, it is in every embed code).
                'demoBotKey' => filled(config('hamsukhan.vapi.private_key')) ? config('hamsukhan.demo.bot_key') : null,
            ],
            'config' => [
                // Public prices for the landing page (the fair-use limits stay private).
                'plans' => collect(config('hamsukhan.plans'))->map(fn ($p, $k) => ['id' => $k, 'name' => $p['name'], 'pricePkr' => $p['price_pkr'], 'stores' => $p['stores'], 'users' => $p['users']])->values(),
                'trialDays' => (int) config('hamsukhan.trial_days'),
            ],
        ];
    }

    private function plan(User $account): array
    {
        $plan = $account->activePlan();

        return [
            'active' => $plan !== null,
            'name' => $plan ? $account->planLimits()['name'] : null,
            'expiresAt' => $account->plan_expires_at?->toIso8601String(),
            'daysLeft' => $plan ? (int) ceil(now()->diffInDays($account->plan_expires_at, true)) : 0,
        ];
    }
}
