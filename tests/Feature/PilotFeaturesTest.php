<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\VapiService;
use App\Services\WalletService;
use App\Support\BusinessHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Alerts, spending cap, opening hours, domain allow-list, knowledge gaps, insights and email verification. */
class PilotFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function report(int $botId, string $callId, array $structured = [], ?string $startedAt = null): array
    {
        $start = $startedAt ? Carbon::parse($startedAt) : now()->subMinutes(2);

        return ['message' => [
            'type' => 'end-of-call-report',
            'endedReason' => 'customer-ended-call',
            'call' => ['id' => $callId, 'metadata' => ['botId' => $botId]],
            'startedAt' => $start->toIso8601String(),
            'endedAt' => $start->copy()->addMinutes(2)->toIso8601String(),
            'durationSeconds' => 120,
            'analysis' => ['summary' => 'x', 'structuredData' => $structured + ['title' => 'Haircut', 'outcome' => 'Booking']],
            'artifact' => ['messages' => [['role' => 'user', 'message' => 'Kal slot hai?']]],
        ]];
    }

    private function booking(): array
    {
        return ['booking' => ['customerName' => 'Ayesha', 'phone' => '0300 1234567', 'request' => 'Haircut', 'preferredTime' => 'Kal 5 baje']];
    }

    public function test_new_lead_alerts_the_owner_once_even_when_vapi_retries(): void
    {
        Mail::fake();
        $user = $this->customer(10);

        $this->postJson('/api/webhooks/vapi', $this->report($user->bot->id, 'c1', $this->booking()))->assertOk();
        $this->postJson('/api/webhooks/vapi', $this->report($user->bot->id, 'c1', $this->booking()))->assertOk();

        $this->assertSame(1, NotificationLog::where('kind', 'lead')->where('channel', 'email')->where('status', 'sent')->count());
        $this->assertSame('+923001234567', Booking::firstOrFail()->phone);
    }

    public function test_lead_alert_goes_to_whatsapp_when_switched_on(): void
    {
        Mail::fake();
        config(['hamsukhan.whatsapp.token' => 'wa', 'hamsukhan.whatsapp.phone_number_id' => '123', 'hamsukhan.whatsapp.template' => 'lead_alert']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $user = $this->customer(10);
        $user->update(['notify_whatsapp' => true, 'whatsapp_number' => '+923331112222']);

        $this->postJson("/api/widget/{$user->bot->public_key}/callback", ['name' => 'Ali', 'phone' => '03001234567', 'consent' => true])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/123/messages') && $r['to'] === '923331112222' && $r['template']['name'] === 'lead_alert');
        $this->assertSame(2, NotificationLog::where('kind', 'lead')->where('status', 'sent')->count()); // email + whatsapp
    }

    public function test_failed_alert_is_logged_and_does_not_break_the_call(): void
    {
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('SMTP down'));
        $user = $this->customer(10);

        $this->postJson('/api/webhooks/vapi', $this->report($user->bot->id, 'c2', $this->booking()))->assertOk();

        $this->assertSame(1, $user->bot->calls()->count());
        $this->assertSame('failed', NotificationLog::firstOrFail()->status);
    }

    public function test_low_balance_alert_is_sent_once_per_top_up(): void
    {
        Mail::fake();
        $user = $this->customer(0.5);
        $wallet = app(WalletService::class);

        $wallet->checkAlerts($user);
        $wallet->checkAlerts($user);
        $this->assertSame(1, NotificationLog::where('kind', 'low_balance')->count());
    }

    public function test_monthly_cap_limits_and_then_blocks_calls(): void
    {
        $user = $this->customer(50);
        $user->update(['monthly_cap' => 1.00]);
        $wallet = app(WalletService::class);

        // $1 at $0.12/min = 500 seconds, below the 10-minute bot maximum
        $this->assertSame(500, $wallet->maxAffordableSeconds($user->fresh(), 10));

        $user->transactions()->create(['type' => 'call_charge', 'amount' => -1.00, 'balance_after' => 49, 'description' => 'x', 'created_at' => now()]);
        $this->postJson("/api/widget/{$user->bot->public_key}/start")->assertStatus(402)->assertJson(['reason' => 'cap_reached']);
    }

    public function test_notification_settings_store_the_cap_in_wallet_currency(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->patch('/settings/notifications', ['email' => true, 'whatsapp' => true, 'whatsappNumber' => '0333 1112222', 'dailySummary' => false, 'monthlyCapPkr' => 2800])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertEquals(10.0, (float) $user->monthly_cap); // 2800 / 280
        $this->assertSame('+923331112222', $user->whatsapp_number);
        $this->assertFalse($user->notify_daily_summary);

        $this->actingAs($user)->patch('/settings/notifications', ['email' => true, 'whatsapp' => true, 'whatsappNumber' => '12', 'dailySummary' => true])
            ->assertSessionHasErrors('whatsappNumber');
    }

    public function test_opening_hours_reach_the_prompt_and_mark_after_hours_calls(): void
    {
        $user = $this->customer(10);
        $hours = ['mon' => ['open' => '10:00', 'close' => '21:00'], 'tue' => ['open' => '10:00', 'close' => '21:00', 'closed' => false], 'sun' => ['closed' => true]];
        $this->actingAs($user)->patch('/bot-studio/hours', ['hours' => $hours, 'holidays' => [['date' => '2026-12-25', 'note' => 'Quaid Day']]])->assertSessionHasNoErrors();

        $bot = $user->bot->fresh();
        $prompt = $bot->systemPrompt();
        $this->assertStringContainsString('Monday: 10:00 AM to 9:00 PM', $prompt);
        $this->assertStringContainsString('Sunday: closed', $prompt);

        // Monday 2026-10-19 23:30 Pakistan time = after hours; 12:00 = open; the holiday = closed
        $this->assertFalse(BusinessHours::isOpen($bot, Carbon::parse('2026-10-19 23:30', 'Asia/Karachi')));
        $this->assertTrue(BusinessHours::isOpen($bot, Carbon::parse('2026-10-19 12:00', 'Asia/Karachi')));
        $this->assertFalse(BusinessHours::isOpen($bot, Carbon::parse('2026-12-25 12:00', 'Asia/Karachi')));

        $this->postJson('/api/webhooks/vapi', $this->report($bot->id, 'late', [], '2026-10-19T18:30:00Z'))->assertOk(); // 23:30 PKT
        $this->assertTrue($bot->calls()->firstOrFail()->after_hours);
    }

    public function test_widget_only_runs_on_allowed_domains(): void
    {
        $user = $this->customer(10);
        $this->actingAs($user)->patch('/bot-studio/domains', ['domains' => ['https://www.MySalon.pk/contact', '']])->assertSessionHasNoErrors();
        $this->assertSame(['mysalon.pk'], $user->bot->fresh()->allowed_domains);
        $key = $user->bot->public_key;

        $this->getJson("/api/widget/{$key}/config", ['Origin' => 'https://evil.example'])->assertStatus(403);
        $this->getJson("/api/widget/{$key}/config", ['Origin' => 'https://book.mysalon.pk'])->assertOk();
        $this->postJson("/api/widget/{$key}/start", [], ['Origin' => 'https://evil.example'])->assertStatus(403);
        $this->postJson("/api/widget/{$key}/start", [], ['Origin' => config('app.url')])->assertOk(); // dashboard test call

        $this->actingAs($user)->patch('/bot-studio/domains', ['domains' => ['not a domain']])->assertSessionHasErrors('domains');
    }

    public function test_unanswered_questions_are_stored_and_shown_in_insights(): void
    {
        $user = $this->customer(10);
        $this->postJson('/api/webhooks/vapi', $this->report($user->bot->id, 'g1', ['unansweredQuestions' => ['Parking hai?', '']] + $this->booking()))->assertOk();
        $this->postJson('/api/webhooks/vapi', $this->report($user->bot->id, 'g2', ['unansweredQuestions' => ['parking hai']]))->assertOk();

        $this->assertSame(['Parking hai?'], $user->bot->calls()->where('vapi_call_id', 'g1')->first()->unanswered);
        $this->assertArrayHasKey('unansweredQuestions', app(VapiService::class)->assistantPayload($user->bot)['analysisPlan']['structuredDataPlan']['schema']['properties']);

        $this->actingAs($user)->get('/insights')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Insights')
            ->where('gaps.0.count', 2)
            ->where('stats.leads', 1)
            ->where('funnel.0.value', 2));
    }

    public function test_daily_summary_only_on_days_with_activity(): void
    {
        Mail::fake();
        $user = $this->customer(10);
        $notify = app(NotificationService::class);

        $this->assertFalse($notify->dailySummary($user, now()));
        $this->postJson('/api/webhooks/vapi', $this->report($user->bot->id, 'd1', $this->booking()))->assertOk();
        $this->assertTrue($notify->dailySummary($user->fresh(), now()));
        $this->assertTrue($notify->dailySummary($user->fresh(), now())); // second run: deduped, not re-sent
        $this->assertSame(1, NotificationLog::where('kind', 'daily_summary')->count());
    }

    public function test_lead_statuses_include_contacted_lost_and_spam(): void
    {
        $user = $this->customer();
        $booking = $user->bot->bookings()->create(['customer_name' => 'A', 'request' => 'x']);
        foreach (['contacted', 'lost', 'spam'] as $status) {
            $this->actingAs($user)->patch("/bookings/{$booking->id}", ['status' => $status])->assertSessionHas('success');
            $this->assertSame($status, $booking->fresh()->status);
        }
    }

    public function test_email_verification_is_enforced_only_when_switched_on(): void
    {
        Mail::fake();
        $user = User::factory()->unverified()->create();
        app(\App\Services\BotProvisioner::class)->provision($user);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        config(['hamsukhan.site.require_email_verification' => true]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/email/verify');
        $this->actingAs($user)->get('/email/verify')->assertOk();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($url)->assertRedirect('/dashboard');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    public function test_responses_carry_a_request_id(): void
    {
        $this->get('/terms')->assertHeader('X-Request-Id');
    }
}
