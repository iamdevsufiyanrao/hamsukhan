<?php

namespace App\Services;

use App\Models\Bot;
use App\Support\CaptureFields;
use App\Support\ProviderError;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Keeps one Vapi assistant per bot in sync with our database (our DB is the source of truth).
 * Without VAPI_PRIVATE_KEY it runs in simulated mode.
 */
class VapiService
{
    public function __construct(private ElevenLabsService $elevenLabs) {}

    public function enabled(): bool
    {
        return filled(config('hamsukhan.vapi.private_key'));
    }

    public function publicKey(): ?string
    {
        return config('hamsukhan.vapi.public_key');
    }

    /** The bot has a real assistant on Vapi (not a simulated placeholder). */
    public function isLive(Bot $bot): bool
    {
        return $this->enabled() && $bot->vapi_assistant_id && ! str_starts_with($bot->vapi_assistant_id, 'sim_') && ! $bot->sync_error;
    }

    /**
     * Creates or updates the bot's assistant. On failure the readable reason is saved on the bot
     * (shown in Bot Studio) and a RuntimeException with that reason is thrown.
     */
    public function syncAssistant(Bot $bot): void
    {
        if (! $this->enabled()) {
            Log::info('[vapi:simulated] sync assistant', ['bot' => $bot->id]);
            $bot->forceFill(['vapi_assistant_id' => $bot->vapi_assistant_id ?? 'sim_asst_'.$bot->id, 'synced_at' => now(), 'sync_error' => null])->save();

            return;
        }

        try {
            // A voice recorded before ElevenLabs was connected has only a local id: clone it first.
            if ($bot->voice) {
                $this->elevenLabs->ensureCloned($bot->voice);
            }
            $payload = $this->assistantPayload($bot);
            $existing = $bot->vapi_assistant_id && ! str_starts_with($bot->vapi_assistant_id, 'sim_') ? $bot->vapi_assistant_id : null;

            $send = function (array $payload) use ($existing) {
                $response = $existing ? $this->http()->patch("/assistant/{$existing}", $payload) : null;
                if (! $response || $response->status() === 404) {
                    $response = $this->http()->post('/assistant', $payload); // first sync, or the assistant was deleted on Vapi
                }

                return $response;
            };
            $response = $send($payload);
            // Vapi rejects fields it doesn't know ("property x should not exist"); drop those once and retry, so API drift can't block a bot.
            if ($response->status() === 400 && ($unknown = $this->unknownProperties($response->json('message')))) {
                Log::warning('[vapi] dropped unknown assistant fields', ['fields' => $unknown]);
                foreach ($unknown as $path) {
                    \Illuminate\Support\Arr::forget($payload, $path);
                }
                $response = $send($payload);
            }
            $response->throw();
        } catch (\Throwable $e) {
            $reason = $e instanceof RuntimeException && ! $e instanceof \Illuminate\Http\Client\HttpClientException ? $e->getMessage() : ProviderError::message($e, 'Vapi');
            report($e);
            $bot->forceFill(['sync_error' => $reason])->save();

            throw new RuntimeException($reason, 0, $e);
        }

        $bot->forceFill(['vapi_assistant_id' => $response->json('id'), 'synced_at' => now(), 'sync_error' => null])->save();
    }

    /** @return list<string> dotted paths from messages like "analysisPlan.property summaryPrompt should not exist" */
    private function unknownProperties(mixed $messages): array
    {
        return collect((array) $messages)
            ->map(fn ($m) => is_string($m) && preg_match('/^(?:([\w.\[\]]+)\.)?property (\w+) should not exist/', $m, $x) ? ltrim(($x[1] ?? '').'.'.$x[2], '.') : null)
            ->filter()
            ->map(fn ($p) => preg_replace('/\[(\d+)\]/', '.$1', $p))
            ->values()
            ->all();
    }

