<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/calls')->assertRedirect('/login');
    }

    public function test_landing_page_is_public_and_hides_payment_details(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Landing')
                ->missing('config.accounts')
                ->missing('config.pkrPerUsd')
                ->has('config.ratePerMinute'));

        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Landing')->has('config.accounts', 3));
    }

    public function test_register_creates_account_with_default_bot(): void
    {
        $this->post('/register', [
            'name' => 'Hira Malik',
            'email' => 'hira@example.com',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'terms' => true,
        ])->assertRedirect('/dashboard');

        $user = User::where('email', 'hira@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->bot);
        $this->assertNotNull($user->bot->vapi_assistant_id); // simulated sync ran
        $this->assertSame('0.0000', $user->balance);
        $this->assertSame('Asia/Karachi', $user->timezone);
    }

    public function test_new_user_model_has_timezone_before_reload(): void
    {
        $this->assertSame('Asia/Karachi', User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-pass'])->timezone);
    }

    public function test_register_requires_terms(): void
    {
        $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass'])
            ->assertSessionHasErrors('terms');
    }

    public function test_login_with_wrong_password_fails(): void
    {
        $user = $this->customer();
        $this->post('/login', ['email' => $user->email, 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_and_logout(): void
    {
        $user = $this->customer();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_every_app_page_renders(): void
    {
        $user = $this->customer(10);
        foreach (['/dashboard', '/bot-studio', '/bot-studio?tab=widget', '/calls', '/bookings', '/billing', '/settings'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_admin_without_bot_lands_on_payments(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertRedirect('/admin');
    }

    public function test_account_missing_a_bot_gets_one(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertNotNull($user->fresh()->bot);
    }

    public function test_admin_pages_are_admin_only(): void
    {
        $this->actingAs($this->customer())->get('/admin/top-ups')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/top-ups')->assertOk();
    }
}
