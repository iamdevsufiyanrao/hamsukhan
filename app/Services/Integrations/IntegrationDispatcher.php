<?php

namespace App\Services\Integrations;

use App\Models\Booking;
use App\Models\Integration;
use App\Models\IntegrationDelivery;
use Illuminate\Support\Facades\Http;
use Hamsukhan\Connector\Signature;
use Throwable;

/**
 * Sends bookings/orders to the customer's own software.
 * First attempt runs right after the response; failures retry with backoff via `php artisan hamsukhan:deliver-integrations`
 * (scheduled every minute). 2xx = delivered; 4xx (except 408/429) = failed for good; anything else = retry.
 */
class IntegrationDispatcher
{
    /** Seconds to wait before attempt 2, 3, 4, 5. */
    public const BACKOFF = [60, 300, 1800, 7200];

    public function __construct(private PayloadBuilder $payloads) {}

    /** @return IntegrationDelivery[] */
    public function dispatchForBooking(Booking $booking): array
    {
        if (! config('hamsukhan.integrations.enabled')) {
            return []; // switched off platform-wide by the super admin
        }
        $event = PayloadBuilder::eventFor($booking);
        $deliveries = [];
        foreach ($booking->bot->integrations()->where('is_active', true)->get() as $integration) {
            if ($integration->listensTo($event)) {
                $deliveries[] = $this->queue($integration, $booking, false);
            }
        }

        return $deliveries;
    }

    public function sendTest(Integration $integration, Booking $sample): IntegrationDelivery
    {
        $delivery = $this->queue($integration, $sample, true, later: false);

        return $this->attempt($delivery);
    }

    public function retryNow(IntegrationDelivery $delivery): IntegrationDelivery
    {
        $delivery->update(['status' => 'pending', 'next_attempt_at' => now()]);

        return $this->attempt($delivery);
    }

    public function attempt(IntegrationDelivery $delivery): IntegrationDelivery
    {
        $integration = $delivery->integration;
        $delivery->increment('attempts');

        if (! $integration->is_active && ! $delivery->is_test) {
            return $this->finish($delivery, 'failed', null, 'Connector is turned off.');
        }
        if ($error = UrlGuard::check($integration->url)) {
            return $this->finish($delivery, 'failed', null, $error);
        }

        [$body, $contentType] = $integration->kind === 'form'
            ? [http_build_query($this->payloads->formFields($integration, $delivery->payload)), 'application/x-www-form-urlencoded']
            : [json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json'];

        try {
            $response = Http::withHeaders([
                Signature::HEADER => Signature::sign($body, $integration->secret),
                'Hamsukhan-Event' => $delivery->event,
                'Hamsukhan-Delivery' => $delivery->uuid,
                'User-Agent' => 'Hamsukhan-Connector/1.0',
                'Accept' => 'application/json',
            ])->withBody($body, $contentType)->timeout(10)->connectTimeout(5)->withoutRedirecting()->post($integration->url);
        } catch (Throwable $e) {
            return $this->retryOrFail($delivery, null, 'Could not connect: '.mb_strimwidth($e->getMessage(), 0, 200, '…'));
        }

        $snippet = mb_strimwidth((string) $response->body(), 0, 1000, '…');
        if ($response->successful()) {
            $ref = $response->json('reference') ?? $response->json('id');

            return $this->finish($delivery, 'delivered', $response->status(), $snippet, is_scalar($ref) ? (string) $ref : null);
        }
        if ($response->clientError() && ! in_array($response->status(), [408, 429], true)) {
            return $this->finish($delivery, 'failed', $response->status(), $snippet);
        }

        return $this->retryOrFail($delivery, $response->status(), $snippet);
    }

    /** Attempts every delivery that is due. Returns how many were attempted. */
    public function deliverDue(int $limit = 50): int
    {
        $due = IntegrationDelivery::with('integration')->where('status', 'pending')->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($limit)->get();
        foreach ($due as $delivery) {
            $this->attempt($delivery);
        }

        return $due->count();
    }

    private function queue(Integration $integration, Booking $booking, bool $isTest, bool $later = true): IntegrationDelivery
    {
        $delivery = $integration->deliveries()->make(['event' => PayloadBuilder::eventFor($booking), 'booking_id' => $isTest ? null : $booking->id, 'is_test' => $isTest, 'payload' => [], 'next_attempt_at' => now()]);
        $delivery->uuid = (string) \Illuminate\Support\Str::uuid();
        $delivery->payload = $this->payloads->event($booking, $delivery->uuid, $isTest);
        $delivery->save();

        if ($later) {
            // Don't make Vapi's webhook wait on the customer's server.
            app()->terminating(fn () => app(self::class)->attempt($delivery->fresh('integration')));
        }

        return $delivery;
    }

    private function retryOrFail(IntegrationDelivery $delivery, ?int $code, string $body): IntegrationDelivery
    {
        $wait = self::BACKOFF[$delivery->attempts - 1] ?? null;
        if ($wait === null) {
            return $this->finish($delivery, 'failed', $code, $body);
        }
        $delivery->update(['status' => 'pending', 'response_code' => $code, 'response_body' => $body, 'next_attempt_at' => now()->addSeconds($wait)]);
        $delivery->integration->update(['last_delivery_at' => now(), 'last_status' => 'retrying']);

        return $delivery;
    }

    private function finish(IntegrationDelivery $delivery, string $status, ?int $code, ?string $body, ?string $reference = null): IntegrationDelivery
    {
        $delivery->update([
            'status' => $status,
            'response_code' => $code,
            'response_body' => $body,
            'external_reference' => $reference,
            'next_attempt_at' => null,
            'delivered_at' => $status === 'delivered' ? now() : null,
        ]);
        $delivery->integration->update(['last_delivery_at' => now(), 'last_status' => $status]);

        // A real booking that will never arrive in the owner's software: tell them (once per delivery).
        if ($status === 'failed' && ! $delivery->is_test) {
            app(\App\Services\NotificationService::class)->integrationFailed($delivery);
        }

        return $delivery;
    }
}
