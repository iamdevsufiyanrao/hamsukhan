<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the platform owner when the app throws in production. The same error (class + file + line)
 * is mailed at most once per 30 minutes, so an outage can't flood the inbox. Never throws itself.
 */
class ErrorAlert
{
    public static function report(Throwable $e): void
    {
        $to = config('hamsukhan.site.alert_email') ?: config('hamsukhan.site.support_email');
        if (! $to || ! app()->isProduction() || config('mail.default') === 'log' || self::ignored($e)) {
            return;
        }

        try {
            $key = 'err-alert:'.md5(get_class($e).$e->getFile().$e->getLine());
            if (! Cache::add($key, 1, now()->addMinutes(30))) {
                return;
            }
            $request = app()->runningInConsole() ? null : request();
            $body = implode("\n", array_filter([
                get_class($e).': '.mb_strimwidth($e->getMessage(), 0, 500, '…'),
                'at '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $e->getFile()).':'.$e->getLine(),
                $request ? $request->method().' '.$request->path() : 'console',
                $request ? 'Request id: '.$request->attributes->get('request_id') : null,
                'Time: '.now()->toDateTimeString().' UTC',
                '',
                'Same error is muted for 30 minutes. Full trace in storage/logs/laravel.log.',
            ]));
            Mail::raw($body, fn ($m) => $m->to($to)->subject('['.config('hamsukhan.site.name').'] Error: '.mb_strimwidth(class_basename($e).' '.$e->getMessage(), 0, 90, '…')));
        } catch (Throwable) {
            // alerting must never cause a second failure
        }
    }

    private static function ignored(Throwable $e): bool
    {
        return $e instanceof \Illuminate\Validation\ValidationException
            || $e instanceof \Illuminate\Auth\AuthenticationException
            || $e instanceof \Illuminate\Session\TokenMismatchException
            || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
            || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException;
    }
}
