<?php

namespace App\Services;

use App\Support\Phone;
use App\Support\ProviderError;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Owner alerts over the WhatsApp Cloud API. Without a token it is switched off (alerts are skipped, never faked). */
class WhatsAppService
{
    public function enabled(): bool
    {
        return filled(config('hamsukhan.whatsapp.token')) && filled(config('hamsukhan.whatsapp.phone_number_id'));
    }

    /** @throws RuntimeException with a readable reason */
    public function send(string $to, string $text): void
    {
        $number = Phone::normalize($to);
        if (! $number) {
            throw new RuntimeException("\"{$to}\" is not a valid WhatsApp number.");
        }
        $text = mb_substr($text, 0, 1000);
        $template = config('hamsukhan.whatsapp.template');

        $payload = ['messaging_product' => 'whatsapp', 'to' => ltrim($number, '+')] + ($template
            ? ['type' => 'template', 'template' => [
                'name' => $template,
                'language' => ['code' => config('hamsukhan.whatsapp.template_lang', 'en')],
                // Template variables can't contain newlines or 4+ spaces.
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => preg_replace('/\s+/', ' ', $text)]]]],
            ]]
            : ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $text]]);

        try {
            Http::withToken(config('hamsukhan.whatsapp.token'))->acceptJson()->timeout(15)
                ->post(rtrim(config('hamsukhan.whatsapp.base_url'), '/').'/'.config('hamsukhan.whatsapp.phone_number_id').'/messages', $payload)
                ->throw();
        } catch (\Throwable $e) {
            throw new RuntimeException(ProviderError::message($e, 'WhatsApp'), 0, $e);
        }
    }
}
