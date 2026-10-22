<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Two-step login with an authenticator app, recovery codes, the login challenge,
 * and "log out my other devices".
 */
class TwoFactorController extends Controller
{
    private const CHALLENGE = 'two_factor.login';

    /* ---------- settings (signed in) ---------- */

    public function enable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $request->user()->forceFill(['two_factor_secret' => Totp::newSecret(), 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();

        return back()->with('success', 'Scan the QR code with your authenticator app, then enter the 6-digit code.');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();
        if (! $user->two_factor_secret || ! Totp::verify($user->two_factor_secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Check the time on your phone and try the newest code.']);
        }
        $codes = $this->newCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();

        return back()->with('success', 'Two-step login is on.')->with('recoveryCodes', $codes);
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        abort_unless($request->user()->hasTwoFactor(), 409);
        $codes = $this->newCodes();
        $request->user()->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();

        return back()->with('success', 'New recovery codes made, the old ones no longer work.')->with('recoveryCodes', $codes);
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();

        return back()->with('success', 'Two-step login is off.');
    }

    /** Ends every other session of this account (and invalidates "remember me" cookies). */
    public function logoutOthers(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $user = $request->user();
        $n = config('session.driver') === 'database'
            ? DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete()
            : 0;
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        return back()->with('success', $n ? "Logged out of {$n} other ".($n === 1 ? 'device' : 'devices') : 'No other devices were signed in');
    }

    /** Signed-in devices for the Settings page. */
    public static function sessions(Request $request): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return DB::table(config('session.table', 'sessions'))->where('user_id', $request->user()->id)->orderByDesc('last_activity')->limit(10)->get()
            ->map(fn ($s) => [
                'current' => $s->id === $request->session()->getId(),
                'ip' => $s->ip_address,
                'device' => self::device((string) $s->user_agent),
                'lastActive' => \Illuminate\Support\Carbon::createFromTimestamp($s->last_activity)->toIso8601String(),
            ])->all();
    }

    /* ---------- login challenge (guest) ---------- */

    /** Called by AuthController after a correct password for an account with two-step login. */
    public static function startChallenge(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::logout();
        $request->session()->put(self::CHALLENGE, ['id' => $user->id, 'remember' => $remember, 'at' => now()->getTimestamp()]);

        return redirect()->route('two-factor.challenge');
    }

    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $this->pending($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);
        if (! $pending) {
            return redirect()->route('login')->with('status', 'That took too long, please log in again.');
        }
        $data = $request->validate(['code' => ['nullable', 'string', 'max:20'], 'recovery_code' => ['nullable', 'string', 'max:40']]);
        $user = User::find($pending['id']);
        if (! $user || ! $user->hasTwoFactor()) {
            return redirect()->route('login');
        }

        $ok = false;
        if (filled($data['code'] ?? null)) {
            $ok = Totp::verify($user->two_factor_secret, $data['code']);
        } elseif (filled($data['recovery_code'] ?? null)) {
            $hash = hash('sha256', strtolower(trim($data['recovery_code'])));
            $codes = $user->two_factor_recovery_codes ?? [];
            if (in_array($hash, $codes, true)) {
                $ok = true;
                $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$hash]))])->save(); // single use
            }
        }
        if (! $ok) {
            throw ValidationException::withMessages(['code' => 'That code is not right.']);
        }

        $request->session()->forget(self::CHALLENGE);
        Auth::login($user, $pending['remember']);
        $request->session()->regenerate();

        return redirect()->intended($user->is_admin ? route('admin.overview') : route('dashboard'));
    }

    private function pending(Request $request): ?array
    {
        $p = $request->session()->get(self::CHALLENGE);

        return is_array($p) && now()->getTimestamp() - ($p['at'] ?? 0) < 600 ? $p : null;
    }

    /** @return list<string> */
    private function newCodes(): array
    {
        return array_map(fn () => strtolower(Str::random(5).'-'.Str::random(5)), range(1, 8));
    }

    private static function device(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone/iPad',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'unknown device',
        };

        return "{$browser} on {$os}";
    }
}
