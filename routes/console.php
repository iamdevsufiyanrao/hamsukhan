<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Retries failed deliveries to customers' software (connectors). On the host: one cron line
//   * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
// Locally: php artisan schedule:work
Schedule::command('hamsukhan:deliver-integrations')->everyMinute()->withoutOverlapping();

// Heartbeat so Super admin → Settings → Cron & system can show whether cron is actually running.
Schedule::call(fn () => Cache::forever('hamsukhan:cron_last_run', now()->getTimestamp()))->everyMinute()->name('cron-heartbeat');

// End-of-day summary to every owner who wants one (only sent on days with calls or leads, once per day).
Schedule::call(function () {
    $notify = app(\App\Services\NotificationService::class);
    \App\Models\User::where('is_admin', false)->whereNull('suspended_at')->where('notify_daily_summary', true)->whereHas('bot')
        ->each(fn ($user) => rescue(fn () => $notify->dailySummary($user, now())));
})->dailyAt(config('hamsukhan.notifications.daily_summary_at', '21:00'))->timezone('Asia/Karachi')->name('daily-summary');

// Appointment reminder calls (bots with reminder_hours set; each booking is called at most once).
Schedule::call(fn () => app(\App\Services\OutboundService::class)->sendDueReminders())->everyFifteenMinutes()->name('reminder-calls')->withoutOverlapping();

// Outbound campaigns: next few contacts of every running campaign, inside opening hours.
Schedule::call(fn () => app(\App\Services\OutboundService::class)->runCampaigns())->everyFiveMinutes()->name('campaign-calls')->withoutOverlapping();

// Public landing-page demo calls are kept at most 7 days (promised on /privacy). They live on the demo bot as test calls.
Schedule::call(function () {
    $bot = ($key = config('hamsukhan.demo.bot_key')) ? \App\Models\Bot::where('public_key', $key)->first() : null;
    $bot?->calls()->where('is_test', true)->where('created_at', '<', now()->subDays(7))->each(fn ($call) => $call->delete());
})->daily()->name('prune-demo-calls');
