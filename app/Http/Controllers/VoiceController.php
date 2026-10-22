<?php

namespace App\Http\Controllers;

use App\Models\Voice;
use App\Services\ElevenLabsService;
use App\Services\VapiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoiceController extends Controller
{
    public function store(Request $request, ElevenLabsService $elevenLabs, VapiService $vapi): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            // The in-browser recorder sends WAV. Safari/iPhone files are MP4/M4A (often detected as video/mp4); phone voice notes are M4A, AAC or OGG/Opus.
            // MediaRecorder WebM without a duration header is sometimes detected as application/octet-stream, so the extension is checked too.
            'sample' => ['required', 'file', 'max:20480', 'extensions:wav,webm,weba,ogg,oga,opus,mp3,m4a,mp4,aac,flac', 'mimetypes:audio/webm,audio/ogg,audio/opus,audio/wav,audio/x-wav,audio/wave,audio/vnd.wave,audio/mpeg,audio/mp4,audio/x-m4a,audio/aac,audio/x-hx-aac-adts,audio/flac,audio/x-flac,video/webm,video/mp4,video/ogg,application/octet-stream'],
            'seconds' => ['required', 'integer', 'min:'.config('hamsukhan.min_clone_seconds'), 'max:300'],
            'consent' => ['accepted'],
        ], [
            'sample.max' => 'The recording is bigger than 20 MB. Use a clip of 1 to 2 minutes.',
            'sample.uploaded' => 'The recording did not upload (the server limit may be too low). Try a shorter clip.',
        ]);

        if ($user->voices()->count() >= config('hamsukhan.max_cloned_voices')) {
            return back()->withErrors(['name' => 'You can keep up to '.config('hamsukhan.max_cloned_voices').' cloned voices. Delete one first.']);
        }

        $file = $request->file('sample');
        $path = $file->storeAs("voices/{$user->id}", \Illuminate\Support\Str::random(32).'.'.strtolower($file->getClientOriginalExtension() ?: 'wav'));

        try {
            $providerId = $elevenLabs->cloneVoice($data['name'], Storage::path($path));
        } catch (\Throwable $e) {
            report($e);
            Storage::delete($path);

            return back()->with('error', 'Voice cloning failed: '.$e->getMessage());
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
            if ($error = $vapi->trySync($bot->fresh())) {
                return back()->with('error', 'Voice cloned, but Vapi sync failed: '.$error);
            }
        }

        return back()->with('success', $elevenLabs->enabled() ? 'Voice cloned on ElevenLabs, ready to use' : 'Voice saved (demo mode: ElevenLabs is not connected yet)');
    }

    /** Sends a voice that was recorded in demo mode (sim_ id) to ElevenLabs now. */
    public function push(Request $request, Voice $voice, ElevenLabsService $elevenLabs, VapiService $vapi): RedirectResponse
    {
        $user = $request->user();
        abort_unless($voice->user_id === $user->id, 404);
        abort_unless($elevenLabs->enabled(), 409, 'ElevenLabs is not connected.');

        try {
            $elevenLabs->ensureCloned($voice);
        } catch (RuntimeException $e) {
            report($e);

            return back()->with('error', 'Voice cloning failed: '.$e->getMessage());
        }

        if ($user->bot->voice_id === $voice->id && ($error = $vapi->trySync($user->bot->fresh()))) {
            return back()->with('error', 'Voice cloned, but Vapi sync failed: '.$error);
        }

        return back()->with('success', 'Voice uploaded to ElevenLabs');
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

        // Voices the super admin imported from the shared ElevenLabs account are only unlinked, never deleted there.
        if ($voice->provider === 'elevenlabs') {
            rescue(fn () => $elevenLabs->deleteVoice($voice->provider_voice_id));
        }
        if ($voice->sample_path) {
            Storage::delete($voice->sample_path);
        }

        $bot = $user->bot;
        $wasActive = $bot->voice_id === $voice->id;
        $voice->delete();

        if ($wasActive) {
            $next = $user->voices()->where('status', 'ready')->latest()->first();
            $bot->update(['voice_id' => $next?->id, 'preset_voice' => $next ? null : config('hamsukhan.preset_voices.0.id')]);
            $vapi->trySync($bot->fresh());
        }

        return back()->with('success', 'Voice deleted');
    }
}
