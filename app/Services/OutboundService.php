<?php

namespace App\Services;

use App\Models\Booking;
use App\Support\BusinessHours;
use App\Support\Phone;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Calls the bot makes itself: callbacks the owner asks for, and appointment reminders. Charged like any other call. */
class OutboundService
{
    public function __construct(private VapiService $vapi, private WalletService $wallet) {}

    public function available(\App\Models\Bot $bot): bool
    {
        return $this->vapi->enabled() && (bool) $this->vapi->outboundNumberId($bot);
    }

    /** @throws RuntimeException with a reason the owner can act on */
    public function callback(Booking $booking): string
    {
        $bot = $booking->bot;
        $number = Phone::normalize($booking->phone);
        if (! $number) {
            throw new RuntimeException('This lead has no valid phone number.');
        }
        if ($booking->ai_callback_at && $booking->ai_callback_at->gt(now()->subMinutes(10))) {
            throw new RuntimeException('The AI already called this customer a few minutes ago.');
        }
        $seconds = $this->affordable($bot);
        $who = $bot->business_name ?: 'our team';
        $id = $this->vapi->outboundCall($bot, $number,
            "Assalam o Alaikum {$booking->customer_name}! Main {$who} ki AI assistant bol rahi hoon. Aap ne humse rabta kiya tha, kya abhi baat karne ka munasib waqt hai?",
            "Call back {$booking->customer_name}, who asked: \"{$booking->request}\". Help them and book if they want.",
            $seconds, ['bookingId' => $booking->id]);
        $booking->forceFill(['ai_callback_at' => now(), 'status' => $booking->status === 'new' ? 'contacted' : $booking->status])->save();

        return $id;
    }

    /** Reminder calls for confirmed appointments starting in the bot's reminder window. Returns how many calls were placed. */
    public function sendDueReminders(): int
    {
        $placed = 0;
        Booking::with('bot.user')->whereNull('reminder_called_at')->where('status', 'confirmed')->whereNotNull('starts_at')
            ->whereHas('bot', fn ($q) => $q->whereNotNull('reminder_hours'))
            ->where('starts_at', '>', now())->where('starts_at', '<=', now()->addHours(48))
            ->each(function (Booking $b) use (&$placed) {
                $bot = $b->bot;
                if ($b->starts_at->gt(now()->addHours($bot->reminder_hours)) || ! $this->available($bot) || $bot->user->isSuspended()) {
                    return;
                }
                $b->forceFill(['reminder_called_at' => now()])->save(); // claim first: never call twice
                $when = $b->starts_at->copy()->setTimezone(BusinessHours::TIMEZONE)->format('l g:i A');
                try {
                    $this->vapi->outboundCall($bot, (string) Phone::normalize($b->phone),
                        "Assalam o Alaikum {$b->customer_name}! {$bot->business_name} ki taraf se yaad dihani: aap ki appointment {$when} ko hai. Kya aap aa rahe hain?",
                        "Remind {$b->customer_name} of the appointment ({$b->request}) on {$when}. If they cannot come, offer another free time or take a cancellation.",
                        min(180, $this->affordable($bot)), ['bookingId' => $b->id, 'reminder' => true]);
                    $placed++;
                } catch (\Throwable $e) {
                    Log::warning('[outbound] reminder failed', ['booking' => $b->id, 'error' => $e->getMessage()]);
                }
            });

        return $placed;
    }

    /**
     * Every 5 minutes: each running campaign calls its next few contacts, only while the business is open
     * (or 10:00–20:00 Pakistan time when no hours are set). A campaign pauses itself when the balance runs out.
     */
    public function runCampaigns(): int
    {
        $placed = 0;
        \App\Models\Campaign::with('bot.user')->where('status', 'running')->each(function (\App\Models\Campaign $c) use (&$placed) {
            $bot = $c->bot;
            // Calls we never got a report for are closed after 30 minutes.
            $c->contacts()->where('status', 'calling')->where('called_at', '<', now()->subMinutes(30))->update(['status' => 'done']);

            if (! $c->contacts()->whereIn('status', ['pending', 'calling'])->exists()) {
                $c->update(['status' => 'done']);

                return;
            }
            $open = BusinessHours::isOpen($bot, now());
            $hour = (int) now(BusinessHours::TIMEZONE)->format('G');
            if ($open === false || ($open === null && ($hour < 10 || $hour >= 20)) || ! $this->available($bot) || $bot->user->isSuspended()) {
                return;
            }
            $free = max(0, $c->per_run - $c->contacts()->where('status', 'calling')->count());
            foreach ($c->contacts()->where('status', 'pending')->orderBy('id')->limit($free)->get() as $contact) {
                $contact->update(['status' => 'calling', 'called_at' => now()]);
                try {
                    $id = $this->vapi->outboundCall($bot, $contact->phone,
                        str_replace('{name}', $contact->name ?: '', $c->first_message),
                        $c->purpose, min(300, $this->affordable($bot)), ['campaignContactId' => $contact->id]);
                    $contact->update(['vapi_call_id' => $id]);
                    $placed++;
                } catch (RuntimeException $e) {
                    $contact->update(['status' => str_contains($e->getMessage(), 'balance') ? 'pending' : 'failed', 'error' => mb_strimwidth($e->getMessage(), 0, 300, '…'), 'called_at' => null]);
                    if (str_contains($e->getMessage(), 'balance')) {
                        $c->update(['status' => 'paused']);
                        break;
                    }
                }
            }
        });

        return $placed;
    }

    private function affordable(\App\Models\Bot $bot): int
    {
        $seconds = $this->wallet->maxAffordableSeconds($bot->user, $bot->max_call_minutes);
        if ($seconds < 60) {
            throw new RuntimeException('Not enough balance (or monthly limit reached) for a call. Top up first.');
        }

        return $seconds;
    }
}
