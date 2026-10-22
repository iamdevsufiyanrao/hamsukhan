<?php

namespace App\Support;

use App\Models\Bot;
use Illuminate\Support\Carbon;

/**
 * Weekly opening hours + holiday closures for a bot, in Pakistan time.
 * Stored as {"mon": {"open": "10:00", "close": "21:00"}, "sun": null, ...}; a missing or null day means closed.
 * Used for the prompt (so the bot answers "are you open?" correctly) and for after-hours analytics.
 */
class BusinessHours
{
    public const DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    public const TIMEZONE = 'Asia/Karachi';

    /** @param array<string, mixed> $input day => {open, close, closed} */
    public static function normalize(array $input): ?array
    {
        $out = [];
        foreach (array_keys(self::DAYS) as $d) {
            $day = $input[$d] ?? null;
            $ok = is_array($day) && empty($day['closed'])
                && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($day['open'] ?? ''))
                && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($day['close'] ?? ''));
            $out[$d] = $ok ? ['open' => $day['open'], 'close' => $day['close']] : null;
        }

        return array_filter($out) ? $out : null;
    }

    /** @param array<int, array{date?: string, note?: string}> $input */
    public static function normalizeHolidays(array $input): ?array
    {
        $out = collect($input)
            ->filter(fn ($h) => is_array($h) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($h['date'] ?? '')))
            ->map(fn ($h) => ['date' => $h['date'], 'note' => mb_substr(trim((string) ($h['note'] ?? '')), 0, 60)])
            ->unique('date')->sortBy('date')->values()->all();

        return $out ?: null;
    }

    /** Lines appended to the system prompt; empty when no hours are set. */
    public static function promptText(Bot $bot): string
    {
        $hours = $bot->business_hours;
        if (! $hours) {
            return '';
        }
        $lines = ['Opening hours (Pakistan time):'];
        foreach (self::DAYS as $d => $name) {
            $h = $hours[$d] ?? null;
            $lines[] = "- {$name}: ".($h ? self::fmt($h['open']).' to '.self::fmt($h['close']) : 'closed');
        }
        $upcoming = collect($bot->holidays ?? [])->filter(fn ($h) => $h['date'] >= now(self::TIMEZONE)->toDateString())->take(15);
        if ($upcoming->isNotEmpty()) {
            $lines[] = 'Closed on these dates: '.$upcoming->map(fn ($h) => Carbon::parse($h['date'])->format('j M Y').($h['note'] ? " ({$h['note']})" : ''))->implode(', ').'.';
        }
        $lines[] = 'If the caller asks for a time when the business is closed, say so politely and offer the next open slot or a callback.';

        return implode("\n", $lines);
    }

    /** Null when no hours are configured (unknown). */
    public static function isOpen(Bot $bot, Carbon $at): ?bool
    {
        $hours = $bot->business_hours;
        if (! $hours) {
            return null;
        }
        $local = $at->copy()->setTimezone(self::TIMEZONE);
        if (collect($bot->holidays ?? [])->contains('date', $local->toDateString())) {
            return false;
        }
        $h = $hours[strtolower($local->format('D'))] ?? null;
        if (! $h) {
            return false;
        }
        $t = $local->format('H:i');

        // Closing after midnight (e.g. 18:00 to 02:00) wraps around.
        return $h['close'] > $h['open'] ? ($t >= $h['open'] && $t < $h['close']) : ($t >= $h['open'] || $t < $h['close']);
    }

    private static function fmt(string $hm): string
    {
        return Carbon::createFromFormat('H:i', $hm)->format('g:i A');
    }
}
