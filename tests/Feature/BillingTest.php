<?php

namespace Tests\Feature;

use App\Models\TopUp;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_submits_manual_top_up_with_proof(): void
    {
        Storage::fake();
        $user = $this->customer();

        $this->actingAs($user)->post('/billing/top-ups', [
            'amount' => 25,
            'method' => 'jazzcash',
            'reference' => 'JC-123',
            'proof' => UploadedFile::fake()->image('receipt.png'),
        ])->assertSessionHasNoErrors();

        $topUp = $user->topUps()->firstOrFail();
        $this->assertSame('pending', $topUp->status);
        $this->assertSame('25.00', $topUp->amount_usd);
        $this->assertEquals(25 * config('hamsukhan.pkr_per_usd'), (float) $topUp->amount_pkr);
        Storage::assertExists($topUp->proof_path);
        $this->assertSame('0.0000', $user->fresh()->balance); // nothing credited until an admin approves
    }

    public function test_top_up_below_minimum_is_rejected(): void
    {
        $this->actingAs($this->customer())->post('/billing/top-ups', [
            'amount' => 1, 'method' => 'bank', 'reference' => 'X', 'proof' => UploadedFile::fake()->image('r.png'),
        ])->assertSessionHasErrors('amount');
    }

    public function test_admin_approval_credits_wallet_exactly_once(): void
    {
        $user = $this->customer(2);
        $topUp = $user->topUps()->create(['amount_usd' => 10, 'amount_pkr' => 2800, 'method' => 'easypaisa', 'reference' => 'EP-1']);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/top-ups/{$topUp->id}/approve")->assertSessionHas('success');
        $this->actingAs($admin)->post("/admin/top-ups/{$topUp->id}/approve")->assertSessionHas('error');

        $user->refresh();
        $this->assertSame('12.0000', $user->balance);
        $this->assertSame(1, $user->transactions()->where('type', 'topup')->count());
        $this->assertSame('approved', $topUp->fresh()->status);
        $this->assertSame($admin->id, $topUp->fresh()->reviewed_by);
    }

    public function test_reject_needs_a_reason_and_does_not_credit(): void
    {
        $user = $this->customer();
        $topUp = $user->topUps()->create(['amount_usd' => 10, 'amount_pkr' => 2800, 'method' => 'bank', 'reference' => 'FT-1']);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/top-ups/{$topUp->id}/reject")->assertSessionHasErrors('note');
        $this->actingAs($admin)->post("/admin/top-ups/{$topUp->id}/reject", ['note' => 'Not received']);

        $this->assertSame('rejected', $topUp->fresh()->status);
        $this->assertSame('0.0000', $user->fresh()->balance);
    }

    public function test_customers_cannot_approve(): void
    {
        $user = $this->customer();
        $topUp = $user->topUps()->create(['amount_usd' => 10, 'amount_pkr' => 2800, 'method' => 'bank', 'reference' => 'FT-1']);
        $this->actingAs($user)->post("/admin/top-ups/{$topUp->id}/approve")->assertForbidden();
        $this->assertSame('pending', $topUp->fresh()->status);
    }

    public function test_wallet_never_goes_negative_on_call_charge(): void
    {
        $user = $this->customer(0.05);
        $call = $user->bot->calls()->create(['title' => 'Long call', 'started_at' => now(), 'duration_seconds' => 600, 'cost' => 1.2]);

        $wallet = app(WalletService::class);
        $wallet->chargeCall($call);
        $wallet->chargeCall($call); // idempotent

        $this->assertSame('0.0000', $user->fresh()->balance);
        $this->assertSame(1, $user->transactions()->count());
        $this->assertEquals(-0.05, (float) $user->transactions()->first()->amount);
    }

    public function test_manual_debit_cannot_overdraw(): void
    {
        $user = $this->customer(1);
        $this->expectException(RuntimeException::class);
        app(WalletService::class)->adjust($user, -5, 'test');
    }
}
