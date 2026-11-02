<?php

namespace Tests\Feature;

use App\Models\TopUp;
use App\Services\JazzCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Calls-at-the-same-time limits, and online plan payments with JazzCash. */
class ConcurrencyAndJazzCashTest extends TestCase
{
    use RefreshDatabase;

    private function live($bot, int $n): void
    {
        foreach (range(1, $n) as $i) {
            $bot->liveCalls()->create(['vapi_call_id' => "live_{$bot->id}_{$i}", 'started_at' => now()]);
        }
    }

    public function test_limit_is_off_by_default(): void
    {
        $bot = $this->customer()->bot;
        $this->live($bot, 5);
        $this->postJson("/api/widget/{$bot->public_key}/start")->assertOk();
    }

    public function test_plan_limit_refuses_with_busy_and_counts_calls_just_starting(): void
    {
        config(['hamsukhan.concurrency.enabled' => true, 'hamsukhan.plans.basic.concurrent_calls' => 2]);
        $bot = $this->customer()->bot;

        // Two calls start back to back; Vapi hasn't reported either as in progress yet.
        $this->postJson("/api/widget/{$bot->public_key}/start")->assertOk();
        $this->postJson("/api/widget/{$bot->public_key}/start")->assertOk();
        $this->postJson("/api/widget/{$bot->public_key}/start")->assertStatus(429)->assertJson(['reason' => 'busy']);

        // Once the starting holds expire, only calls really in progress count; stale ones (missed "ended") don't.
        Cache::flush();
        $this->live($bot, 1);
        $bot->liveCalls()->create(['vapi_call_id' => 'stale', 'started_at' => now()->subHour()]);
        $this->postJson("/api/widget/{$bot->public_key}/start")->assertOk();
    }

    public function test_business_plan_counts_all_its_stores_together(): void
    {
        config(['hamsukhan.concurrency.enabled' => true, 'hamsukhan.plans.business.concurrent_calls' => 3]);
        $owner = $this->customer('business');
        $this->actingAs($owner)->post('/stores', ['name' => 'Branch 2']);
        [$a, $b] = $owner->bots()->get()->all();
        $this->live($a, 2);
        $this->live($b, 1);

        $this->postJson("/api/widget/{$b->public_key}/start")->assertStatus(429);
        // Another account is not affected.
        $this->postJson('/api/widget/'.$this->customer('business')->bot->public_key.'/start')->assertOk();
    }

    public function test_platform_cap(): void
    {
        config(['hamsukhan.concurrency.enabled' => true, 'hamsukhan.concurrency.platform_max' => 3]);
        $this->live($this->customer()->bot, 1);
        $this->live($this->customer()->bot, 2);
        $this->postJson('/api/widget/'.$this->customer()->bot->public_key.'/start')->assertStatus(429)->assertJson(['reason' => 'busy']);
    }

    public function test_admin_turns_the_limit_on_from_settings(): void
    {
        $this->actingAs($this->admin())->patch('/admin/settings/billing', ['concurrency_enabled' => true, 'basic_concurrent_calls' => 1])->assertSessionHas('success');
        $bot = $this->customer()->bot;
        $this->live($bot, 1);
        $this->postJson("/api/widget/{$bot->public_key}/start")->assertStatus(429);
    }

    /* ---------- JazzCash ---------- */

    private function jazzcashOn(): void
    {
        config(['hamsukhan.jazzcash' => ['enabled' => true, 'sandbox' => true, 'merchant_id' => 'MC123', 'password' => 'pw', 'integrity_salt' => 'salt123']]);
    }

    /** What JazzCash posts back, signed like JazzCash signs it. */
    private function answer(TopUp $payment, string $code = '000', ?int $paisa = null): array
    {
        $fields = ['pp_Version' => '1.1', 'pp_TxnRefNo' => $payment->reference, 'pp_Amount' => (string) ($paisa ?? (int) $payment->amount_pkr * 100), 'pp_ResponseCode' => $code, 'pp_ResponseMessage' => $code === '000' ? 'Thank you' : 'Insufficient balance', 'pp_MerchantID' => 'MC123', 'pp_TxnCurrency' => 'PKR', 'ppmpf_1' => ''];
        $fields['pp_SecureHash'] = app(JazzCashService::class)->hash($fields);

        return $fields;
    }

