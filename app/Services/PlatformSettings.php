<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Super-admin settings stored in the database. On boot they are copied into config(), so every part of
 * the app that reads config('hamsukhan.*') picks up the admin's values; .env values are only the defaults.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'hamsukhan:settings';

    /**
     * Every editable setting: key => [config path it overrides, type, secret?, group].
     * Types: string, text, email, url, number, bool.
     */
    public const SCHEMA = [
        // Site
        'site_name' => ['hamsukhan.site.name', 'string', false, 'site'],
        'support_email' => ['hamsukhan.site.support_email', 'email', false, 'site'],
        'support_phone' => ['hamsukhan.site.support_phone', 'string', false, 'site'],
        'allow_signups' => ['hamsukhan.site.allow_signups', 'bool', false, 'site'],
        'announcement' => ['hamsukhan.site.announcement', 'text', false, 'site'],
        'terms_url' => ['hamsukhan.site.terms_url', 'url', false, 'site'],
        'privacy_url' => ['hamsukhan.site.privacy_url', 'url', false, 'site'],
        'whatsapp_number' => ['hamsukhan.site.whatsapp', 'string', false, 'site'],
        'demo_bot_key' => ['hamsukhan.demo.bot_key', 'string', false, 'site'],
        // SEO
        'seo_title' => ['hamsukhan.seo.title', 'string', false, 'seo'],
        'seo_description' => ['hamsukhan.seo.description', 'text', false, 'seo'],
        'seo_keywords' => ['hamsukhan.seo.keywords', 'string', false, 'seo'],
        'seo_og_image' => ['hamsukhan.seo.og_image', 'url', false, 'seo'],
        'seo_index' => ['hamsukhan.seo.index', 'bool', false, 'seo'],
        'seo_ga_id' => ['hamsukhan.seo.ga_id', 'string', false, 'seo'],
        // Billing
        'rate_per_minute' => ['hamsukhan.rate_per_minute', 'number', false, 'billing'],
        'min_topup_usd' => ['hamsukhan.min_topup_usd', 'number', false, 'billing'],
        'pkr_per_usd' => ['hamsukhan.pkr_per_usd', 'number', false, 'billing'],
        'low_balance_usd' => ['hamsukhan.low_balance_usd', 'number', false, 'billing'],
        'jazzcash_title' => ['hamsukhan.payment_accounts.jazzcash.title', 'string', false, 'billing'],
        'jazzcash_number' => ['hamsukhan.payment_accounts.jazzcash.number', 'string', false, 'billing'],
        'easypaisa_title' => ['hamsukhan.payment_accounts.easypaisa.title', 'string', false, 'billing'],
        'easypaisa_number' => ['hamsukhan.payment_accounts.easypaisa.number', 'string', false, 'billing'],
        'bank_title' => ['hamsukhan.payment_accounts.bank.title', 'string', false, 'billing'],
        'bank_number' => ['hamsukhan.payment_accounts.bank.number', 'string', false, 'billing'],
        // Provider keys
        'vapi_private_key' => ['hamsukhan.vapi.private_key', 'string', true, 'providers'],
        'vapi_public_key' => ['hamsukhan.vapi.public_key', 'string', false, 'providers'],
        'vapi_webhook_secret' => ['hamsukhan.vapi.webhook_secret', 'string', true, 'providers'],
        'vapi_model' => ['hamsukhan.vapi.model', 'string', false, 'providers'],
        'elevenlabs_api_key' => ['hamsukhan.elevenlabs.api_key', 'string', true, 'providers'],
        'elevenlabs_model' => ['hamsukhan.elevenlabs.model', 'string', false, 'providers'],
        'openai_api_key' => ['hamsukhan.openai.api_key', 'string', true, 'providers'],
        // Plugins / connectors
        'connectors_enabled' => ['hamsukhan.integrations.enabled', 'bool', false, 'plugins'],
        'form_connectors_enabled' => ['hamsukhan.integrations.form_enabled', 'bool', false, 'plugins'],
        'connectors_max_per_bot' => ['hamsukhan.integrations.max_per_bot', 'number', false, 'plugins'],
    ];

    /** Copy DB values into config(). Safe before migrations have run. */
    public function apply(): void
    {
        try {
            $values = Cache::get(self::CACHE_KEY);
            if ($values === null) {
                if (! Schema::hasTable('settings')) {
                    return; // not migrated yet, don't cache "no settings"
                }
                $values = Setting::all()->mapWithKeys(fn (Setting $s) => [$s->key => $s->is_secret && $s->value !== null ? Crypt::decryptString($s->value) : $s->value])->all();
                Cache::forever(self::CACHE_KEY, $values);
            }
        } catch (Throwable) {
            return; // e.g. cache/settings table not migrated yet
        }

        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key]) && $value !== null && $value !== '') {
                [$path, $type] = self::SCHEMA[$key];
                config([$path => $this->cast($type, $value)]);
            }
        }
    }

    /** @param array<string, mixed> $values only keys present are changed; empty secret = keep the old one */
    public function save(array $values, User $admin): array
    {
        $changed = [];
        foreach ($values as $key => $value) {
            if (! isset(self::SCHEMA[$key])) {
                continue;
            }
            [, $type, $secret] = self::SCHEMA[$key];
            if ($secret && ($value === null || $value === '')) {
                continue; // blank secret field means "unchanged"
            }
            $stored = $type === 'bool' ? ($value ? '1' : '0') : ($value === null ? null : (string) $value);
            Setting::updateOrCreate(['key' => $key], [
                'value' => $secret && $stored !== null ? Crypt::encryptString($stored) : $stored,
                'is_secret' => $secret,
                'updated_by' => $admin->id,
            ]);
            $changed[] = $key;
        }
        Cache::forget(self::CACHE_KEY);
        $this->apply();

        return $changed;
    }

    public function clearSecret(string $key, User $admin): void
    {
        if (self::SCHEMA[$key][2] ?? false) {
            Setting::where('key', $key)->delete();
            Cache::forget(self::CACHE_KEY);
            config([self::SCHEMA[$key][0] => null]);
        }
    }

    /** Current effective values for the admin form. Secrets are never sent to the browser, only a masked hint. */
    public function forForm(): array
    {
        $out = [];
        foreach (self::SCHEMA as $key => [$path, $type, $secret]) {
            $value = config($path);
            $out[$key] = $secret
                ? ['set' => filled($value), 'hint' => filled($value) ? '••••'.substr((string) $value, -4) : null, 'source' => Setting::where('key', $key)->exists() ? 'admin' : (filled($value) ? 'env' : null)]
                : $this->cast($type, $value);
        }

        return $out;
    }

    private function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($value) ? $value + 0 : null,
            default => $value,
        };
    }
}
