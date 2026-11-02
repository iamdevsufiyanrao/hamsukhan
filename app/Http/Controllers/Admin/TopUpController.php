<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\TopUp;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Super admin: manual plan payments (JazzCash / Easypaisa / bank) waiting for a check against the real account. */
class TopUpController extends Controller
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ['pending', 'approved', 'rejected'], true) ? $request->query('status') : 'pending';

        return Inertia::render('Admin/TopUps', [
            'filter' => $status,
            'topUps' => TopUp::with('user:id,name,email', 'reviewer:id,name')->where('status', $status)->latest()->limit(100)->get()
                ->map(fn (TopUp $t) => [
                    'id' => $t->id,
                    'user' => ['name' => $t->user->name, 'email' => $t->user->email],
                    'plan' => $t->planName(),
                    'months' => $t->months,
                    'amountPkr' => (float) $t->amount_pkr,
                    'method' => $t->methodLabel(),
                    'reference' => $t->reference,
                    'hasProof' => (bool) $t->proof_path,
                    'status' => $t->status,
                    'note' => $t->admin_note,
                    'reviewer' => $t->reviewer?->name,
                    'createdAt' => $t->created_at->toIso8601String(),
                ]),
            'counts' => TopUp::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function proof(TopUp $topUp): StreamedResponse
    {
        abort_unless($topUp->proof_path && Storage::exists($topUp->proof_path), 404);

        return Storage::response($topUp->proof_path);
    }

    public function approve(Request $request, TopUp $topUp, SubscriptionService $subscriptions): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:200']])['note'] ?? null;
        try {
            $account = $subscriptions->approvePayment($topUp, $request->user(), $note);
            AdminAction::record($request->user(), $topUp->user, 'approve_payment', ['topUpId' => $topUp->id, 'plan' => $topUp->plan, 'months' => $topUp->months, 'amountPkr' => (float) $topUp->amount_pkr, 'reference' => $topUp->reference]);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Approved, {$account->name}'s {$topUp->planName()} plan is active until {$account->plan_expires_at->format('j M Y')}");
    }

    public function reject(Request $request, TopUp $topUp, SubscriptionService $subscriptions): RedirectResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'max:200']])['note'];
        try {
            $subscriptions->rejectPayment($topUp, $request->user(), $note);
            AdminAction::record($request->user(), $topUp->user, 'reject_payment', ['topUpId' => $topUp->id, 'note' => $note]);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment rejected');
    }
}
