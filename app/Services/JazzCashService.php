<?php

namespace App\Services;

use App\Models\TopUp;

/**
 * JazzCash online checkout ("Page Redirection", API v1.1): we post a signed form to JazzCash's hosted page,
 * the customer pays there (wallet or card), and JazzCash posts the signed result back to our return URL.
 * Signature (pp_SecureHash): HMAC-SHA256 with the integrity salt over "salt&" + every non-empty pp*
 * field sorted by name and joined with "&", upper-case hex.
 */
class JazzCashService
{
    public function enabled(): bool
    {
        return (bool) config('hamsukhan.jazzcash.enabled')
            && filled(config('hamsukhan.jazzcash.merchant_id'))
            && filled(config('hamsukhan.jazzcash.password'))
            && filled(config('hamsukhan.jazzcash.integrity_salt'));
    }

    public function endpoint(): string
    {
        return config('hamsukhan.jazzcash.sandbox')
            ? 'https://sandbox.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform/'
            : 'https://payments.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform/';
    }

    /** A transaction reference JazzCash accepts (max 20 chars, unique per merchant). */
    public static function newReference(): string
    {
        return 'T'.now('Asia/Karachi')->format('YmdHis').random_int(1000, 9999);
    }

    /** The signed form fields for this payment. */
    public function checkoutFields(TopUp $payment): array
    {
        $now = now('Asia/Karachi');
        $fields = [
            'pp_Version' => '1.1',
            'pp_TxnType' => '', // empty: the customer picks wallet or card on JazzCash's page
            'pp_Language' => 'EN',
            'pp_MerchantID' => (string) config('hamsukhan.jazzcash.merchant_id'),
            'pp_SubMerchantID' => '',
            'pp_Password' => (string) config('hamsukhan.jazzcash.password'),
            'pp_BankID' => '',
            'pp_ProductID' => '',
            'pp_TxnRefNo' => $payment->reference,
            'pp_Amount' => (string) self::paisa((float) $payment->amount_pkr),
            'pp_TxnCurrency' => 'PKR',
            'pp_TxnDateTime' => $now->format('YmdHis'),
            'pp_BillReference' => 'plan'.$payment->id,
            'pp_Description' => mb_substr("Hamsukhan {$payment->planName()} plan, {$payment->months} month".($payment->months > 1 ? 's' : ''), 0, 100),
            'pp_TxnExpiryDateTime' => $now->copy()->addDay()->format('YmdHis'),
            'pp_ReturnURL' => route('jazzcash.return'),
            'ppmpf_1' => '', 'ppmpf_2' => '', 'ppmpf_3' => '', 'ppmpf_4' => '', 'ppmpf_5' => '',
        ];
        $fields['pp_SecureHash'] = $this->hash($fields);

        return $fields;
    }

    /** True when JazzCash's answer carries a valid signature. */
    public function verify(array $response): bool
    {
        $given = strtoupper((string) ($response['pp_SecureHash'] ?? ''));

        return $given !== '' && hash_equals($this->hash($response), $given);
    }

    public function hash(array $fields): string
    {
        $values = collect($fields)
            ->filter(fn ($v, $k) => str_starts_with((string) $k, 'pp') && $k !== 'pp_SecureHash' && $v !== null && $v !== '')
            ->sortKeys(SORT_STRING)
            ->implode('&');
        $salt = (string) config('hamsukhan.jazzcash.integrity_salt');

        return strtoupper(hash_hmac('sha256', $salt.'&'.$values, $salt));
    }

    public static function paisa(float $pkr): int
    {
        return (int) round($pkr * 100);
    }
}
