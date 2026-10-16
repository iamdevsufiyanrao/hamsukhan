<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event as EventFacade;
use Hamsukhan\Connector\Contracts\EventHandler;
use Hamsukhan\Connector\Event;
use Hamsukhan\Connector\Laravel\ConnectorServiceProvider;
use Hamsukhan\Connector\Laravel\EventReceived;
use Hamsukhan\Connector\Signature;
use Tests\TestCase;

/** Proves the package a customer installs works inside a Laravel app, using the same signing as Hamsukhan. */
class ConnectorPackageTest extends TestCase
{
    public static array $handled = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$handled = [];
        config(['hamsukhan-connector.secret' => 'whsec_test', 'hamsukhan-connector.handler' => FakeOrderHandler::class]);
        $this->app->register(ConnectorServiceProvider::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function payload(string $id = 'd-1'): string
    {
        return json_encode([
            'event' => 'order.created', 'id' => $id, 'test' => false,
            'bot' => ['id' => 1, 'business' => 'Karachi Grill'],
            'booking' => ['id' => 5, 'type' => 'order', 'customer_name' => 'Ali', 'phone' => '0300', 'request' => 'Platter', 'preferred_time' => 'ASAP', 'details' => ['items' => 'Platter, naan']],
            'call' => ['id' => 9],
        ]);
    }

    private function send(string $body, ?string $signature)
    {
        return $this->call('POST', '/hamsukhan/webhook', [], [], [], array_filter(['CONTENT_TYPE' => 'application/json', 'HTTP_HAMSUKHAN_SIGNATURE' => $signature]), $body);
    }

    public function test_signature_round_trip_and_tampering(): void
    {
        $body = $this->payload();
        $sig = Signature::sign($body, 'whsec_test');

        $this->assertTrue(Signature::verify($body, $sig, 'whsec_test'));
        $this->assertFalse(Signature::verify($body.' ', $sig, 'whsec_test'));
        $this->assertFalse(Signature::verify($body, $sig, 'whsec_other'));
        $this->assertFalse(Signature::verify($body, Signature::sign($body, 'whsec_test', time() - 600), 'whsec_test')); // replayed
        $this->assertFalse(Signature::verify($body, 'garbage', 'whsec_test'));
        $this->assertFalse(Signature::verify($body, null, 'whsec_test'));
    }

    public function test_laravel_route_calls_handler_and_returns_reference(): void
    {
        EventFacade::fake([EventReceived::class]);
        $body = $this->payload();

        $this->send($body, Signature::sign($body, 'whsec_test'))->assertOk()->assertJson(['ok' => true, 'reference' => 'ORD-5']);

        $this->assertSame(['Ali', 'Platter, naan'], self::$handled[0]);
        EventFacade::assertDispatched(EventReceived::class, fn ($e) => $e->event->isOrder());
    }

    public function test_bad_signature_is_rejected_and_handler_not_called(): void
    {
        $this->send($this->payload(), 't=1,v1=abc')->assertStatus(401);
        $this->assertSame([], self::$handled);
    }

    public function test_retry_with_same_delivery_id_is_not_saved_twice(): void
    {
        $body = $this->payload('same-id');
        $this->send($body, Signature::sign($body, 'whsec_test'))->assertOk();
        $this->send($body, Signature::sign($body, 'whsec_test'))->assertOk()->assertJson(['duplicate' => true, 'reference' => 'ORD-5']);
        $this->assertCount(1, self::$handled);
    }

    public function test_plain_php_receiver_capture(): void
    {
        $body = $this->payload();
        $event = \Hamsukhan\Connector\Receiver::capture('whsec_test', $body, Signature::sign($body, 'whsec_test'));
        $this->assertSame('Platter, naan', $event->field('items'));

        $this->expectException(\Hamsukhan\Connector\InvalidSignature::class);
        \Hamsukhan\Connector\Receiver::capture('whsec_test', $body, 'nope');
    }
}

class FakeOrderHandler implements EventHandler
{
    public function handle(Event $event): ?string
    {
        ConnectorPackageTest::$handled[] = [$event->customerName(), $event->field('items')];

        return 'ORD-'.$event->booking()['id'];
    }
}
