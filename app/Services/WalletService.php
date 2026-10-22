<?php

namespace App\Services;

use App\Models\Call;
use App\Models\TopUp;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only place that changes a user's balance.
 * Each change locks the user row, updates the balance and writes a ledger row in one transaction.
 */
class WalletService
{
    public function costFor(int $seconds): float
    {
        return round($seconds * config('hamsukhan.rate_per_minute') / 60, 4);
    }

    /**
     * Longest call (in seconds) the owner can pay for: the bot's max length, the wallet balance,
     * and what is left of the owner's monthly spending cap.
     */
    public function maxAffordableSeconds(User $user, int $capMinutes): int
    {
        $perSecond = config('hamsukhan.rate_per_minute') / 60;
        $money = (float) $user->balance;
        if ($user->monthly_cap !== null) {
            $money = min($money, max(0, (float) $user->monthly_cap - $this->spentThisMonth($user)));
        }

        return (int) max(0, min($capMinutes * 60, floor($money / $perSecond)));
    }

    /** Call charges in the owner's current calendar month (their timezone), as a positive amount. */
    public function spentThisMonth(User $user): float
    {
        $start = now($user->timezone)->startOfMonth()->utc();

        return round(-(float) $user->transactions()->where('type', 'call_charge')->where('created_at', '>=', $start)->sum('amount'), 4);
    }

    /** After a charge: low-balance and spending-cap alerts (each one is sent once, see NotificationService). */
    public function checkAlerts(?User $user): void
    {
        if (! $user) {
            return;
        }
        $user->refresh();
        $notify = app(NotificationService::class);
        foreach (config('hamsukhan.notifications.low_balance_thresholds', []) as $threshold) {
            if ((float) $user->balance < $threshold) {
                $notify->lowBalance($user, (float) $threshold);
                break; // only the highest threshold crossed
            }
        }
        if ($user->monthly_cap !== null && $this->spentThisMonth($user) >= (float) $user->monthly_cap) {
            $notify->capReached($user);
        }
    }

    public function approveTopUp(TopUp $topUp, User $admin, ?string $note = null): WalletTransaction
    {
        return DB::transaction(function () use ($topUp, $admin, $note) {
            $topUp = TopUp::whereKey($topUp->id)->lockForUpdate()->firstOrFail();
            if ($topUp->status !== 'pending') {
                throw new RuntimeException('This top-up was already reviewed.');
            }

            $topUp->update(['status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'admin_note' => $note]);

            return $this->apply($topUp->user_id, (float) $topUp->amount_usd, 'topup', "Top-up · {$topUp->methodLabel()} · {$topUp->reference}", topUpId: $topUp->id);
        });
    }

    public function rejectTopUp(TopUp $topUp, User $admin, ?string $note = null): void
    {
        DB::transaction(function () use ($topUp, $admin, $note) {
            $topUp = TopUp::whereKey($topUp->id)->lockForUpdate()->firstOrFail();
            if ($topUp->status !== 'pending') {
                throw new RuntimeException('This top-up was already reviewed.');
            }
            $topUp->update(['status' => 'rejected', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'admin_note' => $note]);
        });
    }

    /**
     * Charge a finished call. Idempotent per call (wallet_transactions.call_id is unique).
     * The balance never goes below zero; maxDurationSeconds at call start keeps the gap tiny.
     */
    public function chargeCall(Call $call): ?WalletTransaction
    {
        if ((float) $call->cost <= 0 || WalletTransaction::where('call_id', $call->id)->exists()) {
            return null;
        }

        return $this->apply($call->bot->user_id, -(float) $call->cost, 'call_charge', 'Call · '.$call->title, callId: $call->id, clampAtZero: true);
    }

    public function adjust(User $user, float $amount, string $description): WalletTransaction
    {
        return $this->apply($user->id, $amount, 'adjustment', $description);
    }

    private function apply(int $userId, float $amount, string $type, string $description, ?int $callId = null, ?int $topUpId = null, bool $clampAtZero = false): WalletTransaction
    {
        if ($amount == 0.0) {
            throw new InvalidArgumentException('Amount must not be zero.');
        }

        return DB::transaction(function () use ($userId, $amount, $type, $description, $callId, $topUpId, $clampAtZero) {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $balance = (float) $user->balance;

            if ($amount < 0 && $clampAtZero) {
                $amount = -min(-$amount, $balance);
            } elseif ($amount < 0 && $balance + $amount < 0) {
                throw new RuntimeException('Insufficient balance.');
            }

            $new = round($balance + $amount, 4);
            $user->forceFill(['balance' => $new])->save();

            return $user->transactions()->create([
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $new,
                'description' => mb_strimwidth($description, 0, 250, '…'),
                'call_id' => $callId,
                'top_up_id' => $topUpId,
                'created_at' => now(),
            ]);
        });
    }
}
