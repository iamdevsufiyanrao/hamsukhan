<?php

namespace App\Services;

use App\Models\TopUp;
use App\Support\ProviderError;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Easypaisa "Easypay" hosted checkout, three browser hops:
 *  1. we post the signed order to Index.jsf (merchantHashedReq = base64 AES-128-ECB of the sorted fields, key = hash key);
 *  2. Easypay sends the browser back to our first URL with an auth_token, which we post to Confirm.jsf;
 *  3. Easypay sends the browser to our final URL with the status.
 * The browser's status is never trusted: the payment is confirmed server-to-server with inquire-transaction.
 */
class EasypaisaService
{
    public function enabled(): bool
    {
        $c = config('hamsukhan.easypaisa');

        return (bool) $c['enabled'] && filled($c['store_id']) && filled($c['hash_key']) && filled($c['username']) && filled($c['password']) && filled($c['account_num']);
    }

    private function base(): string
    {
        return config('hamsukhan.easypaisa.sandbox') ? 'https://easypaystg.easypaisa.com.pk' : 'https://easypay.easypaisa.com.pk';
    }

    public function indexUrl(): string
    {
        return $this->base().'/easypay/Index.jsf';
    }

    public function confirmUrl(): string
    {
        return $this->base().'/easypay/Confirm.jsf';
    }

    /** Step 1: the signed order form for Index.jsf. */
    public function checkoutFields(TopUp $payment): array
    {
        $fields = [
            'amount' => number_format((float) $payment->amount_pkr, 1, '.', ''),
            'autoRedirect' => '1',
            'emailAddr' => (string) $payment->user->email,
            'expiryDate' => now('Asia/Karachi')->addDay()->format('Ymd His'),
            'orderRefNum' => $payment->reference,
            'postBackURL' => route('easypaisa.token'),
            'storeId' => (string) config('hamsukhan.easypaisa.store_id'),
        ];
        $fields['merchantHashedReq'] = $this->hashRequest($fields);

        return $fields;
    }

    public function hashRequest(array $fields): string
    {
        ksort($fields, SORT_STRING);
        $plain = collect($fields)->filter(fn ($v) => $v !== '' && $v !== null)->map(fn ($v, $k) => "{$k}={$v}")->implode('&');

        return base64_encode((string) openssl_encrypt($plain, 'aes-128-ecb', (string) config('hamsukhan.easypaisa.hash_key'), OPENSSL_RAW_DATA));
    }

    /**
     * Asks Easypay whether the order is paid.
     *
     * @return array{paid: bool, amount: ?float, status: ?string, raw: array}
     *
     * @throws RuntimeException when Easypay can't be reached
     */
    public function inquire(string $orderRef): array
    {
        $c = config('hamsukhan.easypaisa');
        try {
            $r = Http::withHeaders(['Credentials' => base64_encode($c['username'].':'.$c['password'])])
                ->acceptJson()->timeout(20)
                ->post($this->base().'/easypay-service/rest/v4/inquire-transaction', [
                    'orderId' => $orderRef,
                    'storeId' => $c['store_id'],
                    'accountNum' => $c['account_num'],
                ])->throw()->json() ?? [];
        } catch (\Throwable $e) {
            throw new RuntimeException(ProviderError::message($e, 'Easypaisa'), 0, $e);
        }
        $status = strtoupper((string) ($r['transactionStatus'] ?? ''));

        return [
            'paid' => ($r['responseCode'] ?? null) === '0000' && in_array($status, ['PAID', 'SUCCESS'], true),
            'amount' => isset($r['transactionAmount']) ? (float) $r['transactionAmount'] : null,
            'status' => $status ?: ($r['responseDesc'] ?? null),
            'raw' => $r,
        ];
    }
}
