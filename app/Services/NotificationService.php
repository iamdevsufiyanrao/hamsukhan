<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\IntegrationDelivery;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Owner alerts by email and WhatsApp: new lead, low balance, spending cap, connector failure, daily summary.
 * Every alert has a dedupe key; the same key is never sent twice on the same channel (webhook retries, cron reruns).
 * Sending never throws: a failed alert is logged in notification_logs and must not break a call or booking.
 */
class NotificationService
{
    public function __construct(private WhatsAppService $whatsapp) {}

    public function lead(Booking $booking): void
    {
        $booking->loadMissing('bot.user');
        $user = $booking->bot->user;
        $kind = ['booking' => 'New booking request', 'order' => 'New order', 'callback' => 'Callback requested'][$booking->type] ?? 'New lead';
        $lines = array_filter([
            "{$kind}: {$booking->customer_name}",
            $booking->phone ? "Phone: {$booking->phone}" : null,
            "Request: {$booking->request}",
            $booking->preferred_time ? "When: {$booking->preferred_time}" : null,
            ...collect($booking->details ?? [])->map(fn ($v, $k) => str($k)->headline().': '.(is_array($v) ? implode(', ', $v) : $v))->values()->all(),
            $booking->source === 'widget' ? 'Left with the website callback form.' : null,
        ]);

        $this->send($user, 'lead', "lead:{$booking->id}", "{$kind} · {$booking->customer_name}", implode("\n", $lines), route('bookings'));
    }

    public function lowBalance(User $user, float $threshold): void
    {
        $pkr = (int) round((float) $user->balance * config('hamsukhan.pkr_per_usd'));
        // Key includes the last top-up, so the alert comes back after the next top-up runs low again.
        $lastTopUp = $user->transactions()->where('type', 'topup')->max('id') ?? 0;

        $this->send($user, 'low_balance', "low:{$user->id}:{$threshold}:{$lastTopUp}", 'Your Hamsukhan balance is low',
            "Your wallet balance is about Rs {$pkr} (\${$user->balance}). When it runs out your website assistant stops taking calls. Top up from the Billing page.", route('billing'));
    }

    public function capReached(User $user): void
    {
        $month = now($user->timezone)->format('Y-m');
        $this->send($user, 'cap_reached', "cap:{$user->id}:{$month}", 'Monthly spending limit reached',
            "Your monthly spending limit of \${$user->monthly_cap} is used up, so your website assistant is paused until next month. Raise the limit in Settings to switch it back on.", route('settings'));
    }

    public function integrationFailed(IntegrationDelivery $delivery): void
    {
        $delivery->loadMissing('integration.bot.user', 'booking');
        $user = $delivery->integration?->bot?->user;
        if (! $user) {
            return;
        }
        $this->send($user, 'integration_failed', "delivery:{$delivery->id}", 'A booking could not be sent to your software',
            "The connector \"{$delivery->integration->name}\" could not deliver the booking for {$delivery->booking?->customer_name}. Last answer: ".mb_strimwidth((string) $delivery->response_body, 0, 160, '…').' You can retry it from the Integrations page.', route('integrations'));
    }

    /** End-of-day digest for one owner (only when something happened that day). Returns false when there was nothing to send. */
    public function dailySummary(User $user, Carbon $day): bool
    {
        $bot = $user->bot;
        if (! $bot || ! $user->notify_daily_summary) {
            return false;
        }
        $from = $day->copy()->setTimezone($user->timezone)->startOfDay();
        $to = $from->copy()->endOfDay();
        $calls = $bot->calls()->where('is_test', false)->whereBetween('started_at', [$from->copy()->utc(), $to->copy()->utc()]);
        $count = (clone $calls)->count();
        $leads = $bot->bookings()->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])->latest()->get();
        if ($count === 0 && $leads->isEmpty()) {
            return false;
        }
        $gaps = (clone $calls)->whereNotNull('unanswered')->get()->flatMap(fn ($c) => $c->unanswered ?? [])->unique()->take(5);

        $lines = [
            "Today on your website: {$count} conversation".($count === 1 ? '' : 's').", {$leads->count()} lead".($leads->count() === 1 ? '' : 's').'.',
            ...$leads->take(5)->map(fn (Booking $b) => "• {$b->customer_name}".($b->phone ? " ({$b->phone})" : '').": {$b->request}")->all(),
            ...($gaps->isNotEmpty() ? ['', 'Questions your assistant could not answer:', ...$gaps->map(fn ($q) => "• {$q}")->all()] : []),
            '',
            'Balance: about Rs '.(int) round((float) $user->balance * config('hamsukhan.pkr_per_usd')).'.',
        ];

        $this->send($user, 'daily_summary', "daily:{$user->id}:{$from->toDateString()}", 'Your Hamsukhan summary for '.$from->format('j M'), implode("\n", $lines), route('dashboard'));

        return true;
    }

    private function send(User $user, string $kind, string $key, string $subject, string $text, string $url): void
    {
        if ($user->notify_email && $user->email) {
            $this->deliver($user, $kind, $key, 'email', function () use ($user, $subject, $text, $url) {
                Mail::raw($text."\n\nOpen: {$url}\n\n— ".config('hamsukhan.site.name'), fn ($m) => $m->to($user->email, $user->name)->subject($subject));
            });
        }
        if ($user->notify_whatsapp && $user->whatsapp_number && $this->whatsapp->enabled()) {
            $this->deliver($user, $kind, $key, 'whatsapp', fn () => $this->whatsapp->send($user->whatsapp_number, "{$subject}\n{$text}\n{$url}"));
        }
    }

    private function deliver(User $user, string $kind, string $key, string $channel, callable $send): void
    {
        try {
            $log = NotificationLog::create(['user_id' => $user->id, 'kind' => $kind, 'channel' => $channel, 'dedupe_key' => $key, 'status' => 'sending']);
        } catch (UniqueConstraintViolationException) {
            return; // already sent (or being sent) for this event
        }

        try {
            $send();
            $log->update(['status' => 'sent']);
        } catch (\Throwable $e) {
            Log::warning("[notify] {$kind} via {$channel} failed", ['user' => $user->id, 'error' => $e->getMessage()]);
            $log->update(['status' => 'failed', 'error' => mb_strimwidth($e->getMessage(), 0, 400, '…')]);
        }
    }
}
