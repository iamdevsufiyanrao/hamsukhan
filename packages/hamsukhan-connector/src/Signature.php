<?php

namespace Hamsukhan\Connector;

/**
 * Header format:  Hamsukhan-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">
 * The timestamp stops old deliveries from being replayed.
 */
final class Signature
{
    public const HEADER = 'Hamsukhan-Signature';

    public static function sign(string $body, string $secret, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();

        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret);
    }

    public static function verify(string $body, ?string $header, string $secret, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        if (! $header || $secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$k] = $v;
        }
        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $parts['t']) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $parts['t'].'.'.$body, $secret), $parts['v1']);
    }
}
