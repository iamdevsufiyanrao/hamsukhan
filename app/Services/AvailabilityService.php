<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Bot;
use App\Services\Integrations\IntegrationDispatcher;
use App\Support\BusinessHours;
use App\Support\CaptureFields;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Free slots and live booking during a call: opening hours − holidays − existing bookings − Google Calendar busy times.
 * Booking re-checks the slot inside a lock on the bot row, so two callers can never get the same slot.
 */
class AvailabilityService
{
    /** Bookings in these statuses no longer hold their slot. */
    public const RELEASED = ['lost', 'spam'];

    /** Earliest bookable moment: no slot starting in the next 30 minutes. */
    private const LEAD_MINUTES = 30;

    public function __construct(private GoogleCalendarService $google, private NotificationService $notifications, private IntegrationDispatcher $integrations) {}

    /** @return list<string> free start times "H:i" (Pakistan time) on that date */
    public function freeSlots(Bot $bot, Carbon $date): array
    {
        $tz = BusinessHours::TIMEZONE;
        $day = $date->copy()->setTimezone($tz)->startOfDay();
        $hours = ($bot->business_hours ?? [])[strtolower($day->format('D'))] ?? null;
        if (! $hours || collect($bot->holidays ?? [])->contains('date', $day->toDateString())) {
            return [];
        }

        $len = max(10, (int) $bot->slot_minutes);
        $open = $day->copy()->setTimeFromTimeString($hours['open']);
        $close = $day->copy()->setTimeFromTimeString($hours['close']);
        if ($close <= $open) {
            $close->addDay(); // closes after midnight
        }
        $earliest = now($tz)->addMinutes(self::LEAD_MINUTES);

        $taken = $bot->bookings()->whereNotIn('status', self::RELEASED)->whereNotNull('starts_at')
            ->where('starts_at', '<', $close->copy()->utc())->where('ends_at', '>', $open->copy()->utc())
            ->get(['starts_at', 'ends_at'])->map(fn ($b) => [$b->starts_at, $b->ends_at])->all();
        $busy = array_merge($taken, $this->googleBusy($bot, $open, $close));

        $slots = [];
        for ($t = $open->copy(); $t->copy()->addMinutes($len) <= $close; $t->addMinutes($len)) {
            $end = $t->copy()->addMinutes($len);
            if ($t < $earliest) {
                continue;
            }
            $clash = collect($busy)->contains(fn ($b) => $b[0] < $end && $b[1] > $t);
            if (! $clash) {
                $slots[] = $t->format('H:i');
            }
        }

        return $slots;
    }

    /** Sentence the bot says for check_availability. */
    public function describe(Bot $bot, string $date): string
    {
        $day = $this->parseDate($date);
        if (! $day) {
            return 'I could not understand that date. Ask the caller for the day again.';
        }
        $slots = $this->freeSlots($bot, $day);
        $label = $day->format('l j F');
        if (! $slots) {
            $next = $this->nextFreeDay($bot, $day);

            return "There are no free slots on {$label}.".($next ? " The next free day is {$next[0]} with slots at ".implode(', ', array_slice($next[1], 0, 6)).'.' : ' Offer a callback from the team.');
        }

        return "Free slots on {$label}: ".implode(', ', array_map(fn ($s) => Carbon::createFromFormat('H:i', $s)->format('g:i A'), array_slice($slots, 0, 12))).'. Offer two or three of these, never a time not on this list.';
    }

    /**
     * Books a slot during the call. Same call + same time twice (Vapi retries) returns the existing booking.
     *
     * @return array{ok: bool, message: string, booking?: Booking}
     */
    public function book(Bot $bot, array $args, ?string $vapiCallId): array
    {
        $day = $this->parseDate((string) ($args['date'] ?? ''));
        $time = $this->parseTime((string) ($args['time'] ?? ''));
        $name = trim((string) ($args['customerName'] ?? ''));
        if (! $day || ! $time || $name === '') {
            return ['ok' => false, 'message' => 'Missing details: you need the date, the time and the caller\'s name before booking.'];
        }
        $tz = BusinessHours::TIMEZONE;
        $start = Carbon::parse($day->toDateString().' '.$time, $tz);
        $end = $start->copy()->addMinutes(max(10, (int) $bot->slot_minutes));

        if ($vapiCallId && ($existing = $bot->bookings()->where('vapi_call_id', $vapiCallId)->where('starts_at', $start->copy()->utc())->first())) {
            return ['ok' => true, 'message' => $this->confirmation($existing), 'booking' => $existing];
        }

        $booking = DB::transaction(function () use ($bot, $start, $end, $args, $name, $vapiCallId) {
            Bot::whereKey($bot->id)->lockForUpdate()->first(); // serialise bookings per bot
            if (! in_array($start->format('H:i'), $this->freeSlots($bot, $start), true)) {
                return null;
            }

            return $bot->bookings()->create([
                'type' => 'booking',
                'source' => 'call',
                'status' => 'confirmed',
                'customer_name' => mb_substr($name, 0, 120),
                'phone' => Phone::clean($args['phone'] ?? null),
                'request' => mb_substr(trim((string) ($args['service'] ?? '')) ?: 'Appointment', 0, 250),
                'preferred_time' => $start->format('D j M, g:i A'),
                'details' => CaptureFields::extract($bot->capture_fields ?? [], (array) ($args['details'] ?? [])) ?: null,
                'starts_at' => $start->copy()->utc(),
                'ends_at' => $end->copy()->utc(),
                'vapi_call_id' => $vapiCallId,
            ]);
        });

        if (! $booking) {
            return ['ok' => false, 'message' => 'That time is no longer free. '.$this->describe($bot, $start->toDateString())];
        }

        $this->afterBooked($bot, $booking);

        return ['ok' => true, 'message' => $this->confirmation($booking), 'booking' => $booking];
    }

