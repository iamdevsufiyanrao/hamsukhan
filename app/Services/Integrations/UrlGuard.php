<?php

namespace App\Services\Integrations;

/**
 * Customers type in the URL we POST to, so it must not point at our own network (SSRF).
 * Local development may allow private addresses to test against a receiver on the same machine.
 */
class UrlGuard
{
    public static function check(string $url): ?string
    {
        $parts = parse_url($url);
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return 'Use a full http(s):// URL.';
        }
        if (app()->isProduction() && $parts['scheme'] !== 'https') {
            return 'Use an https:// URL.';
        }
        if (config('hamsukhan.integrations.allow_private_urls')) {
            return null;
        }

        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            return 'That address could not be found.';
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'Private or local network addresses are not allowed.';
            }
        }

        return null;
    }
}
