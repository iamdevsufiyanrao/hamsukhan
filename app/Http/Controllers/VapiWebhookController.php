<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\LiveCall;
use App\Services\AvailabilityService;
use App\Services\CallIngestService;
use App\Services\VapiService;
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
    public function __invoke(Request $request, CallIngestService $ingest, AvailabilityService $availability, VapiService $vapi): JsonResponse
    {
        // Production must have a secret; locally an empty secret is allowed for testing.
        $secret = config('hamsukhan.vapi.webhook_secret');
        abort_if(! $secret && app()->isProduction(), 401);
        if ($secret && ! hash_equals($secret, (string) $request->header('x-vapi-secret'))) {
            abort(401);
        }

        $message = (array) $request->input('message', []);

        return match ($message['type'] ?? null) {
            'end-of-call-report' => response()->json(['ok' => true, 'callId' => $this->ended($message, $ingest)]),
            'tool-calls' => response()->json(['results' => $this->toolResults($message, $availability)]),
            'status-update' => response()->json(['ok' => $this->status($message, $vapi)]),
            default => response()->json(['ok' => true]),
        };
    }

    private function ended(array $message, CallIngestService $ingest): ?int
    {
        LiveCall::where('vapi_call_id', Arr::get($message, 'call.id'))->delete();

        return $ingest->ingestEndOfCallReport($message)?->id;
    }

    /** Keeps the "Live now" list: a call appears when it is in progress and disappears when it ends. */
    private function status(array $message, VapiService $vapi): bool
    {
        $callId = Arr::get($message, 'call.id');
        $botId = Arr::get($message, 'call.metadata.botId') ?? Arr::get($message, 'assistant.metadata.botId');
        $bot = $botId ? Bot::find($botId) : (($a = Arr::get($message, 'call.assistantId')) ? Bot::where('vapi_assistant_id', $a)->first() : null);
        if (! $callId || ! $bot) {
            return false;
        }
        $status = Arr::get($message, 'status');
        if ($status === 'ended') {
            LiveCall::where('vapi_call_id', $callId)->delete();

            return true;
        }
        if ($status !== 'in-progress') {
            return true;
        }

        $monitor = (array) Arr::get($message, 'call.monitor', []);
        if (empty($monitor['listenUrl']) && $vapi->enabled()) {
            $monitor = $vapi->monitorUrls($callId);
        }
        $bot->liveCalls()->updateOrCreate(['vapi_call_id' => $callId], [
            'direction' => match (Arr::get($message, 'call.type')) { 'inboundPhoneCall' => 'inbound', 'outboundPhoneCall' => 'outbound', default => 'web' },
            'customer_number' => \App\Support\Phone::clean(Arr::get($message, 'call.customer.number')),
            'listen_url' => $monitor['listenUrl'] ?? null,
            'control_url' => $monitor['controlUrl'] ?? null,
            'started_at' => now(),
        ]);

        return true;
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
