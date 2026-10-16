<?php

namespace Hamsukhan\Connector\Laravel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Hamsukhan\Connector\Contracts\EventHandler;
use Hamsukhan\Connector\Event;
use Hamsukhan\Connector\Signature;
use Throwable;

class WebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('hamsukhan-connector.secret');
        if (! Signature::verify($request->getContent(), $request->header(Signature::HEADER), $secret)) {
            return response()->json(['ok' => false, 'error' => 'invalid signature'], 401);
        }

        try {
            $event = Event::fromJson($request->getContent());
        } catch (Throwable) {
            return response()->json(['ok' => false, 'error' => 'invalid payload'], 400);
        }

        // Retries carry the same id, answer with the first result instead of saving twice.
        $cacheKey = 'hamsukhan-connector:'.$event->id();
        if ($event->id() !== '' && ($previous = Cache::get($cacheKey)) !== null) {
            return response()->json(['ok' => true, 'reference' => $previous ?: null, 'duplicate' => true]);
        }

        event(new EventReceived($event));

        $reference = null;
        if ($class = config('hamsukhan-connector.handler')) {
            /** @var EventHandler $handler */
            $handler = app($class);
            try {
                $reference = $handler->handle($event);
            } catch (Throwable $e) {
                report($e);

                return response()->json(['ok' => false, 'error' => 'handler failed'], 500); // Hamsukhan will retry
            }
        }

        if ($event->id() !== '') {
            Cache::put($cacheKey, (string) $reference, now()->addDays(3));
        }

        return response()->json(['ok' => true, 'reference' => $reference]);
    }
}
