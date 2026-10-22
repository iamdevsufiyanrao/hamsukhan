<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\MessageController;
use App\Http\Controllers\Admin\VoiceSampleController as AdminVoiceSampleController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\TopUpController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BotStudioController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\KnowledgeFileController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\Admin\ProviderVoiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TestCallController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\VoiceController;
use App\Http\Controllers\VoiceSampleController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\InsightsController;
use App\Http\Middleware\EnsureBot;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureNotSuspended;
use Illuminate\Support\Facades\Route;

// Public landing page; signed-in users see it too (its buttons point to the dashboard).
Route::get('/', [VoiceSampleController::class, 'landing'])->name('home');
Route::get('/voice-samples/{sample}/play', [VoiceSampleController::class, 'play'])->middleware(['signed', 'throttle:60,1'])->name('voice-samples.play');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1')->name('contact');
Route::get('/terms', [LegalController::class, 'terms'])->name('terms');
Route::get('/privacy', [LegalController::class, 'privacy'])->name('privacy');

// ---------- Guests ----------
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::get('/forgot-password', [AuthController::class, 'showForgot'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->name('password.update');
    Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verify'])->middleware('throttle:two-factor');
});

// ---------- Any signed-in user ----------
Route::middleware(['auth', EnsureNotSuspended::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:3,1')->name('verification.send');
    Route::post('/impersonation/stop', [AccountController::class, 'stopImpersonating'])->name('impersonation.stop');

    Route::get('/settings', [SettingsController::class, 'show'])->name('settings');
    Route::patch('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile');
    Route::post('/settings/two-factor', [TwoFactorController::class, 'enable'])->middleware('throttle:5,1')->name('two-factor.enable');
    Route::post('/settings/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:two-factor')->name('two-factor.confirm');
    Route::post('/settings/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])->middleware('throttle:5,1')->name('two-factor.codes');
    Route::delete('/settings/two-factor', [TwoFactorController::class, 'disable'])->middleware('throttle:5,1')->name('two-factor.disable');
    Route::post('/settings/sessions/logout-others', [TwoFactorController::class, 'logoutOthers'])->middleware('throttle:5,1')->name('sessions.logout-others');
    Route::patch('/settings/notifications',[SettingsController::class, 'updateNotifications'])->name('settings.notifications');
    Route::post('/settings/notifications/test', [SettingsController::class, 'testNotification'])->middleware('throttle:3,1')->name('settings.notifications.test');
    Route::post('/settings/avatar', [SettingsController::class, 'updateAvatar'])->name('settings.avatar');
    Route::delete('/settings/avatar', [SettingsController::class, 'destroyAvatar'])->name('settings.avatar.destroy');
    Route::put('/settings/password', [SettingsController::class, 'updatePassword'])->name('settings.password');
    Route::delete('/settings/account', [SettingsController::class, 'destroy'])->name('settings.destroy');

    // ---------- Super admin ----------
    Route::middleware(EnsureAdmin::class)->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AccountController::class, 'overview'])->name('overview');
        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts');
        Route::get('/accounts/{user}', [AccountController::class, 'show'])->name('accounts.show');
        Route::post('/accounts/{user}/suspend', [AccountController::class, 'suspend'])->name('accounts.suspend');
        Route::post('/accounts/{user}/activate', [AccountController::class, 'activate'])->name('accounts.activate');
        Route::post('/accounts/{user}/balance', [AccountController::class, 'adjustBalance'])->name('accounts.balance');
        Route::post('/accounts/{user}/impersonate', [AccountController::class, 'impersonate'])->name('accounts.impersonate');

        Route::get('/settings', [AdminSettingsController::class, 'show'])->name('settings');
        Route::patch('/settings/{group}', [AdminSettingsController::class, 'update'])->name('settings.update');
        Route::delete('/settings/secret/{key}', [AdminSettingsController::class, 'clearSecret'])->name('settings.secret');
        Route::post('/settings/test/{provider}', [AdminSettingsController::class, 'test'])->middleware('throttle:10,1')->name('settings.test');
        Route::post('/settings/link-elevenlabs', [AdminSettingsController::class, 'linkElevenLabs'])->middleware('throttle:10,1')->name('settings.link-elevenlabs');
        Route::post('/settings/resync-bots', [AdminSettingsController::class, 'resyncBots'])->middleware('throttle:5,1')->name('settings.resync');

        Route::get('/provider-voices', [ProviderVoiceController::class, 'index'])->name('provider-voices');
        Route::post('/provider-voices/assign', [ProviderVoiceController::class, 'assign'])->name('provider-voices.assign');
        Route::post('/settings/connectors/{integration}/toggle', [AdminSettingsController::class, 'toggleConnector'])->name('settings.connector');
        Route::post('/settings/cron/run', [AdminSettingsController::class, 'runCron'])->name('settings.cron');
        Route::get('/settings/package.zip', [IntegrationController::class, 'package'])->name('settings.package');

        Route::get('/top-ups', [TopUpController::class, 'index'])->name('top-ups');
        Route::get('/top-ups/{topUp}/proof', [TopUpController::class, 'proof'])->name('top-ups.proof');
        Route::post('/top-ups/{topUp}/approve', [TopUpController::class, 'approve'])->name('top-ups.approve');
        Route::post('/top-ups/{topUp}/reject', [TopUpController::class, 'reject'])->name('top-ups.reject');

        Route::get('/voice-samples', [AdminVoiceSampleController::class, 'index'])->name('voice-samples');
        Route::post('/voice-samples', [AdminVoiceSampleController::class, 'store'])->name('voice-samples.store');
        Route::patch('/voice-samples/{sample}', [AdminVoiceSampleController::class, 'update'])->name('voice-samples.update');
        Route::delete('/voice-samples/{sample}', [AdminVoiceSampleController::class, 'destroy'])->name('voice-samples.destroy');

        Route::get('/messages', [MessageController::class, 'index'])->name('messages');
        Route::post('/messages/{message}/read', [MessageController::class, 'markRead'])->name('messages.read');
        Route::delete('/messages/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');
    });
});