    /** Sync without throwing (signup, background saves). Returns the error message, or null when it worked. */
    public function trySync(Bot $bot): ?string
    {
        try {
            $this->syncAssistant($bot);

            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /** ElevenLabs voice id the assistant speaks with. */
    public function voiceIdFor(Bot $bot): string
    {
        $bot->loadMissing('voice');
        if ($bot->voice && ! ElevenLabsService::isSimulatedId($bot->voice->provider_voice_id)) {
            return $bot->voice->provider_voice_id;
        }
        $presets = collect(config('hamsukhan.preset_voices'));
        $preset = $presets->firstWhere('id', $bot->preset_voice) ?? $presets->first();

        return $preset['voice_id'];
    }

    public function assistantPayload(Bot $bot): array
    {
        $lang = config('hamsukhan.languages.'.$bot->language, 'en');
        $transcriber = config("hamsukhan.transcribers.{$lang}") ?? ['provider' => 'deepgram', 'model' => 'nova-2', 'language' => 'en'];

        $payload = [
            'name' => mb_substr('Hamsukhan #'.$bot->id.($bot->business_name ? ' '.$bot->business_name : ''), 0, 40),
            'firstMessage' => $bot->greeting,
            'firstMessageMode' => 'assistant-speaks-first',
            'endCallMessage' => $bot->end_message,
            'maxDurationSeconds' => $bot->max_call_minutes * 60,
            'silenceTimeoutSeconds' => 30,
            'backgroundDenoisingEnabled' => true,
            'model' => array_filter([
                'provider' => 'openai',
                'model' => config('hamsukhan.vapi.model'),
                'temperature' => 0.3,
                'messages' => [['role' => 'system', 'content' => $bot->systemPrompt()]],
                'tools' => $this->liveBookingTools($bot) ?: null,
            ]),
            'voice' => [
                'provider' => '11labs',
                'voiceId' => $this->voiceIdFor($bot),
                'model' => config('hamsukhan.elevenlabs.model'),
            ],
            'transcriber' => $transcriber,
            'server' => array_filter([
                'url' => route('webhooks.vapi'),
                'secret' => config('hamsukhan.vapi.webhook_secret'),
            ]),
            'serverMessages' => ['end-of-call-report'],
            'metadata' => ['botId' => $bot->id],
            'analysisPlan' => [
                'summaryPlan' => ['enabled' => true],
                'structuredDataPlan' => [
                    'enabled' => true,
                    'schema' => $this->structuredDataSchema($bot),
                ],
            ],
        ];

        // Vapi rejects explicit nulls for optional fields.
        return array_filter($payload, fn ($v) => $v !== null && $v !== '');
    }

    /** Function tools for live booking; Vapi calls our webhook with "tool-calls" (see VapiWebhookController). Only with opening hours set. */
    private function liveBookingTools(Bot $bot): array
    {
        if (! $bot->live_booking || ! $bot->business_hours) {
            return [];
        }
        $server = array_filter(['url' => route('webhooks.vapi'), 'secret' => config('hamsukhan.vapi.webhook_secret')]);
        $date = ['type' => 'string', 'description' => 'The day in YYYY-MM-DD format (work it out from today\'s date), or "today" / "tomorrow".'];

        return [
            [
                'type' => 'function',
                'messages' => [['type' => 'request-start', 'content' => 'Ek second, main check karti hoon.']],
                'function' => [
                    'name' => 'check_availability',
                    'description' => 'Get the free appointment times for a day. Always call this before offering or confirming a time.',
                    'parameters' => ['type' => 'object', 'properties' => ['date' => $date], 'required' => ['date']],
                ],
                'server' => $server,
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'book_appointment',
                    'description' => 'Book a free time for the caller. Only after check_availability, and after the caller agreed to the time and gave their name and phone number.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => $date,
                            'time' => ['type' => 'string', 'description' => 'Start time in 24-hour HH:MM, exactly one of the free times'],
                            'customerName' => ['type' => 'string'],
                            'phone' => ['type' => 'string', 'description' => 'Phone or WhatsApp number, e.g. 03001234567'],
                            'service' => ['type' => 'string', 'description' => 'What the appointment is for'],
                            'details' => ['type' => 'object', 'properties' => (object) CaptureFields::schema($bot->capture_fields ?? [])],
                        ],
                        'required' => ['date', 'time', 'customerName', 'phone'],
                    ],
                ],
                'server' => $server,
            ],
        ];
    }

    private function structuredDataSchema(Bot $bot): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Short title of what the caller wanted, max 8 words'],
                'outcome' => ['type' => 'string', 'enum' => config('hamsukhan.outcome_tags')],
                'unansweredQuestions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Questions the caller asked that the assistant could not answer from its facts, or answered with uncertainty. Short, in the words of the caller. Empty if none.',
                ],
                'booking' => [
                    'type' => 'object',
                    'description' => 'Only if the caller booked, ordered or asked for a callback',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => \App\Models\Booking::TYPES],
                        'customerName' => ['type' => 'string'],
                        'phone' => ['type' => 'string', 'description' => 'Pakistani numbers in +92 format, e.g. +923001234567'],
                        'request' => ['type' => 'string', 'description' => 'One line: what they booked or ordered'],
                        'preferredTime' => ['type' => 'string'],
                        'details' => ['type' => 'object', 'properties' => (object) CaptureFields::schema($bot->capture_fields ?? [])],
                    ],
                ],
            ],
        ];
    }

    /**
     * Gives Vapi our ElevenLabs key ("provider credential") so assistants can speak with voices cloned in that account.
     * Without it Vapi can only use ElevenLabs' public premade voices and cloned voices fail on calls.
     *
     * @return array{ok: bool, message: string}
     */
    public function linkElevenLabs(): array
    {
        $key = config('hamsukhan.elevenlabs.api_key');
        if (! $this->enabled() || ! $key) {
            return ['ok' => false, 'message' => 'Add both the Vapi private key and the ElevenLabs key first.'];
        }

        try {
            $existing = collect($this->http()->get('/credential')->throw()->json() ?? [])->firstWhere('provider', '11labs');
            $existing
                ? $this->http()->patch("/credential/{$existing['id']}", ['apiKey' => $key])->throw()
                : $this->http()->post('/credential', ['provider' => '11labs', 'apiKey' => $key])->throw();
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => ProviderError::message($e, 'Vapi').' You can also add it by hand: Vapi dashboard → Integrations → ElevenLabs.'];
        }

        return ['ok' => true, 'message' => 'ElevenLabs is linked inside Vapi, cloned voices will now speak on calls.'];
    }

    private function http(): PendingRequest
    {
        return Http::withToken(config('hamsukhan.vapi.private_key'))
            ->baseUrl(rtrim(config('hamsukhan.vapi.base_url'), '/'))
            ->acceptJson()
            ->timeout(30);
    }
}
