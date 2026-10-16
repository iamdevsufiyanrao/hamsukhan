<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\IntegrationDelivery;
use App\Services\Integrations\IntegrationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Hamsukhan\Connector\Signature;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hamsukhan.integrations.allow_private_urls' => true]); // no DNS in tests
    }

    private function orderReport(int $botId, string $callId): array
    {
        return ['message' => [
            'type' => 'end-of-call-report',
            'call' => ['id' => $callId, 'metadata' => ['botId' => $botId]],
            'durationSeconds' => 90,
            'startedAt' => now()->subSeconds(90)->toIso8601String(),
            'endedAt' => now()->toIso8601String(),
            'analysis' => ['summary' => 'Order placed', 'structuredData' => [
                'title' => 'Delivery order', 'outcome' => 'Order',
                'booking' => ['type' => 'order', 'customerName' => 'Sana Khan', 'phone' => '0333 7654321', 'request' => 'BBQ platter', 'preferredTime' => 'ASAP',
                    'details' => ['items' => ['BBQ platter', '2x naan'], 'address' => 'DHA Phase 6', 'unknown_field' => 'ignored']],
            ]],
            'artifact' => ['messages' => [['role' => 'assistant', 'message' => 'Hi'], ['role' => 'user', 'message' => 'One BBQ platter please']]],
        ]];
    }

    private function restaurant(): \App\Models\User
    {
        $user = $this->customer(10);
        $user->bot->update(['capture_fields' => \App\Support\CaptureFields::presets()['restaurant']]);

        return $user->fresh();
    }

    public function test_call_with_order_is_delivered_signed_to_webhook(): void
    {
        Http::fake(['shop.test/*' => Http::response(['ok' => true, 'reference' => 'ORD-77'])]);
        $user = $this->restaurant();
        $integration = $user->bot->integrations()->create(['name' => 'POS', 'kind' => 'webhook', 'url' => 'https://shop.test/hamsukhan/webhook', 'events' => ['order.created']]);

        $this->postJson('/api/webhooks/vapi', $this->orderReport($user->bot->id, 'v1'))->assertOk();

        $booking = $user->bot->bookings()->firstOrFail();
        $this->assertSame('order', $booking->type);
        $this->assertSame(['items' => 'BBQ platter, 2x naan', 'address' => 'DHA Phase 6'], $booking->details); // unknown keys dropped

        Http::assertSent(function (HttpRequest $r) use ($integration) {
            $body = $r->body();
            $json = json_decode($body, true);

            return $r->url() === 'https://shop.test/hamsukhan/webhook'
                && Signature::verify($body, $r->header(Signature::HEADER)[0] ?? null, $integration->secret)
                && $r->header('Hamsukhan-Event')[0] === 'order.created'
                && $json['booking']['details']['items'] === 'BBQ platter, 2x naan'
                && $json['booking']['customer_name'] === 'Sana Khan';
        });

        $delivery = IntegrationDelivery::firstOrFail();
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame('ORD-77', $delivery->external_reference);
        $this->assertSame('delivered', $integration->fresh()->last_status);
    }

    public function test_connectors_only_get_events_they_subscribed_to(): void
    {
        Http::fake();
        $user = $this->restaurant();
        $user->bot->integrations()->create(['name' => 'Bookings only', 'kind' => 'webhook', 'url' => 'https://crm.test/hook', 'events' => ['booking.created']]);
        $user->bot->integrations()->create(['name' => 'Paused', 'kind' => 'webhook', 'url' => 'https://crm.test/hook2', 'events' => ['order.created'], 'is_active' => false]);

        $this->postJson('/api/webhooks/vapi', $this->orderReport($user->bot->id, 'v2'))->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, IntegrationDelivery::count());
    }

    public function test_form_connector_posts_mapped_fields(): void
    {
        Http::fake(['pos.test/*' => Http::response('<html>saved</html>')]);
        $user = $this->restaurant();
        $user->bot->integrations()->create(['name' => 'Form', 'kind' => 'form', 'url' => 'https://pos.test/orders/new', 'events' => ['order.created'], 'field_map' => [
            ['target' => 'customer', 'source' => 'customer_name'],
            ['target' => 'items', 'source' => 'details.items'],
            ['target' => 'channel', 'source' => '', 'value' => 'phone-bot'],
        ]]);

        $this->postJson('/api/webhooks/vapi', $this->orderReport($user->bot->id, 'v3'));

        Http::assertSent(function (HttpRequest $r) {
            parse_str($r->body(), $fields);

            return str_starts_with($r->header('Content-Type')[0], 'application/x-www-form-urlencoded')
                && $fields === ['customer' => 'Sana Khan', 'items' => 'BBQ platter, 2x naan', 'channel' => 'phone-bot'];
        });
    }

    public function test_server_errors_retry_with_backoff_then_succeed(): void
    {
        $user = $this->restaurant();
        $user->bot->integrations()->create(['name' => 'Flaky', 'kind' => 'webhook', 'url' => 'https://flaky.test/hook', 'events' => ['order.created']]);
        Http::fakeSequence('flaky.test/*')->push('down', 503)->push(['ok' => true, 'reference' => 'R-1']);

        $this->postJson('/api/webhooks/vapi', $this->orderReport($user->bot->id, 'v4'));
        $d = IntegrationDelivery::firstOrFail();
        $this->assertSame('pending', $d->status);
        $this->assertSame(1, $d->attempts);
        $this->assertEqualsWithDelta(now()->addSeconds(IntegrationDispatcher::BACKOFF[0])->timestamp, $d->next_attempt_at->timestamp, 2);

        $this->artisan('hamsukhan:deliver-integrations')->assertSuccessful(); // not due yet
        $this->assertSame(1, $d->fresh()->attempts);

        $this->travel(61)->seconds();
        $this->artisan('hamsukhan:deliver-integrations')->assertSuccessful();
        $d->refresh();
        $this->assertSame('delivered', $d->status);
        $this->assertSame(2, $d->attempts);
        $this->assertSame('R-1', $d->external_reference);
    }

    public function test_client_errors_fail_without_retry_and_gives_up_after_five_tries(): void
    {
        $user = $this->restaurant();
        $integration = $user->bot->integrations()->create(['name' => 'Bad', 'kind' => 'webhook', 'url' => 'https://bad.test/hook', 'events' => ['order.created']]);
        Http::fake(['bad.test/*' => Http::response('nope', 422)]);
        $this->postJson('/api/webhooks/vapi', $this->orderReport($user->bot->id, 'v5'));
        $this->assertSame('failed', IntegrationDelivery::firstOrFail()->status);

        Http::fake(['down.test/*' => Http::response('x', 500)]);
        $integration->update(['url' => 'https://down.test/hook']);
        $d = IntegrationDelivery::firstOrFail();
        $d->update(['status' => 'pending', 'attempts' => 0]);
        $dispatcher = app(IntegrationDispatcher::class);
        foreach (range(1, 5) as $_) {
            $dispatcher->attempt($d->fresh('integration'));
        }
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertSame(5, $d->fresh()->attempts);
    }

    public function test_private_network_urls_are_blocked_when_not_allowed(): void
    {
        config(['hamsukhan.integrations.allow_private_urls' => false]);
        $user = $this->customer();

        foreach (['http://127.0.0.1/hook', 'http://10.0.0.5/hook', 'http://192.168.1.10/x', 'ftp://example.com'] as $url) {
            $this->actingAs($user)->post('/integrations', ['name' => 'X', 'kind' => 'webhook', 'url' => $url, 'events' => ['booking.created']])
                ->assertSessionHasErrors('url');
        }
        $this->assertSame(0, Integration::count());
    }

    public function test_send_test_uses_a_sample_and_reports_result(): void
    {
        Http::fake(['crm.test/*' => Http::response(['ok' => true, 'reference' => 'TEST-OK'])]);
        $user = $this->customer();
        $integration = $user->bot->integrations()->create(['name' => 'CRM', 'kind' => 'webhook', 'url' => 'https://crm.test/hook', 'events' => ['booking.created']]);

        $this->actingAs($user)->post("/integrations/{$integration->id}/test")->assertSessionHas('success');
        $d = IntegrationDelivery::firstOrFail();
        $this->assertTrue($d->is_test);
        $this->assertTrue($d->payload['test']);
        $this->assertSame('TEST-OK', $d->external_reference);
    }

    public function test_connectors_are_private_to_their_owner(): void
    {
        $owner = $this->customer();
        $integration = $owner->bot->integrations()->create(['name' => 'CRM', 'kind' => 'webhook', 'url' => 'https://crm.test/hook', 'events' => ['booking.created']]);
        $other = $this->customer();

        $this->actingAs($other)->post("/integrations/{$integration->id}/test")->assertNotFound();
        $this->actingAs($other)->delete("/integrations/{$integration->id}")->assertNotFound();
        $this->actingAs($other)->get('/integrations')->assertOk()->assertDontSee($integration->secret);
        $this->assertNotNull($integration->fresh());
    }

    public function test_secret_is_encrypted_at_rest(): void
    {
        $integration = $this->customer()->bot->integrations()->create(['name' => 'CRM', 'kind' => 'webhook', 'url' => 'https://crm.test/hook', 'events' => ['booking.created']]);
        $raw = \DB::table('integrations')->value('secret');
        $this->assertStringStartsWith('whsec_', $integration->secret);
        $this->assertStringNotContainsString($integration->secret, $raw);
    }

    public function test_capture_fields_are_normalised_and_reach_the_prompt(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->patch('/bot-studio/capture', ['fields' => [
            ['label' => 'Delivery Address', 'type' => 'text', 'required' => true],
            ['label' => 'Phone', 'type' => 'text', 'required' => true], // clashes with a standard field → dropped
            ['label' => 'Items', 'type' => 'list', 'required' => true],
        ]])->assertSessionHasNoErrors();

        $fields = $user->bot->fresh()->capture_fields;
        $this->assertSame(['delivery_address', 'items'], array_column($fields, 'key'));
        $this->assertStringContainsString('delivery address, items', $user->bot->fresh()->systemPrompt());

        $schema = app(\App\Services\VapiService::class)->assistantPayload($user->bot->fresh());
        $this->assertSame('array', $schema['analysisPlan']['structuredDataSchema']['properties']['booking']['properties']['details']['properties']->items['type']);
    }
}
