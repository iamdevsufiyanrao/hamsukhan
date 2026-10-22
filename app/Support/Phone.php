<?php

namespace App\Support;

/** Pakistan-first phone numbers: everything is stored as +92XXXXXXXXXX (E.164) when it can be recognised. */
class Phone
{
    /** "0300-1234567", "92 300 1234567", "+923001234567", "3001234567" → "+923001234567". Other countries keep their + form. Null if not a phone number. */
    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $plus = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }

        return match (true) {
            // Pakistani mobiles (3XX) and landlines (area code 2-9, e.g. 21 Karachi, 42 Lahore)
            (bool) preg_match('/^92([1-9]\d{8,9})$/', $digits, $m) => '+92'.$m[1],
            ! $plus && (bool) preg_match('/^0([1-9]\d{8,9})$/', $digits, $m) => '+92'.$m[1],
            ! $plus && (bool) preg_match('/^(3\d{9})$/', $digits, $m) => '+92'.$m[1],
            $plus && strlen($digits) >= 8 && strlen($digits) <= 15 => '+'.$digits,
            default => null,
        };
    }

    /** Normalised when possible, otherwise the original text (never lose what the caller said). */
    public static function clean(?string $raw): ?string
    {
        return self::normalize($raw) ?? (filled($raw) ? mb_substr(trim($raw), 0, 40) : null);
    }
}
