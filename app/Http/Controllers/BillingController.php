<?php

namespace App\Http\Controllers;

use App\Models\TopUp;
use App\Models\WalletTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Billing', [
            'transactions' => $user->transactions()->latest('created_at')->latest('id')->limit(50)->get()->map(fn (WalletTransaction $t) => [
                'id' => $t->id,
                'date' => $t->created_at->toIso8601String(),
                'text' => $t->description,
                'type' => $t->type,
                'amount' => (float) $t->amount,
            ]),
            'topUps' => $user->topUps()->latest()->limit(10)->get()->map(fn (TopUp $t) => [
                'id' => $t->id,
                'amountUsd' => (float) $t->amount_usd,
                'amountPkr' => (float) $t->amount_pkr,
                'method' => $t->methodLabel(),
                'reference' => $t->reference,
                'status' => $t->status,
                'note' => $t->admin_note,
                'createdAt' => $t->created_at->toIso8601String(),
            ]),
            'pricing' => [
                'ratePerMinute' => config('hamsukhan.rate_per_minute'),
                'minTopUp' => config('hamsukhan.min_topup_usd'),
                'pkrPerUsd' => config('hamsukhan.pkr_per_usd'),
                'lowBalance' => config('hamsukhan.low_balance_usd'),
                'maxVoices' => config('hamsukhan.max_cloned_voices'),
            ],
            'accounts' => collect(config('hamsukhan.payment_accounts'))->map(fn ($a, $k) => $a + ['id' => $k])->values(),
        ]);
    }

    /** Manual top-up: the customer already sent money and submits the reference + screenshot for review. */
    public function storeTopUp(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:'.config('hamsukhan.min_topup_usd'), 'max:1000'],
            'method' => ['required', Rule::in(TopUp::METHODS)],
            'reference' => ['required', 'string', 'max:60'],
            'proof' => ['required', 'image', 'max:4096'],
        ]);

        if ($user->topUps()->where('status', 'pending')->count() >= 3) {
            return back()->withErrors(['amount' => 'You already have 3 top-ups waiting for review.']);
        }

        $user->topUps()->create([
            'amount_usd' => round($data['amount'], 2),
            'amount_pkr' => round($data['amount'] * config('hamsukhan.pkr_per_usd')),
            'method' => $data['method'],
            'reference' => $data['reference'],
            'proof_path' => $request->file('proof')->store("top-ups/{$user->id}"),
        ]);

        return back()->with('success', 'Payment submitted, your balance updates once we verify it (usually within a few hours).');
    }
}
