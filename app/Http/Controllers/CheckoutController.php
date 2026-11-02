<?php

namespace App\Http\Controllers;

use App\Services\EasypaisaService;
use App\Services\JazzCashService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** "Pay online": records the plan payment, then sends the browser to the chosen gateway's checkout. */
class CheckoutController extends Controller
{
    /** @return array<string, bool> gateway => connected by the super admin */
    public static function gateways(): array
    {
        return [
            'jazzcash' => app(JazzCashService::class)->enabled(),
            'easypaisa' => app(EasypaisaService::class)->enabled(),
        ];
    }

    public function store(Request $request, SubscriptionService $subscriptions): Response
    {
        $on = array_keys(array_filter(self::gateways()));
        abort_if($on === [], 404);
        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys($subscriptions->plans()))],
            'months' => ['required', 'integer', Rule::in([1, 3, 6, 12])],
            'gateway' => ['nullable', Rule::in($on)],
        ]);
        $gateway = $data['gateway'] ?? $on[0];

        $payment = $request->user()->topUps()->create([
            'plan' => $data['plan'],
            'months' => $data['months'],
            'amount_pkr' => $subscriptions->priceFor($data['plan'], $data['months']),
            'method' => $gateway,
            'gateway' => $gateway,
            'reference' => JazzCashService::newReference(),
        ]);

        // A full page load (not an Inertia visit), because that page posts a form to the gateway.
        return Inertia::location(route($gateway.'.redirect', $payment));
    }
}