    public function test_online_checkout_is_hidden_until_the_admin_connects_it(): void
    {
        $user = $this->customer(null);
        $this->actingAs($user)->post('/billing/checkout', ['plan' => 'basic', 'months' => 1])->assertNotFound();
        $this->actingAs($user)->get('/billing')->assertInertia(fn ($p) => $p->where('gateways', [])->where('manualPayments', true));

        $this->jazzcashOn();
        $this->actingAs($user)->get('/billing')->assertInertia(fn ($p) => $p->where('gateways', ['jazzcash']));
    }

    public function test_checkout_creates_the_payment_and_posts_a_signed_form_to_jazzcash(): void
    {
        $this->jazzcashOn();
        $user = $this->customer(null);

        $this->actingAs($user)->post('/billing/checkout', ['plan' => 'business', 'months' => 3], ['X-Inertia' => 'true'])->assertStatus(409);
        $payment = $user->topUps()->firstOrFail();
        $this->assertSame(['jazzcash', 'pending', 75000.0], [$payment->gateway, $payment->status, (float) $payment->amount_pkr]);

        $html = $this->actingAs($user)->get("/billing/checkout/{$payment->id}")->assertOk()->getContent();
        $this->assertStringContainsString('sandbox.jazzcash.com.pk', $html);
        $this->assertStringContainsString('name="pp_Amount" value="7500000"', $html);
        $this->assertStringContainsString($payment->reference, $html);
        preg_match_all('/name="(\w+)" value="([^"]*)"/', $html, $m);
        $fields = array_combine($m[1], array_map('html_entity_decode', $m[2]));
        $this->assertSame(app(JazzCashService::class)->hash($fields), $fields['pp_SecureHash']);

        // Not shown to anyone else, and not in the admin's manual review queue.
        $this->actingAs($this->customer())->get("/billing/checkout/{$payment->id}")->assertNotFound();
        $this->assertSame(0, TopUp::awaitingReview()->count());
    }

    public function test_paid_answer_switches_the_plan_on_once(): void
    {
        $this->jazzcashOn();
        $user = $this->customer(null);
        $payment = $user->topUps()->create(['plan' => 'basic', 'months' => 1, 'amount_pkr' => 3000, 'method' => 'jazzcash', 'gateway' => 'jazzcash', 'reference' => 'T2026110312000001']);

        $this->post('/api/payments/jazzcash/return', $this->answer($payment))->assertRedirect('/billing?paid=T2026110312000001');
        $this->post('/api/payments/jazzcash/return', $this->answer($payment)); // repeated answer
        $this->assertSame('basic', $user->fresh()->activePlan());
        $this->assertTrue($user->fresh()->plan_expires_at->between(now()->addMonth()->subMinute(), now()->addMonth()->addMinute()));
        $this->assertSame('approved', $payment->fresh()->status);
        $this->assertArrayNotHasKey('pp_Password', $payment->fresh()->gateway_response);

        $this->actingAs($user)->get('/billing?paid=T2026110312000001')->assertInertia(fn ($p) => $p->where('checkoutResult.status', 'approved'));
    }

    public function test_forged_failed_or_wrong_amount_answers_never_switch_the_plan_on(): void
    {
        $this->jazzcashOn();
        $user = $this->customer(null);
        $mk = fn ($ref) => $user->topUps()->create(['plan' => 'basic', 'months' => 1, 'amount_pkr' => 3000, 'method' => 'jazzcash', 'gateway' => 'jazzcash', 'reference' => $ref]);

        $forged = $this->answer($a = $mk('TA'));
        $forged['pp_SecureHash'] = str_repeat('A', 64);
        $this->post('/api/payments/jazzcash/return', $forged);
        $this->assertSame('pending', $a->fresh()->status);

        $this->post('/api/payments/jazzcash/return', $this->answer($b = $mk('TB'), '124'));
        $this->assertSame('rejected', $b->fresh()->status);
        $this->assertStringContainsString('Insufficient balance', $b->fresh()->admin_note);

        $this->post('/api/payments/jazzcash/return', $this->answer($c = $mk('TC'), '000', 100)); // Rs 1 instead of Rs 3,000
        $this->assertSame('rejected', $c->fresh()->status);

        $this->assertNull($user->fresh()->activePlan());
    }

    public function test_admin_saves_jazzcash_credentials_encrypted(): void
    {
        $this->actingAs($this->admin())->patch('/admin/settings/billing', ['jazzcash_online_enabled' => true, 'jazzcash_merchant_id' => 'MC9', 'jazzcash_password' => 'p@ss', 'jazzcash_integrity_salt' => 'sss'])->assertSessionHas('success');
        $this->assertTrue(app(JazzCashService::class)->enabled());
        $this->assertNotSame('sss', \App\Models\Setting::where('key', 'jazzcash_integrity_salt')->value('value'));
    }