// ---------- Customer app (needs a bot) ----------
Route::middleware(['auth', EnsureNotSuspended::class, EnsureEmailVerified::class, EnsureBot::class])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/bot-studio', [BotStudioController::class, 'show'])->name('bot-studio');
    Route::patch('/bot-studio/config', [BotStudioController::class, 'updateConfig'])->name('bot.config');
    Route::patch('/bot-studio/capture', [BotStudioController::class, 'updateCaptureFields'])->name('bot.capture');
    Route::patch('/bot-studio/voice', [BotStudioController::class, 'updateVoice'])->name('bot.voice');
    Route::patch('/bot-studio/knowledge', [BotStudioController::class, 'updateKnowledge'])->name('bot.knowledge');
    Route::patch('/bot-studio/widget', [BotStudioController::class, 'updateWidget'])->name('bot.widget');
    Route::patch('/bot-studio/hours', [BotStudioController::class, 'updateHours'])->name('bot.hours');
    Route::patch('/bot-studio/domains', [BotStudioController::class, 'updateDomains'])->name('bot.domains');
    Route::post('/bot-studio/sync',[BotStudioController::class, 'sync'])->middleware('throttle:10,1')->name('bot.sync');

    Route::post('/voices', [VoiceController::class, 'store'])->middleware('throttle:10,1')->name('voices.store');
    Route::post('/voices/{voice}/push', [VoiceController::class, 'push'])->middleware('throttle:10,1')->name('voices.push');
    Route::get('/voices/{voice}/sample', [VoiceController::class, 'sample'])->name('voices.sample');
    Route::delete('/voices/{voice}', [VoiceController::class, 'destroy'])->name('voices.destroy');

    Route::post('/knowledge', [KnowledgeFileController::class, 'store'])->name('knowledge.store');
    Route::delete('/knowledge/{file}', [KnowledgeFileController::class, 'destroy'])->name('knowledge.destroy');

    Route::get('/calls', [CallController::class, 'index'])->name('calls');
    Route::get('/calls/export', [CallController::class, 'export'])->name('calls.export');
    Route::get('/calls/{call}', [CallController::class, 'show'])->name('calls.show');
    Route::post('/test-call/finish', TestCallController::class)->name('test-call.finish');

    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings');
    Route::get('/insights', InsightsController::class)->name('insights');
    Route::patch('/bookings/{booking}', [BookingController::class, 'update'])->name('bookings.update');

    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations');
    Route::post('/integrations/google/connect', [GoogleCalendarController::class, 'connect'])->name('google.connect');
    Route::get('/integrations/google/callback', [GoogleCalendarController::class, 'callback'])->middleware('throttle:10,1')->name('google.callback');
    Route::delete('/integrations/google', [GoogleCalendarController::class, 'disconnect'])->name('google.disconnect');
    Route::get('/integrations/connector.zip', [IntegrationController::class, 'package'])->name('integrations.package');
    Route::post('/integrations', [IntegrationController::class, 'store'])->name('integrations.store');
    Route::put('/integrations/{integration}', [IntegrationController::class, 'update'])->name('integrations.update');
    Route::post('/integrations/{integration}/toggle', [IntegrationController::class, 'toggle'])->name('integrations.toggle');
    Route::post('/integrations/{integration}/secret', [IntegrationController::class, 'rotateSecret'])->name('integrations.secret');
    Route::post('/integrations/{integration}/test', [IntegrationController::class, 'test'])->name('integrations.test');
    Route::delete('/integrations/{integration}', [IntegrationController::class, 'destroy'])->name('integrations.destroy');
    Route::post('/integration-deliveries/{delivery}/retry', [IntegrationController::class, 'retry'])->name('integrations.retry');

    Route::get('/billing', [BillingController::class, 'index'])->name('billing');
    Route::post('/billing/top-ups', [BillingController::class, 'storeTopUp'])->name('top-ups.store');
});
