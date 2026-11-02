<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Call;
use App\Models\LiveCall;
use App\Models\TopUp;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Monthly plans. The only place that changes an account's plan or its paid-until date.
 * Calls are unlimited while the plan is paid; the plan's fair-use minutes (if set) are a monthly safety limit.
 */
class SubscriptionService
{
    /** @return array<string, array{name: string, price_pkr: int, stores: int, users: int, fair_use_minutes: ?int}> */
    public function plans(): array
    {
        return config('hamsukhan.plans');
    }

    /** Talk time of all the account's stores in its current calendar month (its timezone). Failed connections don't count. */
    public function usedSecondsThisMonth(User $account): int
    {
        $start = now($account->timezone)->startOfMonth()->utc();

        return (int) Call::whereIn('bot_id', Bot::where('user_id', $account->id)->select('id'))
            ->where('started_at', '>=', $start)->where('status', '!=', 'failed')->sum('duration_seconds');
    }

    /** Why this store can't take a call right now (no_plan | store_limit | fair_use), or null when it can. */
    public function blockReason(Bot $bot): ?string
    {
        $account = $bot->user;
        if (! $account->activePlan()) {
            return 'no_plan';
        }
        $limits = $account->planLimits();
        // After a downgrade the oldest stores keep working, the rest pause until the plan allows them again.
        if (! Bot::where('user_id', $account->id)->orderBy('id')->limit($limits['stores'])->pluck('id')->contains($bot->id)) {
            return 'store_limit';
        }
        if ($limits['fair_use_minutes'] && $this->usedSecondsThisMonth($account) >= $limits['fair_use_minutes'] * 60) {
            return 'fair_use';
        }

        return null;
    }

    /** Longest call (seconds) this store may take now: the bot's max length, or less near the fair-use limit; 0 when blocked. */
    public function maxCallSeconds(Bot $bot): int
    {
        if ($this->blockReason($bot)) {
            return 0;
        }
        $seconds = $bot->max_call_minutes * 60;
        if ($minutes = $bot->user->planLimits()['fair_use_minutes']) {
            $seconds = min($seconds, $minutes * 60 - $this->usedSecondsThisMonth($bot->user));
        }

        return max(0, $seconds);
    }

    /** A call that never sent "ended" (missed webhook) stops counting after this long; no call lasts longer (max 15 min). */
    private const LIVE_CALL_TTL_MINUTES = 20;

    /** Calls just started that Vapi hasn't reported "in-progress" for yet still count for this many seconds. */
    private const STARTING_TTL_SECONDS = 45;

    /** Calls running now on the account's stores (reported in-progress, plus ones just starting). */
    public function activeCalls(User $account): int
    {
        $live = LiveCall::whereIn('bot_id', Bot::where('user_id', $account->id)->select('id'))
            ->where('started_at', '>=', now()->subMinutes(self::LIVE_CALL_TTL_MINUTES))->count();

        return $live + count($this->starting("account:{$account->id}"));
    }

    /** Calls running now on the whole platform. */
    public function platformActiveCalls(): int
    {
        return LiveCall::where('started_at', '>=', now()->subMinutes(self::LIVE_CALL_TTL_MINUTES))->count() + count($this->starting('platform'));
    }

    /**
     * 'busy' when the concurrent-call limit is on and the account (its plan's `concurrent_calls`)
     * or the platform (`concurrency.platform_max`) already has that many calls running; else null.
     */
    public function busyReason(Bot $bot): ?string
    {
        if (! config('hamsukhan.concurrency.enabled')) {
            return null;
        }
        $limit = (int) ($bot->user->planLimits()['concurrent_calls'] ?? 0);
        if ($limit > 0 && $this->activeCalls($bot->user) >= $limit) {
            return 'busy';
        }
        $platform = (int) config('hamsukhan.concurrency.platform_max');

        return $platform > 0 && $this->platformActiveCalls() >= $platform ? 'busy' : null;
    }

    /** Holds a line for a call that is starting, until Vapi reports it in progress (see activeCalls). */
    public function reserveLine(Bot $bot): void
    {
        if (! config('hamsukhan.concurrency.enabled')) {
            return;
        }
        foreach (["account:{$bot->user_id}", 'platform'] as $scope) {
            Cache::lock("hamsukhan:starting:{$scope}:lock", 5)->block(3, function () use ($scope) {
                $list = $this->starting($scope);
                $list[] = now()->getTimestamp();
                Cache::put("hamsukhan:starting:{$scope}", $list, self::STARTING_TTL_SECONDS);
            });
        }
    }

