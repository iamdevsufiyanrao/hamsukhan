<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/** "Test connection" buttons on the super admin's API-keys screen. Read-only calls. */
class ProviderCheck
{
    /** @return array{ok: bool, message: string} */
    public function vapi(): array
    {
        $key = config('hamsukhan.vapi.private_key');
        if (! $key) {
            return ['ok' => false, 'message' => 'No Vapi private key set, running in simulated mode.'];
        }
        try {
            $r = Http::withToken($key)->timeout(10)->get(rtrim(config('hamsukhan.vapi.base_url'), '/').'/assistant', ['limit' => 1]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach Vapi: '.mb_strimwidth($e->getMessage(), 0, 120, '…')];
        }

        return $r->successful()
            ? ['ok' => true, 'message' => 'Vapi key works.']
            : ['ok' => false, 'message' => "Vapi answered HTTP {$r->status()}".($r->status() === 401 ? ', the private key is wrong.' : '.')];
    }

    /** Sends a real test email to the admin. @return array{ok: bool, message: string} */
    public function mail(string $to): array
    {
        if (config('mail.default') === 'log') {
            return ['ok' => false, 'message' => 'Mail is set to "log", so nothing is really sent. Choose SMTP and fill in the mailbox details.'];
        }
        try {
            \Illuminate\Support\Facades\Mail::raw('Outgoing email from '.config('hamsukhan.site.name').' works.', fn ($m) => $m->to($to)->subject('Test email'));
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Email failed: '.mb_strimwidth($e->getMessage(), 0, 200, '…')];
        }

        return ['ok' => true, 'message' => "Test email sent to {$to}. Check the inbox (and spam)."];
    }

    /** Sends a WhatsApp test message. @return array{ok: bool, message: string} */
    public function whatsapp(?string $to): array
    {
        $wa = app(WhatsAppService::class);
        if (! $wa->enabled()) {
            return ['ok' => false, 'message' => 'Add the WhatsApp access token and phone number ID first.'];
        }
        if (! $to) {
            return ['ok' => false, 'message' => 'Enter a number to send the test to.'];
        }
        try {
            $wa->send($to, 'Test message from '.config('hamsukhan.site.name').': WhatsApp alerts work.');
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => 'WhatsApp test sent. If it does not arrive, the template is missing or the number has not messaged you in 24 hours.'];
    }

    /** @return array{ok: bool, message: string} */
    public function elevenLabs(): array
    {
        $key = config('hamsukhan.elevenlabs.api_key');
        if (! $key) {
            return ['ok' => false, 'message' => 'No ElevenLabs key set, voice cloning is simulated.'];
        }
        try {
            $r = Http::withHeaders(['xi-api-key' => $key])->timeout(10)->get(rtrim(config('hamsukhan.elevenlabs.base_url'), '/').'/v1/user');
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach ElevenLabs: '.mb_strimwidth($e->getMessage(), 0, 120, '…')];
        }
        if (! $r->successful()) {
            return ['ok' => false, 'message' => "ElevenLabs answered HTTP {$r->status()}".($r->status() === 401 ? ', the key is wrong.' : '.')];
        }
        $tier = $r->json('subscription.tier');
        $canClone = $r->json('subscription.can_use_instant_voice_cloning');

        return ['ok' => true, 'message' => 'ElevenLabs key works'.($tier ? " · plan: {$tier}" : '').($canClone === false ? ' · ⚠ this plan cannot clone voices' : '')];
    }
}