    /* ---------- Easypaisa + manual switch ---------- */

    private function easypaisaOn(): void
    {
        config(['hamsukhan.easypaisa' => ['enabled' => true, 'sandbox' => true, 'store_id' => '1234', 'hash_key' => 'ABCDEFGHIJKLMNOP', 'username' => 'api', 'password' => 'pw', 'account_num' => '654321']]);
    }

    public function test_easypaisa_checkout_signs_the_order_and_walks_the_three_hops(): void
    {
        $this->easypaisaOn();
        $user = $this->customer(null);
        $this->actingAs($user)->post('/billing/checkout', ['plan' => 'basic', 'months' => 1, 'gateway' => 'easypaisa'], ['X-Inertia' => 'true'])->assertStatus(409);
        $payment = $user->topUps()->firstOrFail();
        $this->assertSame('easypaisa', $payment->gateway);

        $html = $this->actingAs($user)->get("/billing/checkout/{$payment->id}/easypaisa")->assertOk()->getContent();
        $this->assertStringContainsString('easypaystg.easypaisa.com.pk/easypay/Index.jsf', $html);
        preg_match_all('/name="(\w+)" value="([^"]*)"/', $html, $m);
        $f = array_combine($m[1], array_map('html_entity_decode', $m[2]));
        $this->assertSame('3000.0', $f['amount']);
        $plain = openssl_decrypt(base64_decode($f['merchantHashedReq']), 'aes-128-ecb', 'ABCDEFGHIJKLMNOP', OPENSSL_RAW_DATA);
        $this->assertStringStartsWith('amount=3000.0&autoRedirect=1&emailAddr=', $plain);
        $this->assertStringContainsString('&orderRefNum='.$payment->reference.'&', $plain);

        // Hop 2: token goes to Confirm.jsf.
        $this->get('/payments/easypaisa/token?auth_token=tok123')->assertOk()->assertSee('Confirm.jsf', false)->assertSee('tok123', false);

        // Hop 3: the browser claims success, but only Easypay's own answer counts.
        \Illuminate\Support\Facades\Http::fake(['easypaystg.easypaisa.com.pk/easypay-service/*' => \Illuminate\Support\Facades\Http::sequence()
            ->push(['responseCode' => '0000', 'transactionStatus' => 'PENDING', 'transactionAmount' => 3000])
            ->push(['responseCode' => '0000', 'transactionStatus' => 'PAID', 'transactionAmount' => 3000])]);
        $this->get("/payments/easypaisa/final?status=0000&orderRefNumber={$payment->reference}")->assertRedirect('/billing?paid='.$payment->reference);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->get("/payments/easypaisa/final?status=0000&orderRefNumber={$payment->reference}");
        $this->assertSame('approved', $payment->fresh()->status);
        $this->assertSame('basic', $user->fresh()->activePlan());
    }

    public function test_easypaisa_wrong_amount_or_failure_never_activates(): void
    {
        $this->easypaisaOn();
        $user = $this->customer(null);
        $payment = $user->topUps()->create(['plan' => 'business', 'months' => 1, 'amount_pkr' => 25000, 'method' => 'easypaisa', 'gateway' => 'easypaisa', 'reference' => 'TE1']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['responseCode' => '0000', 'transactionStatus' => 'PAID', 'transactionAmount' => 10])]);
        $this->get('/payments/easypaisa/final?status=0000&orderRefNumber=TE1');
        $this->assertSame('pending', $payment->fresh()->status); // browser said OK, amount wrong: left for a human
        $this->get('/payments/easypaisa/final?status=0001&desc=Cancelled&orderRefNumber=TE1');
        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertNull($user->fresh()->activePlan());
    }

    public function test_admin_can_switch_off_manual_payments_only_with_a_gateway_connected(): void
    {
        config(['hamsukhan.manual_payments' => false]);
        $user = $this->customer(null);
        // No gateway: manual stays available so nobody is locked out.
        $this->actingAs($user)->get('/billing')->assertInertia(fn ($p) => $p->where('manualPayments', true));

        $this->easypaisaOn();
        $this->actingAs($user)->get('/billing')->assertInertia(fn ($p) => $p->where('manualPayments', false)->where('gateways', ['easypaisa']));
        $this->actingAs($user)->post('/billing/payments', ['plan' => 'basic', 'months' => 1, 'method' => 'bank', 'reference' => 'X', 'proof' => \Illuminate\Http\UploadedFile::fake()->image('r.png')])->assertForbidden();
    }
}
