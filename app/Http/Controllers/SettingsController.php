<?php

namespace App\Http\Controllers;

use App\Services\ElevenLabsService;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings', [
            'profile' => ['name' => $user->name, 'email' => $user->email, 'timezone' => $user->timezone],
            'twoFactor' => [
                'enabled' => $user->hasTwoFactor(),
                // Shown only while setting up (secret saved, not confirmed yet).
                'setup' => $user->two_factor_secret && ! $user->two_factor_confirmed_at
                    ? ['secret' => $user->two_factor_secret, 'uri' => Totp::uri($user->two_factor_secret, $user->email, config('hamsukhan.site.name'))]
                    : null,
                'codesLeft' => count($user->two_factor_recovery_codes ?? []),
                'recoveryCodes' => session('recoveryCodes'),
            ],
            'sessions' => TwoFactorController::sessions($request),
            'team' => ! $user->isMember() && ! $user->is_admin && $user->bot ? [
                'max' => TeamController::MAX_MEMBERS,
                'members' => $user->members()->orderBy('name')->get()->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'role' => $m->team_role]),
            ] : null,
            'isMember' => $user->isMember(),
            'notifications' => [
                'email' => $user->notify_email,
                'whatsapp' => $user->notify_whatsapp,
                'whatsappNumber' => $user->whatsapp_number,
                'dailySummary' => $user->notify_daily_summary,
                'monthlyCapPkr' => $user->monthly_cap !== null ? (int) round((float) $user->monthly_cap * config('hamsukhan.pkr_per_usd')) : null,
                'spentThisMonthPkr' => (int) round(app(\App\Services\WalletService::class)->spentThisMonth($user) * config('hamsukhan.pkr_per_usd')),
                'whatsappAvailable' => app(\App\Services\WhatsAppService::class)->enabled(),
                'recent' => $user->hasMany(\App\Models\NotificationLog::class)->latest()->limit(8)->get()
                    ->map(fn ($n) => ['kind' => $n->kind, 'channel' => $n->channel, 'status' => $n->status, 'error' => $n->error, 'at' => $n->created_at->toIso8601String()]),
            ],
            'timezones' => ['Asia/Karachi', 'Asia/Dubai', 'Asia/Riyadh', 'Europe/London', 'America/New_York', 'UTC'],
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'timezone' => ['required', 'timezone'],
        ]);

        $emailChanged = $data['email'] !== $user->email;
        $user->fill($data);
        if ($emailChanged) {
            $user->email_verified_at = null;
        }
        $user->save();

        return back()->with('success', $emailChanged ? 'Profile saved, please verify your new email' : 'Profile saved');
    }

    /** Lead / balance / summary alerts and the monthly spending limit (entered in PKR, stored in the wallet's USD). */
    public function updateNotifications(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['boolean'],
            'whatsapp' => ['boolean'],
            'whatsappNumber' => ['nullable', 'string', 'max:30', 'required_if:whatsapp,true'],
            'dailySummary' => ['boolean'],
            'monthlyCapPkr' => ['nullable', 'integer', 'min:500', 'max:10000000'],
        ], ['whatsappNumber.required_if' => 'Enter the WhatsApp number that should get the alerts.']);

        $number = null;
        if (filled($data['whatsappNumber'] ?? null)) {
            $number = \App\Support\Phone::normalize($data['whatsappNumber']);
            if (! $number) {
                return back()->withErrors(['whatsappNumber' => 'Enter a valid number, e.g. 0300 1234567.']);
            }
        }

        $request->user()->update([
            'notify_email' => $request->boolean('email'),
            'notify_whatsapp' => $request->boolean('whatsapp'),
            'whatsapp_number' => $number,
            'notify_daily_summary' => $request->boolean('dailySummary'),
            'monthly_cap' => isset($data['monthlyCapPkr']) ? round($data['monthlyCapPkr'] / config('hamsukhan.pkr_per_usd'), 2) : null,
        ]);

        return back()->with('success', 'Notification settings saved');
    }

    /** Sends a test alert on every channel the owner switched on. */
    public function testNotification(Request $request, \App\Services\WhatsAppService $whatsapp): RedirectResponse
    {
        $user = $request->user();
        $sent = [];
        try {
            if ($user->notify_email) {
                \Illuminate\Support\Facades\Mail::raw("This is a test alert from {$user->bot?->business_name}. New leads from your website will look like this.", fn ($m) => $m->to($user->email)->subject('Hamsukhan test alert'));
                $sent[] = 'email';
            }
            if ($user->notify_whatsapp && $user->whatsapp_number) {
                abort_unless($whatsapp->enabled(), 409, 'WhatsApp alerts are not switched on for the platform yet.');
                $whatsapp->send($user->whatsapp_number, 'Hamsukhan test alert: new leads from your website will arrive here.');
                $sent[] = 'WhatsApp';
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Test alert failed: '.mb_strimwidth($e->getMessage(), 0, 200, '…'));
        }

        return back()->with($sent ? 'success' : 'error', $sent ? 'Test alert sent by '.implode(' and ', $sent) : 'Switch on email or WhatsApp alerts first.');
    }

    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:2048']]);
        $user = $request->user();
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }
        $user->update(['avatar_path' => $request->file('avatar')->store('avatars', 'public')]);

        return back()->with('success', 'Photo updated');
    }

    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return back()->with('success', 'Photo removed');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $request->user()->update(['password' => $data['password']]);

        return back()->with('success', 'Password updated');
    }

    public function destroy(Request $request, ElevenLabsService $elevenLabs): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'in:DELETE'], 'password' => ['required', 'current_password']]);
        $user = $request->user();
        if ($user->isMember()) {
            // A team member only removes their own login, never the owner's bot or data.
            Auth::logout();
            $user->delete();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Your team login was deleted.');
        }

        foreach ($user->voices as $voice) {
            rescue(fn () => $elevenLabs->deleteVoice($voice->provider_voice_id));
        }
        Storage::deleteDirectory("voices/{$user->id}");
        Storage::deleteDirectory("top-ups/{$user->id}");
        if ($user->bot) {
            Storage::deleteDirectory("knowledge/{$user->bot->id}");
        }
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        Auth::logout();
        $user->delete(); // cascades to bot, calls, bookings, voices, wallet ledger
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your account has been deleted.');
    }
}
