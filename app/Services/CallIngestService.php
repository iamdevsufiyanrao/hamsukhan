<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Bot;
use App\Models\Call;
use App\Services\Integrations\IntegrationDispatcher;
use App\Support\CaptureFields;
use App\Support\Phone;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns Vapi's "end-of-call-report" into a Call + transcript + optional Booking, then charges the wallet.
 * Idempotent: the same Vapi call id is only ever stored and charged once.
 */
class CallIngestService
{
    public function __construct(private WalletService $wallet, private IntegrationDispatcher $integrations) {}

    public function ingestEndOfCallReport(array $message): ?Call
    {
        $vapiCallId = Arr::get($message, 'call.id');
        $bot = $this->resolveBot($message);
        if (! $bot || ! $vapiCallId) {
            return null;
        }

        if ($existing = Call::where('vapi_call_id', $vapiCallId)->first()) {
            return $existing;
        }

        $structured = (array) Arr::get($message, 'analysis.structuredData', []);
        $started = Carbon::parse(Arr::get($message, 'startedAt', now()));
        $ended = Carbon::parse(Arr::get($message, 'endedAt', $started));
        $duration = (int) round(Arr::get($message, 'durationSeconds', $ended->diffInSeconds($started, true)));
        $turns = $this->transcript($message);
        $status = $this->status(Arr::get($message, 'endedReason'), $turns, $duration);

        $call = DB::transaction(function () use ($bot, $vapiCallId, $structured, $started, $ended, $duration, $turns, $status, $message) {
            $call = $bot->calls()->create([
                'vapi_call_id' => $vapiCallId,
                'title' => Arr::get($structured, 'title') ?: ($status === 'completed' ? 'Call with a website visitor' : 'Caller hung up early'),
                'outcome_tag' => in_array(Arr::get($structured, 'outcome'), config('hamsukhan.outcome_tags'), true) ? $structured['outcome'] : 'No outcome',
                'status' => $status,
                'ended_reason' => Arr::get($message, 'endedReason'),
                'is_test' => (bool) (Arr::get($message, 'call.metadata.isTest') || Arr::get($message, 'call.metadata.isDemo')),
                'started_at' => $started,
                'ended_at' => $ended,
                'duration_seconds' => $duration,
                // The landing-page demo and failed connections are never billed.
                'cost' => Arr::get($message, 'call.metadata.isDemo') || $status === 'failed' ? 0 : $this->wallet->costFor($duration),
                'recording_url' => Arr::get($message, 'recordingUrl') ?? Arr::get($message, 'artifact.recordingUrl'),
                'summary' => Arr::get($message, 'analysis.summary') ?? Arr::get($message, 'summary'),
            ]);

            foreach ($turns as $i => [$role, $text]) {
                $call->messages()->create(['role' => $role, 'text' => $text, 'position' => $i]);
            }

            $booking = (array) Arr::get($structured, 'booking', []);
            if (filled($booking['customerName'] ?? null) && filled($booking['request'] ?? null)) {
                $bot->bookings()->create([
                    'call_id' => $call->id,
                    'type' => $this->bookingType($booking['type'] ?? null, $structured['outcome'] ?? null),
                    'customer_name' => $booking['customerName'],
                    'phone' => Phone::clean($booking['phone'] ?? null),
                    'request' => $booking['request'],
                    'preferred_time' => $booking['preferredTime'] ?? null,
                    'details' => CaptureFields::extract($bot->capture_fields ?? [], (array) ($booking['details'] ?? [])) ?: null,
                ]);
            }

            return $call;
        });

        $this->wallet->chargeCall($call->setRelation('bot', $bot));

        // Push the booking/order into the customer's own software (their connectors).
        if ($created = $call->booking()->first()) {
            $this->integrations->dispatchForBooking($created->setRelation('bot', $bot));
        }

        return $call;
    }

    private function bookingType(?string $type, ?string $outcome): string
    {
        if (in_array($type, Booking::TYPES, true)) {
            return $type;
        }

        return $outcome === 'Order' ? 'order' : ($outcome === 'Support' ? 'callback' : 'booking');
    }

    private function resolveBot(array $message): ?Bot
    {
        $botId = Arr::get($message, 'call.metadata.botId') ?? Arr::get($message, 'assistant.metadata.botId');
        if ($botId) {
            return Bot::find($botId);
        }
        $assistantId = Arr::get($message, 'call.assistantId') ?? Arr::get($message, 'assistant.id');

        return $assistantId ? Bot::where('vapi_assistant_id', $assistantId)->first() : null;
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function transcript(array $message): array
    {
        $messages = Arr::get($message, 'artifact.messages') ?? Arr::get($message, 'messages') ?? [];

        return collect($messages)
            ->filter(fn ($m) => in_array($m['role'] ?? null, ['assistant', 'bot', 'user'], true) && filled($m['message'] ?? $m['content'] ?? null))
            ->map(fn ($m) => [$m['role'] === 'user' ? 'user' : 'ai', trim($m['message'] ?? $m['content'])])
            ->values()
            ->all();
    }

    private function status(?string $endedReason, array $turns, int $duration): string
    {
        if ($duration === 0 || ($endedReason && str_contains($endedReason, 'error'))) {
            return 'failed';
        }

        return collect($turns)->contains(fn ($t) => $t[0] === 'user') ? 'completed' : 'dropped';
    }
}