    /** @return list<int> start times (unix) of calls still starting */
    private function starting(string $scope): array
    {
        $since = now()->getTimestamp() - self::STARTING_TTL_SECONDS;

        return array_values(array_filter((array) Cache::get("hamsukhan:starting:{$scope}", []), fn ($t) => $t >= $since));
    }

    public static function blockMessage(string $reason): string
    {
        return [
            'no_plan' => 'The monthly plan is not active. Renew it from the Billing page.',
            'store_limit' => 'Your plan covers fewer stores. Upgrade to Business to switch this store on.',
            'fair_use' => "This month's fair-use limit is reached. It resets on the 1st, or contact support.",
            'busy' => 'All lines are busy right now. Please try again in a few minutes.',
        ][$reason] ?? 'This assistant is not available right now.';
    }

    public function priceFor(string $plan, int $months): int
    {
        return $this->plans()[$plan]['price_pkr'] * $months;
    }

    /**
     * Starts or extends a plan. The same plan while still paid is extended from its end date;
     * a new plan (or an expired one) starts today.
     */
    public function activate(User $account, string $plan, int $months): User
    {
        if (! isset($this->plans()[$plan]) || $months < 1 || $months > 12) {
            throw new InvalidArgumentException('Unknown plan or months.');
        }

        return DB::transaction(function () use ($account, $plan, $months) {
            $account = User::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $from = $account->activePlan() === $plan ? $account->plan_expires_at : now();
            $account->forceFill(['plan' => $plan, 'plan_expires_at' => $from->copy()->addMonthsNoOverflow($months)])->save();

            return $account;
        });
    }

    public function approvePayment(TopUp $payment, User $admin, ?string $note = null): User
    {
        return DB::transaction(function () use ($payment, $admin, $note) {
            $payment = TopUp::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'pending') {
                throw new RuntimeException('This payment was already reviewed.');
            }
            if (! $payment->plan) {
                throw new RuntimeException('This is an old wallet top-up, it has no plan. Reject it and ask the customer to pay for a plan.');
            }
            $payment->update(['status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'admin_note' => $note]);

            return $this->activate($payment->user, $payment->plan, $payment->months);
        });
    }

    /**
     * The gateway's signed answer for an online payment: paid ⇒ the plan switches on now; else the payment is closed
     * with the gateway's reason. Runs once per payment (a repeated answer is ignored). Returns the payment.
     */
    public function completeOnlinePayment(TopUp $payment, bool $paid, string $note, array $response): TopUp
    {
        return DB::transaction(function () use ($payment, $paid, $note, $response) {
            $payment = TopUp::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'pending') {
                return $payment;
            }
            $payment->update(['status' => $paid ? 'approved' : 'rejected', 'reviewed_at' => now(), 'admin_note' => mb_substr($note, 0, 190), 'gateway_response' => $response]);
            if ($paid) {
                $this->activate($payment->user, $payment->plan, $payment->months);
            }

            return $payment;
        });
    }

    public function rejectPayment(TopUp $payment, User $admin, ?string $note = null): void
    {
        DB::transaction(function () use ($payment, $admin, $note) {
            $payment = TopUp::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'pending') {
                throw new RuntimeException('This payment was already reviewed.');
            }
            $payment->update(['status' => 'rejected', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'admin_note' => $note]);
        });
    }

    /** After a call: tell the owner once when the fair-use limit is reached (see NotificationService). */
    public function checkAlerts(?User $account): void
    {
        $minutes = $account?->planLimits()['fair_use_minutes'] ?? null;
        if ($account && $minutes && $account->activePlan() && $this->usedSecondsThisMonth($account) >= $minutes * 60) {
            app(NotificationService::class)->fairUseReached($account);
        }
    }

    /** Usage summary for the dashboard and billing page. */
    public function summary(User $account): array
    {
        $plan = $account->activePlan();
        $limits = $account->planLimits();

        return [
            'plan' => $plan,
            'planName' => $plan ? $limits['name'] : null,
            'expiresAt' => $account->plan_expires_at?->toIso8601String(),
            'active' => $plan !== null,
            'daysLeft' => $plan ? (int) ceil(now()->diffInDays($account->plan_expires_at, true)) : 0,
            'stores' => $limits['stores'],
            'users' => $limits['users'],
            // How many calls can run at once (null = the limit is switched off).
            'concurrentCalls' => config('hamsukhan.concurrency.enabled') ? (int) ($limits['concurrent_calls'] ?? 0) : null,
            'minutesUsed' => (int) round($this->usedSecondsThisMonth($account) / 60),
            'fairUseMinutes' => $limits['fair_use_minutes'],
        ];
    }
}
