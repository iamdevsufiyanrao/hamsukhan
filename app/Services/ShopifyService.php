<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\ShopifyShop;
use App\Support\ProviderError;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Hamsukhan Shopify app: OAuth install, then the call widget is added to the store as a ScriptTag
 * (widget.js?bot=KEY, since script tags can't carry data attributes). Offline tokens don't expire, so a
 * shop stays connected until the merchant uninstalls the app (app/uninstalled webhook).
 */
class ShopifyService
{
    public function enabled(): bool
    {
        return (bool) config('hamsukhan.shopify.enabled') && filled(config('hamsukhan.shopify.client_id')) && filled(config('hamsukhan.shopify.client_secret'));
    }

    public static function isValidShop(?string $shop): bool
    {
        return is_string($shop) && (bool) preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop);
    }

    /** "mystore", "mystore.myshopify.com" or "https://mystore.myshopify.com/admin" → "mystore.myshopify.com" (null if not a shop). */
    public static function normaliseShop(?string $input): ?string
    {
        $host = strtolower(trim((string) $input));
        if ($host === '') {
            return null;
        }
        $host = (string) (parse_url(str_contains($host, '://') ? $host : "https://{$host}", PHP_URL_HOST) ?: '');
        if (! str_contains($host, '.')) {
            $host .= '.myshopify.com';
        }

        return self::isValidShop($host) ? $host : null;
    }

    public function authorizeUrl(string $shop, string $state): string
    {
        return "https://{$shop}/admin/oauth/authorize?".http_build_query([
            'client_id' => config('hamsukhan.shopify.client_id'),
            'scope' => config('hamsukhan.shopify.scopes'),
            'redirect_uri' => route('shopify.callback'),
            'state' => $state,
        ]);
    }

    /** Shopify signs OAuth redirects: hmac = HMAC-SHA256(secret, the other query params sorted as k=v joined by &). */
    public function verifyHmac(array $query): bool
    {
        $hmac = (string) ($query['hmac'] ?? '');
        unset($query['hmac'], $query['signature']);
        ksort($query);
        $message = collect($query)->map(fn ($v, $k) => $k.'='.(is_array($v) ? implode(',', $v) : $v))->implode('&');

        return $hmac !== '' && hash_equals(hash_hmac('sha256', $message, (string) config('hamsukhan.shopify.client_secret')), $hmac);
    }

    /** Webhooks: X-Shopify-Hmac-Sha256 = base64(HMAC-SHA256(secret, raw body)). */
    public function verifyWebhook(string $rawBody, ?string $header): bool
    {
        return filled($header) && hash_equals(base64_encode(hash_hmac('sha256', $rawBody, (string) config('hamsukhan.shopify.client_secret'), true)), (string) $header);
    }

    /** @return array{access_token: string, scope: ?string} */
    public function exchangeCode(string $shop, string $code): array
    {
        try {
            $json = Http::acceptJson()->timeout(15)->post("https://{$shop}/admin/oauth/access_token", [
                'client_id' => config('hamsukhan.shopify.client_id'),
                'client_secret' => config('hamsukhan.shopify.client_secret'),
                'code' => $code,
            ])->throw()->json();
        } catch (\Throwable $e) {
            throw new RuntimeException(ProviderError::message($e, 'Shopify'), 0, $e);
        }
        if (empty($json['access_token'])) {
            throw new RuntimeException('Shopify did not return an access token. Please try connecting again.');
        }

        return ['access_token' => $json['access_token'], 'scope' => $json['scope'] ?? null];
    }

    /** Adds (or re-adds) the call widget to the store's pages. */
    public function installWidget(ShopifyShop $shop): void
    {
        $this->removeWidget($shop);
        try {
            $id = $this->api($shop)->post('script_tags.json', ['script_tag' => [
                'event' => 'onload',
                'src' => url('/widget.js').'?bot='.$shop->bot->public_key,
            ]])->throw()->json('script_tag.id');
        } catch (\Throwable $e) {
            throw new RuntimeException(ProviderError::message($e, 'Shopify'), 0, $e);
        }
        $shop->forceFill(['script_tag_id' => $id ? (string) $id : null])->save();
        $this->allowShopDomain($shop->bot, $shop->shop);
    }

    /** Best effort: a 404 means it is already gone. */
    public function removeWidget(ShopifyShop $shop): void
    {
        if (! $shop->script_tag_id) {
            return;
        }
        try {
            $response = $this->api($shop)->delete("script_tags/{$shop->script_tag_id}.json");
            if ($response->failed() && $response->status() !== 404) {
                $response->throw();
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(ProviderError::message($e, 'Shopify'), 0, $e);
        }
        $shop->forceFill(['script_tag_id' => null])->save();
    }

    /** So we hear when the merchant removes the app. An existing subscription (422) is fine. */
    public function registerUninstallWebhook(ShopifyShop $shop): void
    {
        try {
            $response = $this->api($shop)->post('webhooks.json', ['webhook' => [
                'topic' => 'app/uninstalled',
                'address' => route('shopify.webhook'),
                'format' => 'json',
            ]]);
            if ($response->failed() && $response->status() !== 422) {
                $response->throw();
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(ProviderError::message($e, 'Shopify'), 0, $e);
        }
    }

    /** An allow-list that is in use must also list the shop, or the widget would refuse to run there. */
    private function allowShopDomain(Bot $bot, string $domain): void
    {
        $list = array_values(array_filter($bot->allowed_domains ?? []));
        if ($list && ! in_array($domain, $list, true)) {
            $bot->update(['allowed_domains' => [...$list, $domain]]);
        }
    }

    private function api(ShopifyShop $shop): PendingRequest
    {
        return Http::withHeaders(['X-Shopify-Access-Token' => $shop->access_token])
            ->baseUrl("https://{$shop->shop}/admin/api/".config('hamsukhan.shopify.api_version'))
            ->acceptJson()
            ->timeout(15);
    }
}
