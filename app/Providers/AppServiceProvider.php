<?php

namespace App\Providers;

use App\Services\PlatformSettings;
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
    }
}
