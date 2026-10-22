<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Owner connects / disconnects their Google Calendar (OAuth). */
class GoogleCalendarController extends Controller
{
    public function connect(Request $request, GoogleCalendarService $google): Response
    {
        abort_unless($google->enabled(), 409, 'Google Calendar is not switched on for the platform yet.');
        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        // Full-page redirect to Google, also when the click came through Inertia.
        return \Inertia\Inertia::location($google->authUrl($state));
    }

    public function callback(Request $request, GoogleCalendarService $google): RedirectResponse
    {
        $expected = $request->session()->pull('google_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('integrations')->with('error', 'The Google sign-in expired. Please press Connect again.');
        }
        if ($request->query('error') || ! $request->query('code')) {
            return redirect()->route('integrations')->with('error', 'Google Calendar was not connected (permission was not given).');
        }

        try {
            $cal = $google->connect($request->user(), (string) $request->query('code'));
        } catch (RuntimeException $e) {
            report($e);

            return redirect()->route('integrations')->with('error', 'Google Calendar could not be connected: '.$e->getMessage());
        }

        return redirect()->route('integrations')->with('success', "Google Calendar connected ({$cal->google_email}). New bookings appear in it and busy times are never offered.");
    }

    public function disconnect(Request $request, GoogleCalendarService $google): RedirectResponse
    {
        if ($cal = $request->user()->googleCalendar) {
            $google->disconnect($cal);
        }

        return back()->with('success', 'Google Calendar disconnected');
    }
}