    /** A lost/spam booking frees its slot and its Google event. */
    public function release(Booking $booking): void
    {
        $cal = $booking->bot->user->googleCalendar;
        if ($booking->google_event_id && $cal) {
            rescue(fn () => $this->google->deleteEvent($cal, $booking->google_event_id));
            $booking->forceFill(['google_event_id' => null])->save();
        }
    }

    private function afterBooked(Bot $bot, Booking $booking): void
    {
        if ($cal = $bot->user->googleCalendar) {
            try {
                $booking->forceFill(['google_event_id' => $this->google->createEvent($cal, $booking, BusinessHours::TIMEZONE)])->save();
            } catch (\Throwable $e) {
                Log::warning('[calendar] event not created', ['booking' => $booking->id, 'error' => $e->getMessage()]);
            }
        }
        rescue(fn () => $this->integrations->dispatchForBooking($booking->setRelation('bot', $bot)));
        $this->notifications->lead($booking);
    }

    private function confirmation(Booking $booking): string
    {
        $when = $booking->starts_at->copy()->setTimezone(BusinessHours::TIMEZONE)->format('l j F \a\t g:i A');

        return "Booked: {$booking->request} for {$booking->customer_name} on {$when}. Confirm this to the caller and tell them the team will send a confirmation.";
    }

    /** @return array{0: string, 1: list<string>}|null */
    private function nextFreeDay(Bot $bot, Carbon $after): ?array
    {
        for ($i = 1; $i <= 14; $i++) {
            $d = $after->copy()->addDays($i);
            if ($slots = $this->freeSlots($bot, $d)) {
                return [$d->format('l j F'), array_map(fn ($s) => Carbon::createFromFormat('H:i', $s)->format('g:i A'), $slots)];
            }
        }

        return null;
    }

    /** @return array<int, array{0: Carbon, 1: Carbon}> */
    private function googleBusy(Bot $bot, Carbon $from, Carbon $to): array
    {
        $cal = $bot->user->googleCalendar;
        if (! $cal) {
            return [];
        }
        try {
            return $this->google->busy($cal, $from, $to);
        } catch (\Throwable $e) {
            // Calendar down: still offer slots from our own bookings; the error shows on the Integrations page.
            Log::warning('[calendar] free/busy failed', ['bot' => $bot->id, 'error' => $e->getMessage()]);

            return [];
        }
    }

    private function parseDate(string $date): ?Carbon
    {
        $tz = BusinessHours::TIMEZONE;
        $date = strtolower(trim($date));
        $day = match ($date) {
            'today', 'aaj' => now($tz),
            'tomorrow', 'kal' => now($tz)->addDay(),
            default => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? Carbon::createFromFormat('Y-m-d', $date, $tz) : null,
        };
        if (! $day || $day->copy()->endOfDay()->isPast() || $day->gt(now($tz)->addDays(90))) {
            return null;
        }

        return $day->startOfDay();
    }

    private function parseTime(string $time): ?string
    {
        $time = strtolower(trim($time));
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            return sprintf('%02d:%02d', $m[1], $m[2]);
        }
        if (preg_match('/^(1[0-2]|0?[1-9])(?::([0-5]\d))?\s*(am|pm)$/', $time, $m)) {
            $h = (int) $m[1] % 12 + ($m[3] === 'pm' ? 12 : 0);

            return sprintf('%02d:%02d', $h, (int) ($m[2] ?? 0));
        }

        return null;
    }
}
