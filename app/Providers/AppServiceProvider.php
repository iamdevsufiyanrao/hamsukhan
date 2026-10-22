<?php

namespace App\Providers;

use App\Services\PlatformSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlatformSettings::class);
    }

    public function boot(): void
    {
        // Super-admin settings (DB) override the config/.env defaults for this request.
        $this->app->make(PlatformSettings::class)->apply();

        // Own key per limiter: plain "throttle:x,y" middlewares on the same route share one counter.
        RateLimiter::for('widget-callback', fn (Request $request) => Limit::perMinutes(10, 5)->by('callback:'.$request->ip().':'.$request->route('publicKey')));
    }
}
