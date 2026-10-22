<?php

namespace App\Services;

use App\Models\Voice;
use App\Support\ProviderError;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Voice cloning. Without ELEVENLABS_API_KEY it runs in simulated mode and returns local ids.
 * Endpoints: POST /v1/voices/add (instant clone), GET /v1/voices, DELETE /v1/voices/{id}.
 */
class ElevenLabsService
{
    public function enabled(): bool
    {
        return filled(config('hamsukhan.elevenlabs.api_key'));
    }

    /** True for ids made while ElevenLabs was not connected; they don't exist on ElevenLabs. */
    public static function isSimulatedId(?string $id): bool
    {
        return ! $id || str_starts_with($id, 'sim_');
    }

    /**
     * @return string the provider voice id
     *
     * @throws RuntimeException with a readable message when ElevenLabs refuses the sample
     */
    public function cloneVoice(string $name, string $absoluteSamplePath): string
    {
        if (! $this->enabled()) {
            Log::info('[elevenlabs:simulated] clone voice', ['name' => $name]);

            return 'sim_'.Str::lower(Str::random(20));
        }

        try {
            $response = $this->http()->timeout(120)
                ->attach('files', fopen($absoluteSamplePath, 'r'), basename($absoluteSamplePath))
                ->post('/v1/voices/add', ['name' => $name, 'remove_background_noise' => 'true', 'description' => 'Hamsukhan receptionist voice'])
                ->throw();
        } catch (\Throwable $e) {
            throw new RuntimeException(ProviderError::message($e, 'ElevenLabs'), 0, $e);
        }

        $id = (string) $response->json('voice_id');
        if ($id === '') {
            throw new RuntimeException('ElevenLabs did not return a voice id.');
        }

        return $id;
    }

    /**
     * A voice recorded while ElevenLabs was not connected only has a local sim_ id: clone it now from its stored sample.
     *
     * @throws RuntimeException
     */
    public function ensureCloned(Voice $voice): Voice
    {
        if (! $this->enabled() || ! self::isSimulatedId($voice->provider_voice_id)) {
            return $voice;
        }
        if (! $voice->sample_path || ! Storage::exists($voice->sample_path)) {
            throw new RuntimeException("The voice \"{$voice->name}\" was made in demo mode and its recording is gone. Record it again.");
        }

        $voice->update(['provider_voice_id' => $this->cloneVoice($voice->name, Storage::path($voice->sample_path)), 'status' => 'ready']);

        return $voice;
    }

    /**
     * Voices in the ElevenLabs account (cloned + premade), for the super admin's import screen.
     *
     * @return array<int, array{id: string, name: string, category: string, previewUrl: ?string, labels: array}>
     */
    public function listVoices(): array
    {
        if (! $this->enabled()) {
            return [];
        }
        try {
            $voices = $this->http()->timeout(20)->get('/v1/voices')->throw()->json('voices', []);
        } catch (\Throwable $e) {
            throw new RuntimeException(ProviderError::message($e, 'ElevenLabs'), 0, $e);
        }

        return collect($voices)->map(fn ($v) => [
            'id' => (string) ($v['voice_id'] ?? ''),
            'name' => (string) ($v['name'] ?? ''),
            'category' => (string) ($v['category'] ?? 'unknown'),
            'previewUrl' => $v['preview_url'] ?? null,
            'labels' => (array) ($v['labels'] ?? []),
        ])->filter(fn ($v) => $v['id'] !== '')->sortBy(fn ($v) => [$v['category'] === 'premade' ? 1 : 0, $v['name']])->values()->all();
    }

    public function deleteVoice(?string $providerVoiceId): void
    {
        if (self::isSimulatedId($providerVoiceId) || ! $this->enabled()) {
            return;
        }

        $this->http()->delete("/v1/voices/{$providerVoiceId}");
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders(['xi-api-key' => config('hamsukhan.elevenlabs.api_key')])
            ->baseUrl(rtrim(config('hamsukhan.elevenlabs.base_url'), '/'))
            ->acceptJson();
    }
}
