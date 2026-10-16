<?php

namespace Tests\Feature;

use App\Support\CallScenarios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function report(int $botId, string $callId, int $seconds = 120): array
    {
        return ['message' => [
            'type' => 'end-of-call-report',
            'endedReason' => 'assistant-ended-call',
            'call' => ['id' => $callId, 'metadata' => ['botId' => $botId]],
            'startedAt' => now()->subSeconds($seconds)->toIso8601String(),
            'endedAt' => now()->toIso8601String(),
            'durationSeconds' => $seconds,
            'recordingUrl' => 'https://example.com/rec.mp3',
            'analysis' => [
                'summary' => 'Caller booked a screen repair.',
                'structuredData' => [
                    'title' => 'Screen repair booking',
                    'outcome' => 'Booking',
                    'booking' => ['customerName' => 'Bilal Ahmed', 'phone' => '0301 5551234', 'request' => 'Dell XPS, screen', 'preferredTime' => 'Saturday 11am'],
                ],
            ],
            'artifact' => ['messages' => [
                ['role' => 'system', 'message' => 'You are a receptionist'],
                ['role' => 'assistant', 'message' => 'Hi, how can I help?'],
                ['role' => 'user', 'message' => 'I need a screen repair.'],
            ]],
        ]];
    }

    public function test_end_of_call_report_creates_call_booking_and_charges_once(): void
    {
        $user = $this->customer(5);
        $bot = $user->bot;

        $this->postJson('/api/webhooks/vapi', $this->report($bot->id, 'vapi_abc', 120))->assertOk();
        $this->postJson('/api/webhooks/vapi', $this->report($bot->id, 'vapi_abc', 120))->assertOk(); // Vapi retry

        $this->assertSame(1, $bot->calls()->count());
        $call = $bot->calls()->first();
        $this->assertSame('Screen repair booking', $call->title);
        $this->assertSame('Booking', $call->outcome_tag);
        $this->assertSame('completed', $call->status);
        $this->assertSame(2, $call->messages()->count()); // system prompt is not stored
        $this->assertSame('Bilal Ahmed', $bot->bookings()->first()->customer_name);

        $expected = round(120 * config('hamsukhan.rate_per_minute') / 60, 4);
        $this->assertEquals($expected, (float) $call->cost);
        $this->assertEquals(round(5 - $expected, 4), (float) $user->fresh()->balance);
        $this->assertSame(1, $user->transactions()->where('type', 'call_charge')->count());
    }

    public function test_webhook_secret_is_enforced_when_configured(): void
    {
        config(['hamsukhan.vapi.webhook_secret' => 's3cret']);
        $bot = $this->customer(5)->bot;

        $this->postJson('/api/webhooks/vapi', $this->report($bot->id, 'vapi_x'))->assertUnauthorized();
        $this->postJson('/api/webhooks/vapi', $this->report($bot->id, 'vapi_x'), ['x-vapi-secret' => 's3cret'])->assertOk();
        $this->assertSame(1, $bot->calls()->count());
    }

    public function test_other_message_types_are_ignored(): void
    {
        $bot = $this->customer(5)->bot;
        $this->postJson('/api/webhooks/vapi', ['message' => ['type' => 'status-update', 'status' => 'in-progress']])->assertOk();
        $this->assertSame(0, $bot->calls()->count());
    }

    public function test_widget_start_refuses_without_balance_and_caps_duration(): void
    {
        $poor = $this->customer(0.01)->bot;
        $this->postJson("/api/widget/{$poor->public_key}/start")->assertStatus(402)->assertJson(['reason' => 'no_balance']);

        $rich = $this->customer(100)->bot;
        $this->postJson("/api/widget/{$rich->public_key}/start")
            ->assertOk()
            ->assertJsonPath('overrides.maxDurationSeconds', $rich->max_call_minutes * 60)
            ->assertJsonPath('overrides.metadata.botId', $rich->id);

        // $0.10 at $0.12/min buys 50 seconds.
        $some = $this->customer(0.10)->bot;
        $this->postJson("/api/widget/{$some->public_key}/start")->assertJsonPath('overrides.maxDurationSeconds', 50);
    }

    public function test_widget_config_is_public_and_hides_secrets(): void
    {
        $bot = $this->customer()->bot;
        $res = $this->getJson("/api/widget/{$bot->public_key}/config")->assertOk()->assertJsonStructure(['active', 'widget' => ['position', 'states']]);
        $this->assertStringNotContainsString('prompt', $res->getContent());
    }

    public function test_simulated_test_call_flows_through_billing(): void
    {
        $user = $this->customer(5);
        $this->actingAs($user)->postJson('/test-call/finish', ['seconds' => 60])->assertOk()->assertJson(['ok' => true]);

        $call = $user->bot->calls()->first();
        $this->assertTrue($call->is_test);
        $this->assertLessThan(5, (float) $user->fresh()->balance);
    }

    public function test_users_cannot_read_other_users_calls(): void
    {
        $owner = $this->customer(5);
        $this->postJson('/api/webhooks/vapi', $this->report($owner->bot->id, 'vapi_private'));
        $call = $owner->bot->calls()->first();

        $this->actingAs($this->customer())->getJson("/calls/{$call->id}")->assertNotFound();
        $this->actingAs($owner)->getJson("/calls/{$call->id}")->assertOk()->assertJsonPath('transcript.0.1', 'Hi, how can I help?');
    }

    public function test_scenarios_render_without_placeholders(): void
    {
        foreach (CallScenarios::all() as $i => $s) {
            $r = CallScenarios::render($s, $i * 7);
            $this->assertDoesNotMatchRegularExpression('/\{[a-zA-Z]+\}/', json_encode($r));
        }
    }
}
