<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_render_for_admin_only(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(3);
        foreach (['/admin', '/admin/accounts', "/admin/accounts/{$customer->id}", '/admin/top-ups'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
            $this->actingAs($customer)->get($url)->assertForbidden();
        }
    }

    public function test_impersonate_and_return(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(3);

        $this->actingAs($admin)->post("/admin/accounts/{$customer->id}/impersonate")->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($customer);
        $this->assertSame($admin->id, session('impersonator_id'));
        $this->get('/dashboard')->assertOk();

        $this->post('/impersonation/stop')->assertRedirect("/admin/accounts/{$customer->id}");
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('impersonator_id'));
        $this->assertTrue(AdminAction::where('action', 'impersonate')->where('target_user_id', $customer->id)->exists());
    }

    public function test_cannot_impersonate_admins_and_customers_cannot_stop_fake_session(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/accounts/'.$this->admin()->id.'/impersonate')->assertForbidden();
        $this->actingAs($this->customer())->post('/impersonation/stop')->assertForbidden();
    }

    public function test_suspend_blocks_login_and_takes_bot_offline(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(5);

        $this->actingAs($admin)->post("/admin/accounts/{$customer->id}/suspend", ['reason' => 'Chargeback'])->assertSessionHas('success');
        $this->assertTrue($customer->fresh()->isSuspended());
        $this->assertFalse($customer->bot->fresh()->is_active);
        $this->postJson("/api/widget/{$customer->bot->public_key}/start")->assertStatus(409);

        // An already-open session is ended on the next request.
        $this->actingAs($customer->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => $customer->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($admin)->post("/admin/accounts/{$customer->id}/activate");
        $this->assertFalse($customer->fresh()->isSuspended());
        $this->assertTrue($customer->bot->fresh()->is_active);
        $this->assertSame(['suspend', 'activate'], AdminAction::orderBy('id')->pluck('action')->all());
    }

    public function test_suspend_needs_a_reason(): void
    {
        $this->actingAs($this->admin())->post('/admin/accounts/'.$this->customer()->id.'/suspend', [])->assertSessionHasErrors('reason');
    }

    public function test_adjust_balance_is_ledgered_and_audited(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(2);

        $this->actingAs($admin)->post("/admin/accounts/{$customer->id}/balance", ['amount' => 3.5, 'reason' => 'Refund'])->assertSessionHas('success');
        $this->assertSame('5.5000', $customer->fresh()->balance);
        $this->assertSame('adjustment', $customer->transactions()->latest('id')->first()->type);

        $this->actingAs($admin)->post("/admin/accounts/{$customer->id}/balance", ['amount' => -50, 'reason' => 'Too much'])->assertSessionHas('error');
        $this->assertSame('5.5000', $customer->fresh()->balance);
        $this->assertSame(1, AdminAction::where('action', 'adjust_balance')->count());
    }

    public function test_admin_login_goes_to_admin_overview(): void
    {
        $admin = $this->admin();
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/admin');
    }

    public function test_top_up_review_is_audited(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $topUp = $customer->topUps()->create(['amount_usd' => 10, 'amount_pkr' => 2800, 'method' => 'jazzcash', 'reference' => 'JC-1']);
        $this->actingAs($admin)->post("/admin/top-ups/{$topUp->id}/approve");
        $this->assertTrue(AdminAction::where('action', 'approve_topup')->where('target_user_id', $customer->id)->exists());
    }
}
