<?php

namespace App\Services;

use App\Models\Bot;
use App\Support\CaptureFields;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Keeps one Vapi assistant per bot in sync with our database (our DB is the source of truth).
 * Without VAPI_PRIVATE_KEY it runs in simulated mode. The payload follows Vapi's assistant API;
 * check field names against the current Vapi docs when the key is added.
 */
class VapiService
{
    public function enabled(): bool
    {
        return filled(config('hamsukhan.vapi.private_key'));
    }

    public function publicKey(): ?string
    {
        return config('hamsukhan.vapi.public_key');
    }

    public function syncAssistant(Bot $bot): void
    {
        $payload = $this->assistantPayload($bot);

        if (! $this->enabled()) {
            Log::info('[vapi:simulated] sync assistant', ['bot' => $bot->id]);
            $bot->forceFill(['vapi_assistant_id' => $bot->vapi_assistant_id ?? 'sim_asst_'.$bot->id, 'synced_at' => now()])->save();

            return;
        }

        $http = Http::withToken(config('hamsukhan.vapi.private_key'))->baseUrl(config('hamsukhan.vapi.base_url'));
        $response = $bot->vapi_assistant_id && ! str_starts_with($bot->vapi_assistant_id, 'sim_')
            ? $http->patch("/assistant/{$bot->vapi_assistant_id}", $payload)->throw()
            : $http->post('/assistant', $payload)->throw();

        $bot->forceFill(['vapi_assistant_id' => $response->json('id'), 'synced_at' => now()])->save();
    }

    public function assistantPayload(Bot $bot): array
    {
        $bot->loadMissing('voice');
        $lang = config('hamsukhan.languages.'.$bot->language, 'en');

        return [
            'name' => 'Hamsukhan bot #'.$bot->id,
            'firstMessage' => $bot->greeting,
            'endCallMessage' => $bot->end_message,
            'maxDurationSeconds' => $bot->max_call_minutes * 60,
            'model' => [
                'provider' => 'openai',
                'model' => config('hamsukhan.vapi.model'),
                'messages' => [['role' => 'system', 'content' => $bot->systemPrompt()]],
            ],
            'voice' => [
                'provider' => '11labs',
                'voiceId' => $bot->voice?->provider_voice_id ?? $bot->preset_voice,
                'model' => config('hamsukhan.elevenlabs.model'),
            ],
            'transcriber' => ['provider' => 'deepgram', 'language' => $lang],
            'server' => [
                'url' => route('webhooks.vapi'),
                'secret' => config('hamsukhan.vapi.webhook_secret'),
            ],
            'metadata' => ['botId' => $bot->id],
            'analysisPlan' => [
                'summaryPrompt' => 'Summarise the call in one sentence for the business owner.',
                'structuredDataSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Short title of what the caller wanted, max 8 words'],
                        'outcome' => ['type' => 'string', 'enum' => config('hamsukhan.outcome_tags')],
                        'booking' => [
                            'type' => 'object',
                            'description' => 'Only if the caller booked, ordered or asked for a callback',
                            'properties' => [
                                'type' => ['type' => 'string', 'enum' => \App\Models\Booking::TYPES],
                                'customerName' => ['type' => 'string'],
                                'phone' => ['type' => 'string'],
                                'request' => ['type' => 'string', 'description' => 'One line: what they booked or ordered'],
                                'preferredTime' => ['type' => 'string'],
                                'details' => ['type' => 'object', 'properties' => (object) CaptureFields::schema($bot->capture_fields ?? [])],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
