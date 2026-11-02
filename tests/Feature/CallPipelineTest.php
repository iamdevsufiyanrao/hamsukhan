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
            'cost' => 0.25,
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

    public function test_end_of_call_report_creates_call_and_booking_once(): void
    {
        $user = $this->customer();
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

        $this->assertEquals(0.25, (float) $call->cost); // Vapi's own cost from the report, for the super admin
    }

    public function test_webhook_secret_is_enforced_when_configured(): void
    {
        config(['hamsukhan.vapi.webhook_secret' => 's3cret']);
        $bot = $this->customer()->bot;

        $this->postJson('/api/webhooks/vapi', $this->report($bot->id, 'vapi_x'))->assertUnauthorized();
        $this->postJson('/api/webhooks/vapi', $this->report($bot->id, 'vapi_x'), ['x-vapi-secret' => 's3cret'])->assertOk();
        $this->assertSame(1, $bot->calls()->count());
    }

    public function test_other_message_types_are_ignored(): void
    {
        $bot = $this->customer()->bot;
        $this->postJson('/api/webhooks/vapi', ['message' => ['type' => 'status-update', 'status' => 'in-progress']])->assertOk();
        $this->assertSame(0, $bot->calls()->count());
    }

    public function test_widget_start_needs_an_active_plan(): void
    {
        $unpaid = $this->customer(null)->bot;
        $this->postJson("/api/widget/{$unpaid->public_key}/start")->assertStatus(402)->assertJson(['reason' => 'no_plan']);

        $expired = $this->customer();
        $expired->forceFill(['plan_expires_at' => now()->subMinute()])->save();
        $this->postJson("/api/widget/{$expired->bot->public_key}/start")->assertStatus(402)->assertJson(['reason' => 'no_plan']);

        $paid = $this->customer()->bot;
        $this->postJson("/api/widget/{$paid->public_key}/start")
            ->assertOk()
            ->assertJsonPath('overrides.maxDurationSeconds', $paid->max_call_minutes * 60)
            ->assertJsonPath('overrides.metadata.botId', $paid->id);
    }

    public function test_fair_use_limit_shortens_then_stops_calls_and_zero_means_unlimited(): void
    {
        config(['hamsukhan.plans.basic.fair_use_minutes' => 10]);
        $bot = $this->customer()->bot;
        $bot->calls()->create(['title' => 'Long', 'status' => 'completed', 'started_at' => now(), 'duration_seconds' => 9 * 60 + 10]);
        // 50 seconds left of the 10 minutes.
        $this->postJson("/api/widget/{$bot->public_key}/start")->assertOk()->assertJsonPath('overrides.maxDurationSeconds', 50);

        $bot->calls()->create(['title' => 'More', 'status' => 'completed', 'started_at' => now(), 'duration_seconds' => 60]);
        $this->postJson("/api/widget/{$bot->public_key}/start")->assertStatus(402)->assertJson(['reason' => 'fair_use']);

        // 0 = no fair-use limit.
        config(['hamsukhan.plans.basic.fair_use_minutes' => 0]);
        $this->postJson("/api/widget/{$bot->public_key}/start")->assertOk()->assertJsonPath('overrides.maxDurationSeconds', $bot->max_call_minutes * 60);
    }

    public function test_widget_config_is_public_and_hides_secrets(): void
    {
        $bot = $this->customer()->bot;
        $res = $this->getJson("/api/widget/{$bot->public_key}/config")->assertOk()->assertJsonStructure(['active', 'widget' => ['position', 'states']]);
        $this->assertStringNotContainsString('prompt', $res->getContent());
    }

    public function test_simulated_test_call_is_stored_as_a_test(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->postJson('/test-call/finish', ['seconds' => 60])->assertOk()->assertJson(['ok' => true]);

        $this->assertTrue($user->bot->calls()->first()->is_test);
    }

    public function test_users_cannot_read_other_users_calls(): void
    {
        $owner = $this->customer();
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
