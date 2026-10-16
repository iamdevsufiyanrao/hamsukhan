<?php

namespace Hamsukhan\Connector\Laravel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Auto-registered in Laravel. Adds POST /hamsukhan/webhook (path configurable). */
class ConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/hamsukhan-connector.php', 'hamsukhan-connector');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../../config/hamsukhan-connector.php' => config_path('hamsukhan-connector.php')], 'hamsukhan-connector');

        if (config('hamsukhan-connector.route.enabled', true)) {
            Route::post(config('hamsukhan-connector.route.path', 'hamsukhan/webhook'), WebhookController::class)
                ->middleware(config('hamsukhan-connector.route.middleware', []))
                ->name('hamsukhan-connector.webhook');
        }
    }
}
