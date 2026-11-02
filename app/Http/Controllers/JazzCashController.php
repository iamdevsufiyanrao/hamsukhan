<?php

namespace App\Http\Controllers;

use App\Models\TopUp;
use App\Services\JazzCashService;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Online plan payment with JazzCash:
 * CheckoutController records the payment → redirect page posts the signed form to JazzCash → the customer pays there
 * → JazzCash posts the signed result to `return` (no session: it's a cross-site POST) → plan switches on → /billing.
 */
class JazzCashController extends Controller
{
    /** Auto-submits the signed form to JazzCash's hosted checkout. */
    public function redirect(Request $request, TopUp $payment, JazzCashService $jazzcash): View
    {
        abort_unless($jazzcash->enabled() && $payment->user_id === $request->user()->id && $payment->gateway === 'jazzcash' && $payment->status === 'pending', 404);

        return view('gateway-redirect', ['gateway' => 'JazzCash', 'endpoint' => $jazzcash->endpoint(), 'fields' => $jazzcash->checkoutFields($payment)]);
    }

    /** JazzCash's answer. Trusted only with a valid signature and a matching reference and amount. */
    public function return(Request $request, JazzCashService $jazzcash, SubscriptionService $subscriptions): RedirectResponse
    {
        $response = $request->only(array_filter(array_keys($request->all()), fn ($k) => str_starts_with((string) $k, 'pp')));
        $payment = TopUp::where('gateway', 'jazzcash')->where('reference', (string) ($response['pp_TxnRefNo'] ?? ''))->first();

        if (! $payment || ! $jazzcash->verify($response)) {
            Log::warning('[jazzcash] unverified return', ['ref' => $response['pp_TxnRefNo'] ?? null, 'found' => (bool) $payment]);

            return redirect()->route('billing');
        }

        $code = (string) ($response['pp_ResponseCode'] ?? '');
        $amountOk = (int) ($response['pp_Amount'] ?? -1) === JazzCashService::paisa((float) $payment->amount_pkr);
        $paid = $code === '000' && $amountOk;
        $note = $paid ? 'Paid online with JazzCash' : 'JazzCash: '.(($response['pp_ResponseMessage'] ?? '') ?: 'payment not completed').($amountOk ? '' : ' (amount mismatch)')." [{$code}]";
        unset($response['pp_Password']);
        $subscriptions->completeOnlinePayment($payment, $paid, $note, $response);

        return redirect()->route('billing', ['paid' => $payment->reference]);
    }
}
