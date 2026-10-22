<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\GoogleCalendar;
use App\Models\User;
use App\Support\ProviderError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Calendar for one owner: OAuth connect, busy times (free/busy API) and booking events.
 * Needs a Google Cloud OAuth client (web application) whose redirect URI is route('google.callback').
 */
class GoogleCalendarService
{
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/calendar/v3';

    public const SCOPES = 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy';

    public function enabled(): bool
    {
        return filled(config('hamsukhan.google.client_id')) && filled(config('hamsukhan.google.client_secret'));
    }

    public function authUrl(string $state): string
    {
        return self::AUTH.'?'.http_build_query([
            'client_id' => config('hamsukhan.google.client_id'),
            'redirect_uri' => route('google.callback'),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent', // always returns a refresh token
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /** Finishes the OAuth flow and stores the tokens for this owner. */
    public function connect(User $user, string $code): GoogleCalendar
    {
        try {
            $token = Http::asForm()->timeout(15)->post(self::TOKEN, [
                'code' => $code,
                'client_id' => config('hamsukhan.google.client_id'),
                'client_secret' => config('hamsukhan.google.client_secret'),
                'redirect_uri' => route('google.callback'),
                'grant_type' => 'authorization_code',
            ])->throw()->json();
            $email = Http::withToken($token['access_token'])->timeout(10)->get('https://openidconnect.googleapis.com/v1/userinfo')->json('email');
        } catch (\Throwable $e) {
            throw new RuntimeException(ProviderError::message($e, 'Google'), 0, $e);
        }
        if (empty($token['refresh_token'])) {
            throw new RuntimeException('Google did not give offline access. Remove Hamsukhan from your Google account permissions and connect again.');
        }
        if (! str_contains((string) ($token['scope'] ?? ''), 'calendar')) {
            throw new RuntimeException('Calendar access was not allowed. Connect again and tick the calendar permissions.');
        }

        return $user->googleCalendar()->updateOrCreate([], [
            'google_email' => $email,
            'calendar_id' => 'primary',
            'refresh_token' => $token['refresh_token'],
            'access_token' => $token['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600) - 60),
            'last_error' => null,
        ]);
    }

    public function disconnect(GoogleCalendar $cal): void
    {
        rescue(fn () => Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $cal->refresh_token]), report: false);
        $cal->delete();
    }

    /**
     * Busy periods between two moments.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    public function busy(GoogleCalendar $cal, Carbon $from, Carbon $to): array
    {
        $res = $this->call($cal, fn ($http) => $http->post(self::API.'/freeBusy', [
            'timeMin' => $from->copy()->utc()->toIso8601ZuluString(),
            'timeMax' => $to->copy()->utc()->toIso8601ZuluString(),
            'items' => [['id' => $cal->calendar_id]],
        ]));

        return collect($res['calendars'][$cal->calendar_id]['busy'] ?? [])
            ->map(fn ($b) => [Carbon::parse($b['start']), Carbon::parse($b['end'])])->all();
    }

    /** @return string the Google event id */
    public function createEvent(GoogleCalendar $cal, Booking $booking, string $timezone): string
    {
        $description = implode("\n", array_filter([
            'Booked by your Hamsukhan AI receptionist.',
            "Customer: {$booking->customer_name}",
            $booking->phone ? "Phone: {$booking->phone}" : null,
            "Request: {$booking->request}",
            ...collect($booking->details ?? [])->map(fn ($v, $k) => str($k)->headline().': '.(is_array($v) ? implode(', ', $v) : $v))->values()->all(),
        ]));

        $event = $this->call($cal, fn ($http) => $http->post(self::API.'/calendars/'.rawurlencode($cal->calendar_id).'/events', [
            'summary' => mb_strimwidth("{$booking->request} · {$booking->customer_name}", 0, 120, '…'),
            'description' => $description,
            'start' => ['dateTime' => $booking->starts_at->copy()->setTimezone($timezone)->toIso8601String(), 'timeZone' => $timezone],
            'end' => ['dateTime' => $booking->ends_at->copy()->setTimezone($timezone)->toIso8601String(), 'timeZone' => $timezone],
            'reminders' => ['useDefault' => true],
        ]));

        return (string) $event['id'];
    }

    public function deleteEvent(GoogleCalendar $cal, string $eventId): void
    {
        $this->call($cal, fn ($http) => $http->delete(self::API.'/calendars/'.rawurlencode($cal->calendar_id).'/events/'.rawurlencode($eventId)), allow404: true);
    }

    /** Runs a request with a fresh access token; saves the error on the calendar so the owner sees it. */
    private function call(GoogleCalendar $cal, callable $request, bool $allow404 = false): array
    {
        try {
            $response = $request(Http::withToken($this->accessToken($cal))->acceptJson()->timeout(8));
            if ($allow404 && in_array($response->status(), [404, 410], true)) {
                return [];
            }
            $json = $response->throw()->json() ?? [];
        } catch (\Throwable $e) {
            $msg = $e instanceof RuntimeException ? $e->getMessage() : ProviderError::message($e, 'Google Calendar');
            $cal->forceFill(['last_error' => mb_strimwidth($msg, 0, 400, '…')])->save();
            throw new RuntimeException($msg, 0, $e);
        }
        if ($cal->last_error) {
            $cal->forceFill(['last_error' => null])->save();
        }

        return $json;
    }

    private function accessToken(GoogleCalendar $cal): string
    {
        if ($cal->access_token && $cal->token_expires_at?->isFuture()) {
            return $cal->access_token;
        }
        try {
            $token = Http::asForm()->timeout(10)->post(self::TOKEN, [
                'client_id' => config('hamsukhan.google.client_id'),
                'client_secret' => config('hamsukhan.google.client_secret'),
                'refresh_token' => $cal->refresh_token,
                'grant_type' => 'refresh_token',
            ])->throw()->json();
        } catch (\Throwable $e) {
            throw new RuntimeException('Google Calendar access expired or was removed. Connect it again in Integrations. ('.ProviderError::message($e, 'Google').')', 0, $e);
        }
        $cal->forceFill(['access_token' => $token['access_token'], 'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600) - 60)])->save();

        return $token['access_token'];
    }
}
