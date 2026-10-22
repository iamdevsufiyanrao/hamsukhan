<?php

use App\Http\Controllers\VapiWebhookController;
use App\Http\Controllers\WidgetController;
use Illuminate\Support\Facades\Route;

// Stateless, no CSRF: called by Vapi's servers and by widget.js on customers' websites.
Route::post('/webhooks/vapi', VapiWebhookController::class)->middleware('throttle:120,1')->name('webhooks.vapi');

// Landing-page live demo (never billed; its own per-IP hourly limit lives in the controller).
Route::post('/demo/start', [WidgetController::class, 'demo'])->middleware('throttle:10,1')->name('demo.start');

Route::middleware('throttle:60,1')->prefix('widget/{publicKey}')->whereUuid('publicKey')->group(function () {
    Route::get('/config', [WidgetController::class, 'config'])->name('widget.config');
    Route::post('/start', [WidgetController::class, 'start'])->name('widget.start');
    Route::post('/callback', [WidgetController::class, 'callback'])->middleware('throttle:widget-callback')->name('widget.callback');
    Route::options('/{any?}', fn () => response('', 204)->withHeaders([
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type',
    ]));
});
