<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\BotProvisioner;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function showLogin(): Response
    {
        return Inertia::render('Auth/Login', ['status' => session('status')]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if ($request->user()->isSuspended()) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'This account is suspended. Contact support.']);
        }

        $request->session()->regenerate();

        return redirect()->intended($request->user()->is_admin ? route('admin.overview') : route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function showRegister(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function register(Request $request, BotProvisioner $provisioner): RedirectResponse
    {
        abort_unless(config('hamsukhan.site.allow_signups'), 403, 'Sign-ups are closed.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            'terms' => ['accepted'],
        ]);

        $user = DB::transaction(function () use ($data, $provisioner) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'terms_accepted_at' => now(),
            ]);
            $provisioner->provision($user);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Welcome to Hamsukhan! Start by writing your bot instructions.');
    }

    public function showForgot(): Response
    {
        return Inertia::render('Auth/ForgotPassword', ['status' => session('status')]);
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        // Same answer whether or not the email exists, so this can't be used to discover accounts.
        return back()->with('status', 'If an account exists for that email, a reset link is on its way.');
    }

    public function showReset(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => $request->query('email', '')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('login')->with('status', 'Password updated, you can log in now.');
    }
}
