<?php

namespace App\Http\Controllers;

use App\Models\Voice;
use App\Services\ElevenLabsService;
use App\Services\VapiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoiceController extends Controller
{
    public function store(Request $request, ElevenLabsService $elevenLabs, VapiService $vapi): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'sample' => ['required', 'file', 'max:20480', 'mimetypes:audio/webm,audio/ogg,audio/wav,audio/x-wav,audio/mpeg,audio/mp4,video/webm'],
            'seconds' => ['required', 'integer', 'min:'.config('hamsukhan.min_clone_seconds'), 'max:300'],
            'consent' => ['accepted'],
        ]);

        if ($user->voices()->count() >= config('hamsukhan.max_cloned_voices')) {
            return back()->withErrors(['name' => 'You can keep up to '.config('hamsukhan.max_cloned_voices').' cloned voices. Delete one first.']);
        }

        $path = $request->file('sample')->store("voices/{$user->id}");

        try {
            $providerId = $elevenLabs->cloneVoice($data['name'], Storage::path($path));
        } catch (\Throwable $e) {
            report($e);
            Storage::delete($path);

            return back()->with('error', 'Voice cloning failed. Please try again with a clearer recording.');
        }

        $voice = $user->voices()->create([
            'name' => $data['name'],
            'provider_voice_id' => $providerId,
            'sample_path' => $path,
            'sample_seconds' => $data['seconds'],
            'status' => 'ready',
            'consent_at' => now(),
        ]);

        // First cloned voice becomes the active one automatically.
        $bot = $user->bot;
        if (! $bot->voice_id) {
            $bot->update(['voice_id' => $voice->id, 'preset_voice' => null]);
            rescue(fn () => $vapi->syncAssistant($bot->fresh()));
        }

        return back()->with('success', 'Voice cloned, ready to use');
    }

    public function sample(Request $request, Voice $voice): StreamedResponse
    {
        abort_unless($voice->user_id === $request->user()->id && $voice->sample_path && Storage::exists($voice->sample_path), 404);

        return Storage::response($voice->sample_path);
    }

    public function destroy(Request $request, Voice $voice, ElevenLabsService $elevenLabs, VapiService $vapi): RedirectResponse
    {
        $user = $request->user();
        abort_unless($voice->user_id === $user->id, 404);

        rescue(fn () => $elevenLabs->deleteVoice($voice->provider_voice_id));
        if ($voice->sample_path) {
            Storage::delete($voice->sample_path);
        }

        $bot = $user->bot;
        $wasActive = $bot->voice_id === $voice->id;
        $voice->delete();

        if ($wasActive) {
            $next = $user->voices()->where('status', 'ready')->latest()->first();
            $bot->update(['voice_id' => $next?->id, 'preset_voice' => $next ? null : config('hamsukhan.preset_voices.0.id')]);
            rescue(fn () => $vapi->syncAssistant($bot->fresh()));
        }

        return back()->with('success', 'Voice deleted');
    }
}
