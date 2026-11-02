<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Monthly plans: paying, approval, renewal, and the store / user limits of each plan. */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_submits_a_plan_payment_with_proof(): void
    {
        Storage::fake();
        $user = $this->customer(null);

        $this->actingAs($user)->post('/billing/payments', [
            'plan' => 'business',
            'months' => 3,
            'method' => 'jazzcash',
            'reference' => 'JC-123',
            'proof' => UploadedFile::fake()->image('receipt.png'),
        ])->assertSessionHasNoErrors();

        $payment = $user->topUps()->firstOrFail();
        $this->assertSame(['pending', 'business', 3], [$payment->status, $payment->plan, $payment->months]);
        $this->assertEquals(75000, (float) $payment->amount_pkr); // price comes from the server, never the form
        Storage::assertExists($payment->proof_path);
        $this->assertNull($user->fresh()->activePlan()); // nothing switches on until an admin approves
    }

    public function test_payment_needs_a_known_plan_and_months(): void
    {
        $this->actingAs($this->customer(null))->post('/billing/payments', [
            'plan' => 'gold', 'months' => 2, 'method' => 'bank', 'reference' => 'X', 'proof' => UploadedFile::fake()->image('r.png'),
        ])->assertSessionHasErrors(['plan', 'months']);
    }

    public function test_approval_activates_the_plan_exactly_once(): void
    {
        $user = $this->customer(null);
        $payment = $user->topUps()->create(['plan' => 'basic', 'months' => 1, 'amount_pkr' => 3000, 'method' => 'easypaisa', 'reference' => 'EP-1']);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/top-ups/{$payment->id}/approve")->assertSessionHas('success');
        $this->actingAs($admin)->post("/admin/top-ups/{$payment->id}/approve")->assertSessionHas('error');

        $user->refresh();
        $this->assertSame('basic', $user->activePlan());
        $this->assertTrue($user->plan_expires_at->between(now()->addMonth()->subMinute(), now()->addMonth()->addMinute()));
        $this->assertSame(['approved', $admin->id], [$payment->fresh()->status, $payment->fresh()->reviewed_by]);
    }

    public function test_renewing_the_same_plan_extends_it_and_a_new_plan_starts_today(): void
    {
        $user = $this->customer();
        $end = $user->plan_expires_at;
        $subscriptions = app(SubscriptionService::class);

        $subscriptions->activate($user, 'basic', 2);
        $this->assertTrue($user->fresh()->plan_expires_at->equalTo($end->copy()->addMonthsNoOverflow(2)));

        $subscriptions->activate($user, 'business', 1);
        $this->assertSame('business', $user->fresh()->activePlan());
        $this->assertTrue($user->fresh()->plan_expires_at->between(now()->addMonth()->subMinute(), now()->addMonth()->addMinute()));
    }

    public function test_reject_needs_a_reason_and_does_not_activate(): void
    {
        $user = $this->customer(null);
        $payment = $user->topUps()->create(['plan' => 'basic', 'months' => 1, 'amount_pkr' => 3000, 'method' => 'bank', 'reference' => 'FT-1']);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/top-ups/{$payment->id}/reject")->assertSessionHasErrors('note');
        $this->actingAs($admin)->post("/admin/top-ups/{$payment->id}/reject", ['note' => 'Not received']);

        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertNull($user->fresh()->activePlan());
    }

    public function test_customers_cannot_approve(): void
    {
        $user = $this->customer();
        $payment = $user->topUps()->create(['plan' => 'basic', 'months' => 1, 'amount_pkr' => 3000, 'method' => 'bank', 'reference' => 'FT-1']);
        $this->actingAs($user)->post("/admin/top-ups/{$payment->id}/approve")->assertForbidden();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_basic_has_one_store_and_business_up_to_five(): void
    {
        $basic = $this->customer();
        $this->actingAs($basic)->post('/stores', ['name' => 'Second shop'])->assertSessionHasErrors('name');
        $this->assertSame(1, $basic->bots()->count());

        $business = $this->customer('business');
        foreach (range(2, 5) as $n) {
            $this->actingAs($business)->post('/stores', ['name' => "Branch {$n}"])->assertRedirect('/bot-studio');
        }
        $this->actingAs($business)->post('/stores', ['name' => 'Branch 6'])->assertSessionHasErrors('name');
        $this->assertSame(5, $business->bots()->count());
        // The new store becomes the one being worked on.
        $this->assertSame('Branch 5', $business->fresh()->bot->business_name);
    }

    public function test_switching_stores_scopes_the_app_and_is_limited_to_the_account(): void
    {
        $owner = $this->customer('business');
        $this->actingAs($owner)->post('/stores', ['name' => 'Branch 2']);
        [$first, $second] = $owner->bots()->get()->all();

        $this->actingAs($owner)->post("/stores/{$first->id}/switch")->assertSessionHas('success');
        $this->assertSame($first->id, $owner->fresh()->bot->id);
        $second->calls()->create(['title' => 'Branch call', 'status' => 'completed', 'started_at' => now(), 'duration_seconds' => 30]);
        $this->actingAs($owner->fresh())->get('/calls')->assertInertia(fn ($p) => $p->where('calls.total', 0));

        $this->actingAs($owner)->post("/stores/{$second->id}/switch");
        $this->actingAs($owner->fresh())->get('/calls')->assertInertia(fn ($p) => $p->where('calls.total', 1));

        // Someone else's store: 404.
        $this->actingAs($this->customer())->post("/stores/{$first->id}/switch")->assertNotFound();
    }

    public function test_after_a_downgrade_only_the_oldest_stores_take_calls(): void
    {
        $owner = $this->customer('business');
        $this->actingAs($owner)->post('/stores', ['name' => 'Branch 2']);
        [$first, $second] = $owner->bots()->get()->all();
        $owner->forceFill(['plan' => 'basic'])->save();

        $this->postJson("/api/widget/{$first->public_key}/start")->assertOk();
        $this->postJson("/api/widget/{$second->public_key}/start")->assertStatus(402)->assertJson(['reason' => 'store_limit']);
    }

    public function test_owner_removes_a_store_but_never_the_last_one(): void
    {
        $owner = $this->customer('business');
        $this->actingAs($owner)->post('/stores', ['name' => 'Branch 2']);
        $second = $owner->bots()->get()->last();

        $this->actingAs($owner)->delete("/stores/{$second->id}", ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
        $this->actingAs($owner)->delete("/stores/{$second->id}", ['confirm' => 'DELETE'])->assertRedirect('/settings');
        $this->assertNull(Bot::find($second->id));

        $last = $owner->bots()->first();
        $this->actingAs($owner)->delete("/stores/{$last->id}", ['confirm' => 'DELETE'])->assertSessionHas('error');
        $this->assertNotNull(Bot::find($last->id));
    }

    public function test_team_size_follows_the_plan(): void
    {
        $basic = $this->customer();
        $this->actingAs($basic)->post('/settings/team', ['name' => 'Ali', 'email' => 'ali@example.com', 'role' => 'operator'])->assertSessionHasErrors('email');

        $business = $this->customer('business');
        foreach (range(1, 4) as $n) {
            $this->actingAs($business)->post('/settings/team', ['name' => "Staff {$n}", 'email' => "staff{$n}@example.com", 'role' => 'viewer'])->assertSessionHasNoErrors();
        }
        $this->actingAs($business)->post('/settings/team', ['name' => 'Staff 5', 'email' => 'staff5@example.com', 'role' => 'viewer'])->assertSessionHasErrors('email');
        $this->assertSame(4, $business->members()->count());
    }

    public function test_billing_settings_and_admin_pages_render_with_plan_data(): void
    {
        $owner = $this->customer('business');
        $this->actingAs($owner)->post('/stores', ['name' => 'Branch 2']);
        $owner->topUps()->create(['plan' => 'business', 'months' => 1, 'amount_pkr' => 25000, 'method' => 'bank', 'reference' => 'FT-9']);

        $this->actingAs($owner)->get('/billing')->assertOk()->assertInertia(fn ($p) => $p
            ->where('subscription.plan', 'business')->where('subscription.storesUsed', 2)->where('subscription.stores', 5)
            ->has('plans', 2)->has('payments', 1)->has('accounts', 3));
        $this->actingAs($owner)->get('/settings')->assertOk()->assertInertia(fn ($p) => $p
            ->where('stores.limit', 5)->has('stores.list', 2)->where('team.max', 4));
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p
            ->where('auth.plan.active', true)->where('auth.plan.name', 'Business')->has('auth.stores', 2));

        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn ($p) => $p->where('stats.activePlans.business', 1));
        $this->actingAs($admin)->get('/admin/accounts')->assertOk()->assertInertia(fn ($p) => $p->where('accounts.0.stores', 2)->where('accounts.0.plan', 'Business'));
        $this->actingAs($admin)->get("/admin/accounts/{$owner->id}")->assertOk()->assertInertia(fn ($p) => $p->has('stores', 2)->has('payments', 1)->where('account.plan', 'business'));
        $this->actingAs($admin)->get('/admin/top-ups')->assertOk()->assertInertia(fn ($p) => $p->where('topUps.0.amountPkr', 25000)->where('topUps.0.plan', 'Business'));
    }

    public function test_members_can_switch_stores_but_only_the_owner_adds_them(): void
    {
        $owner = $this->customer('business');
        $this->actingAs($owner)->post('/stores', ['name' => 'Branch 2']);
        $member = new User(['name' => 'Viewer', 'email' => 'v@example.com', 'password' => 'secret-pass']);
        $member->forceFill(['owner_id' => $owner->id, 'team_role' => 'viewer'])->save();
        $first = $owner->bots()->first();

        $this->actingAs($member)->post("/stores/{$first->id}/switch")->assertSessionHas('success');
        $this->assertSame($first->id, $member->fresh()->bot->id);
        $this->actingAs($member)->post('/stores', ['name' => 'Branch 3'])->assertSessionHas('error');
        $this->assertSame(2, $owner->bots()->count());
    }
}
