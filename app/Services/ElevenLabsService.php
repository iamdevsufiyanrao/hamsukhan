<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Voice cloning. Without ELEVENLABS_API_KEY it runs in simulated mode and returns local ids.
 * Endpoints follow ElevenLabs' public API (POST /v1/voices/add, DELETE /v1/voices/{id}) -
 * check them against the current docs when the key is added.
 */
class ElevenLabsService
{
    public function enabled(): bool
    {
        return filled(config('hamsukhan.elevenlabs.api_key'));
    }

    /** @return string the provider voice id */
    public function cloneVoice(string $name, string $absoluteSamplePath): string
    {
        if (! $this->enabled()) {
            Log::info('[elevenlabs:simulated] clone voice', ['name' => $name]);

            return 'sim_'.Str::lower(Str::random(20));
        }

        $response = Http::withHeaders(['xi-api-key' => config('hamsukhan.elevenlabs.api_key')])
            ->attach('files', fopen($absoluteSamplePath, 'r'), basename($absoluteSamplePath))
            ->post(config('hamsukhan.elevenlabs.base_url').'/v1/voices/add', ['name' => $name])
            ->throw();

        return (string) $response->json('voice_id');
    }

    public function deleteVoice(?string $providerVoiceId): void
    {
        if (! $providerVoiceId || ! $this->enabled() || str_starts_with($providerVoiceId, 'sim_')) {
            return;
        }

        Http::withHeaders(['xi-api-key' => config('hamsukhan.elevenlabs.api_key')])
            ->delete(config('hamsukhan.elevenlabs.base_url')."/v1/voices/{$providerVoiceId}");
    }
}
