<?php

use App\Http\Controllers\VapiWebhookController;
use App\Http\Controllers\WidgetController;
use Illuminate\Support\Facades\Route;

// Stateless, no CSRF: called by Vapi's servers and by widget.js on customers' websites.
Route::post('/webhooks/vapi', VapiWebhookController::class)->middleware('throttle:120,1')->name('webhooks.vapi');

Route::middleware('throttle:60,1')->prefix('widget/{publicKey}')->whereUuid('publicKey')->group(function () {
    Route::get('/config', [WidgetController::class, 'config'])->name('widget.config');
    Route::post('/start', [WidgetController::class, 'start'])->name('widget.start');
    Route::options('/{any?}', fn () => response('', 204)->withHeaders([
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type',
    ]));
});
