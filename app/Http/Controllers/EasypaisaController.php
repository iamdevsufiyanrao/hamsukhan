<?php

namespace App\Http\Controllers;

use App\Models\TopUp;
use App\Services\EasypaisaService;
use App\Services\JazzCashService;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;

/** Easypaisa checkout hops (see EasypaisaService). Hops 2 and 3 come back from Easypay without our session. */
class EasypaisaController extends Controller
{
    /** Hop 1: auto-posts the signed order to Easypay. */
    public function redirect(Request $request, TopUp $payment, EasypaisaService $easypaisa): View
    {
        abort_unless($easypaisa->enabled() && $payment->user_id === $request->user()->id && $payment->gateway === 'easypaisa' && $payment->status === 'pending', 404);

        return view('gateway-redirect', ['gateway' => 'Easypaisa', 'endpoint' => $easypaisa->indexUrl(), 'fields' => $easypaisa->checkoutFields($payment)]);
    }

    /** Hop 2: Easypay hands back an auth token, which goes to Confirm.jsf. */
    public function token(Request $request, EasypaisaService $easypaisa): View|RedirectResponse
    {
        $token = (string) $request->input('auth_token', '');
        if (! $easypaisa->enabled() || $token === '') {
            return redirect()->route('billing');
        }

        return view('gateway-redirect', ['gateway' => 'Easypaisa', 'endpoint' => $easypaisa->confirmUrl(), 'fields' => ['auth_token' => $token, 'postBackURL' => route('easypaisa.final')]]);
    }

    /** Hop 3: the browser says how it went; we ask Easypay itself before switching the plan on. */
    public function final(Request $request, EasypaisaService $easypaisa, SubscriptionService $subscriptions): RedirectResponse
    {
        $ref = (string) ($request->input('orderRefNumber') ?? $request->input('orderRefNum', ''));
        $payment = $ref !== '' ? TopUp::where('gateway', 'easypaisa')->where('reference', $ref)->first() : null;
        if (! $payment || ! $easypaisa->enabled()) {
            return redirect()->route('billing');
        }
        if ($payment->status !== 'pending') {
            return redirect()->route('billing', ['paid' => $payment->reference]);
        }

        try {
            $result = $easypaisa->inquire($payment->reference);
        } catch (RuntimeException $e) {
            Log::warning('[easypaisa] inquire failed', ['ref' => $ref, 'error' => $e->getMessage()]);

            return redirect()->route('billing', ['paid' => $payment->reference]); // stays pending; shown as "waiting to confirm"
        }

        $amountOk = $result['amount'] !== null && JazzCashService::paisa($result['amount']) === JazzCashService::paisa((float) $payment->amount_pkr);
        $paid = $result['paid'] && $amountOk;
        if ($paid || $request->input('status') !== '0000') {
            $note = $paid ? 'Paid online with Easypaisa' : 'Easypaisa: '.($request->input('desc') ?: $result['status'] ?: 'payment not completed').($result['paid'] && ! $amountOk ? ' (amount mismatch)' : '');
            $subscriptions->completeOnlinePayment($payment, $paid, $note, $result['raw'] + ['browserStatus' => $request->input('status')]);
        }

        return redirect()->route('billing', ['paid' => $payment->reference]);
    }
}
