<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/** Turns a Vapi / ElevenLabs failure into one short sentence a person can act on (never includes keys). */
class ProviderError
{
    public static function message(Throwable $e, string $provider): string
    {
        if ($e instanceof ConnectionException) {
            return "Could not reach {$provider}. Check the server's internet connection and try again.";
        }
        if (! $e instanceof RequestException) {
            return mb_strimwidth($e->getMessage(), 0, 240, '…');
        }

        $status = $e->response->status();
        $json = $e->response->json();
        // Vapi: {"message": ["voice.voiceId ..."] | "...", "error": "Bad Request"}; ElevenLabs: {"detail": {"status": "...", "message": "..."}} or {"detail": "..."}
        $detail = data_get($json, 'detail.message') ?? data_get($json, 'detail') ?? data_get($json, 'message') ?? data_get($json, 'error');
        if (is_array($detail)) {
            $detail = implode('; ', array_map(fn ($d) => is_scalar($d) ? (string) $d : json_encode($d), $detail));
        }
        $detail = trim((string) $detail) ?: mb_strimwidth($e->response->body(), 0, 200, '…');

        $hint = match (true) {
            $status === 401 => ' (the API key is wrong or was revoked)',
            $status === 402 => ' (your provider account is out of credits)',
            $status === 403 || str_contains($detail, 'can_not_use_instant_voice_cloning') => ' (your plan does not allow this, upgrade the provider plan)',
            default => '',
        };

        return mb_strimwidth("{$provider} said HTTP {$status}: {$detail}{$hint}", 0, 300, '…');
    }
}
