<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** Props every page receives: the signed-in user, wallet summary, badge counts and flash messages. */
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
                ],
                'wallet' => [
                    'balance' => (float) $user->balance,
                    'minutesLeft' => $user->minutesLeft(),
                    'low' => (float) $user->balance < config('hamsukhan.low_balance_usd'),
                ],
                'bot' => $user->bot ? ['publicKey' => $user->bot->public_key] : null,
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
                'termsUrl' => config('hamsukhan.site.terms_url'),
                'privacyUrl' => config('hamsukhan.site.privacy_url'),
                'supportEmail' => config('hamsukhan.site.support_email'),
                'supportPhone' => config('hamsukhan.site.support_phone'),
            ],
            'config' => [
                'ratePerMinute' => config('hamsukhan.rate_per_minute'),
                'minTopUp' => config('hamsukhan.min_topup_usd'),
                // Payment details are only for signed-in users; public pages never carry them.
                ...($user ? [
                    'pkrPerUsd' => config('hamsukhan.pkr_per_usd'),
                    'accounts' => collect(config('hamsukhan.payment_accounts'))->map(fn ($a, $k) => $a + ['id' => $k])->values(),
                ] : []),
            ],
        ];
    }
}
