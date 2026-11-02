<?php

namespace App\Http\Controllers;

use App\Models\TopUp;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The owner's monthly plan: what they have, how much they used, and paying for (or renewing) a plan. */
class BillingController extends Controller
{
    public function index(Request $request, SubscriptionService $subscriptions): Response
    {
        $user = $request->user();

        return Inertia::render('Billing', [
            'subscription' => $subscriptions->summary($user) + ['storesUsed' => $user->bots()->count(), 'usersUsed' => $user->members()->count() + 1],
            'plans' => collect($subscriptions->plans())->map(fn ($p, $k) => [
                'id' => $k,
                'name' => $p['name'],
                'pricePkr' => $p['price_pkr'],
                'stores' => $p['stores'],
                'users' => $p['users'],
            ])->values(),
            'payments' => $user->topUps()->latest()->limit(10)->get()->map(fn (TopUp $t) => [
                'id' => $t->id,
                'plan' => $t->planName(),
                'months' => $t->months,
                'amountPkr' => (float) $t->amount_pkr,
                'method' => $t->methodLabel(),
                'reference' => $t->reference,
                'status' => $t->status,
                'note' => $t->admin_note,
                'createdAt' => $t->created_at->toIso8601String(),
            ]),
            'accounts' => collect(config('hamsukhan.payment_accounts'))->map(fn ($a, $k) => $a + ['id' => $k])->values(),
        ]);
    }

    /** Manual payment: the owner already sent the plan price and submits the reference + screenshot for review. */
    public function storePayment(Request $request, SubscriptionService $subscriptions): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys($subscriptions->plans()))],
            'months' => ['required', 'integer', Rule::in([1, 3, 6, 12])],
            'method' => ['required', Rule::in(TopUp::METHODS)],
            'reference' => ['required', 'string', 'max:60'],
            'proof' => ['required', 'image', 'max:4096'],
        ]);

        if ($user->topUps()->where('status', 'pending')->count() >= 3) {
            return back()->withErrors(['reference' => 'You already have 3 payments waiting for review.']);
        }

        $user->topUps()->create([
            'plan' => $data['plan'],
            'months' => $data['months'],
            'amount_pkr' => $subscriptions->priceFor($data['plan'], $data['months']),
            'method' => $data['method'],
            'reference' => $data['reference'],
            'proof_path' => $request->file('proof')->store("top-ups/{$user->id}"),
        ]);

        return back()->with('success', 'Payment submitted, your plan switches on once we verify it (usually within a few hours).');
    }
}
