<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Retries failed deliveries to customers' software (connectors). On the host: one cron line
//   * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
// Locally: php artisan schedule:work
Schedule::command('hamsukhan:deliver-integrations')->everyMinute()->withoutOverlapping();

// Heartbeat so Super admin → Settings → Cron & system can show whether cron is actually running.
Schedule::call(fn () => Cache::forever('hamsukhan:cron_last_run', now()->getTimestamp()))->everyMinute()->name('cron-heartbeat');
