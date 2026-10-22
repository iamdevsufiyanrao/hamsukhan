<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Services\AvailabilityService;
use App\Services\CallIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Receives Vapi server messages:
 * - "end-of-call-report" stores the call, transcript, booking and charge;
 * - "tool-calls" answers the live booking tools (check_availability, book_appointment) during the call.
 */
class VapiWebhookController extends Controller
{
    public function __invoke(Request $request, CallIngestService $ingest, AvailabilityService $availability): JsonResponse
    {
        // Production must have a secret; locally an empty secret is allowed for testing.
        $secret = config('hamsukhan.vapi.webhook_secret');
        abort_if(! $secret && app()->isProduction(), 401);
        if ($secret && ! hash_equals($secret, (string) $request->header('x-vapi-secret'))) {
            abort(401);
        }

        $message = (array) $request->input('message', []);

        return match ($message['type'] ?? null) {
            'end-of-call-report' => response()->json(['ok' => true, 'callId' => $ingest->ingestEndOfCallReport($message)?->id]),
            'tool-calls' => response()->json(['results' => $this->toolResults($message, $availability)]),
            default => response()->json(['ok' => true]),
        };
    }

    /** @return list<array{toolCallId: string, result: string}> */
    private function toolResults(array $message, AvailabilityService $availability): array
    {
        $botId = Arr::get($message, 'call.metadata.botId') ?? Arr::get($message, 'assistant.metadata.botId');
        $bot = $botId ? Bot::with('user.googleCalendar')->find($botId) : null;
        $callId = Arr::get($message, 'call.id');
        $calls = Arr::get($message, 'toolCallList') ?? Arr::get($message, 'toolCalls') ?? [];

        return collect($calls)->map(function ($tc) use ($bot, $callId, $availability) {
            $name = Arr::get($tc, 'function.name') ?? Arr::get($tc, 'name');
            $args = Arr::get($tc, 'function.arguments') ?? Arr::get($tc, 'arguments') ?? [];
            $args = is_string($args) ? (json_decode($args, true) ?: []) : (array) $args;

            try {
                $result = match (true) {
                    ! $bot || ! $bot->live_booking => 'Live booking is not available. Take the details and tell the caller the team will confirm.',
                    $name === 'check_availability' => $availability->describe($bot, (string) ($args['date'] ?? '')),
                    $name === 'book_appointment' => $availability->book($bot, $args, $callId)['message'],
                    default => 'Unknown tool.',
                };
            } catch (\Throwable $e) {
                report($e);
                $result = 'The booking system is not answering right now. Take the details and tell the caller the team will confirm shortly.';
            }
            Log::info('[vapi:tool]', ['bot' => $bot?->id, 'tool' => $name, 'call' => $callId]);

            return ['toolCallId' => (string) ($tc['id'] ?? ''), 'result' => $result];
        })->values()->all();
    }
}
