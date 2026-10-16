<?php

namespace App\Http\Controllers;

use App\Services\ElevenLabsService;
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
        return Inertia::render('Settings', [
            'profile' => ['name' => $request->user()->name, 'email' => $request->user()->email, 'timezone' => $request->user()->timezone],
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
