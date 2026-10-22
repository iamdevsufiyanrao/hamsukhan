<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\VapiService;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Live booking (free slots, tool calls), Google Calendar, two-step login and device logout. */
class CalendarAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Monday 2026-10-26, 09:00 Pakistan time; open 10:00–13:00 with 60-minute slots. */
    private function liveBot(): User
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 09:00', 'Asia/Karachi'));
        Mail::fake();
        $user = $this->customer(10);
        $user->bot->update(['live_booking' => true, 'slot_minutes' => 60, 'business_hours' => ['mon' => ['open' => '10:00', 'close' => '13:00']]]);

        return $user->fresh();
    }

    private function tool(int $botId, string $name, array $args, string $callId = 'call_1'): array
    {
        return ['message' => [
            'type' => 'tool-calls',
            'call' => ['id' => $callId, 'metadata' => ['botId' => $botId]],
            'toolCallList' => [['id' => 'tc_'.$name, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $args]]],
        ]];
    }

    public function test_free_slots_skip_bookings_and_google_busy_times(): void
    {
        $user = $this->liveBot();
        $bot = $user->bot;
        $bot->bookings()->create(['customer_name' => 'A', 'request' => 'x', 'starts_at' => Carbon::parse('2026-10-26 10:00', 'Asia/Karachi')->utc(), 'ends_at' => Carbon::parse('2026-10-26 11:00', 'Asia/Karachi')->utc()]);
        $this->assertSame(['11:00', '12:00'], app(AvailabilityService::class)->freeSlots($bot, now()));

        config(['hamsukhan.google.client_id' => 'id', 'hamsukhan.google.client_secret' => 'secret']);
        $user->googleCalendar()->create(['refresh_token' => 'r', 'access_token' => 'a', 'token_expires_at' => now()->addHour()]);
        Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => [['start' => '2026-10-26T07:00:00Z', 'end' => '2026-10-26T07:30:00Z']]]]])]); // 12:00–12:30 PKT

        $this->assertSame(['11:00'], app(AvailabilityService::class)->freeSlots($bot->fresh(), now()));
        $this->assertSame([], app(AvailabilityService::class)->freeSlots($bot, now()->addDay())); // Tuesday: closed
    }

    public function test_bot_books_during_the_call_once_and_the_slot_is_then_taken(): void
    {
        $user = $this->liveBot();
        $bot = $user->bot;

        $this->postJson('/api/webhooks/vapi', $this->tool($bot->id, 'check_availability', ['date' => '2026-10-26']))
            ->assertOk()->assertJsonPath('results.0.toolCallId', 'tc_check_availability')
            ->assertJsonPath('results.0.result', fn ($r) => str_contains($r, '10:00 AM') && str_contains($r, '12:00 PM'));

        $args = ['date' => '2026-10-26', 'time' => '11:00', 'customerName' => 'Ayesha', 'phone' => '0300 1234567', 'service' => 'Haircut'];
        $this->postJson('/api/webhooks/vapi', $this->tool($bot->id, 'book_appointment', $args))->assertJsonPath('results.0.result', fn ($r) => str_starts_with($r, 'Booked'));
        $this->postJson('/api/webhooks/vapi', $this->tool($bot->id, 'book_appointment', $args))->assertJsonPath('results.0.result', fn ($r) => str_starts_with($r, 'Booked')); // Vapi retry
        $this->assertSame(1, Booking::count());

        // Another caller can't take 11:00 any more
        $this->postJson('/api/webhooks/vapi', $this->tool($bot->id, 'book_appointment', ['customerName' => 'Bilal', 'phone' => '0301 1111111'] + $args, 'call_2'))
            ->assertJsonPath('results.0.result', fn ($r) => str_contains($r, 'no longer free'));
        $this->assertSame(1, Booking::count());

        $booking = Booking::firstOrFail();
        $this->assertSame(['confirmed', '+923001234567', 'call_1'], [$booking->status, $booking->phone, $booking->vapi_call_id]);

        // The end-of-call report links that booking instead of creating a second one
        $this->postJson('/api/webhooks/vapi', ['message' => [
            'type' => 'end-of-call-report', 'call' => ['id' => 'call_1', 'metadata' => ['botId' => $bot->id]], 'durationSeconds' => 90,
            'analysis' => ['structuredData' => ['outcome' => 'Booking', 'booking' => ['customerName' => 'Ayesha', 'request' => 'Haircut']]],
            'artifact' => ['messages' => [['role' => 'user', 'message' => 'hi']]],
        ]])->assertOk();
        $this->assertSame(1, Booking::count());
        $this->assertNotNull($booking->fresh()->call_id);
    }

    public function test_tools_are_only_sent_to_vapi_when_live_booking_is_on(): void
    {
        $user = $this->liveBot();
        $payload = app(VapiService::class)->assistantPayload($user->bot);
        $this->assertSame(['check_availability', 'book_appointment'], array_column(array_column($payload['model']['tools'], 'function'), 'name'));
        $this->assertStringContainsString('book_appointment', $payload['model']['messages'][0]['content']);

        $user->bot->update(['live_booking' => false]);
        $this->assertArrayNotHasKey('tools', app(VapiService::class)->assistantPayload($user->bot->fresh())['model']);

        $this->actingAs($user)->patch('/bot-studio/hours', ['hours' => [], 'holidays' => [], 'liveBooking' => true])->assertSessionHasErrors('liveBooking');
    }

    public function test_booking_lands_in_google_calendar_and_is_removed_when_lost(): void
    {
        $user = $this->liveBot();
        config(['hamsukhan.google.client_id' => 'id', 'hamsukhan.google.client_secret' => 'secret']);
        $user->googleCalendar()->create(['refresh_token' => 'r', 'access_token' => 'a', 'token_expires_at' => now()->addHour()]);
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => []]]]),
            'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt_1']),
            'www.googleapis.com/calendar/v3/calendars/primary/events/evt_1' => Http::response(null, 204),
        ]);

        $this->postJson('/api/webhooks/vapi', $this->tool($user->bot->id, 'book_appointment', ['date' => '2026-10-26', 'time' => '12:00', 'customerName' => 'Ayesha', 'phone' => '03001234567']));
        $booking = Booking::firstOrFail();
        $this->assertSame('evt_1', $booking->google_event_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/events') && $r['start']['timeZone'] === 'Asia/Karachi' && str_starts_with($r['start']['dateTime'], '2026-10-26T12:00:00'));

        $this->actingAs($user)->patch("/bookings/{$booking->id}", ['status' => 'lost'])->assertSessionHas('success');
        $this->assertNull($booking->fresh()->google_event_id);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/events/evt_1'));
    }

    public function test_google_oauth_connects_with_a_valid_state_only(): void
    {
        $user = $this->customer();
        config(['hamsukhan.google.client_id' => 'id', 'hamsukhan.google.client_secret' => 'secret']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600, 'scope' => 'openid email https://www.googleapis.com/auth/calendar.events']),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'owner@gmail.com']),
        ]);

        $this->actingAs($user)->post('/integrations/google/connect')->assertRedirect();
        $state = session('google_oauth_state');
        $this->actingAs($user)->get('/integrations/google/callback?state=wrong&code=x')->assertSessionHas('error');

        $this->actingAs($user)->withSession(['google_oauth_state' => $state])->get("/integrations/google/callback?state={$state}&code=abc")->assertSessionHas('success');
        $cal = $user->fresh()->googleCalendar;
        $this->assertSame('owner@gmail.com', $cal->google_email);
        $this->assertSame('rt', $cal->refresh_token); // decrypted by the cast, stored encrypted
        $this->assertNotSame('rt', \DB::table('google_calendars')->value('refresh_token'));
    }

    public function test_two_step_login_with_code_and_single_use_recovery_code(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);
        app(\App\Services\BotProvisioner::class)->provision($user);

        $this->actingAs($user)->post('/settings/two-factor', ['password' => 'secret-pass'])->assertSessionHasNoErrors();
        $secret = $user->fresh()->two_factor_secret;
        $this->actingAs($user)->post('/settings/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($user)->post('/settings/two-factor/confirm', ['code' => Totp::at($secret, intdiv(time(), 30))])->assertSessionHas('recoveryCodes');
        $codes = session('recoveryCodes');
        $this->assertTrue($user->fresh()->hasTwoFactor());
        auth()->logout();

        // Password alone is not enough
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/two-factor-challenge', ['code' => Totp::at($secret, intdiv(time(), 30))])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass']);
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertRedirect('/dashboard');
        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass']);
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertSessionHasErrors('code'); // already used
        $this->assertSame(7, count($user->fresh()->two_factor_recovery_codes));
    }

    public function test_settings_page_shows_security_and_logout_others_needs_password(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);
        app(\App\Services\BotProvisioner::class)->provision($user);

        $this->actingAs($user)->get('/settings')->assertOk()->assertInertia(fn ($p) => $p->has('twoFactor')->has('sessions'));
        $this->actingAs($user)->post('/settings/sessions/logout-others', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $old = $user->fresh()->remember_token;
        $this->actingAs($user)->post('/settings/sessions/logout-others', ['password' => 'secret-pass'])->assertSessionHas('success');
        $this->assertNotSame($old, $user->fresh()->remember_token);
    }
}
