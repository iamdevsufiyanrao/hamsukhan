<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\User;
use App\Models\Voice;
use App\Services\ElevenLabsService;
use App\Services\VapiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Super admin: voices that already exist in the ElevenLabs account (cloned in the ElevenLabs dashboard, or premade)
 * and assigning one to a customer account. Customers never see this list: it holds every customer's cloned voices.
 */
class ProviderVoiceController extends Controller
{
    public function index(ElevenLabsService $elevenLabs): Response
    {
        $error = null;
        $voices = [];
        try {
            $voices = $elevenLabs->listVoices();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        $linked = Voice::with('user:id,name,email')->whereIn('provider_voice_id', array_column($voices, 'id'))->get()->groupBy('provider_voice_id');

        return Inertia::render('Admin/ProviderVoices', [
            'enabled' => $elevenLabs->enabled(),
            'error' => $error,
            'voices' => collect($voices)->map(fn ($v) => $v + [
                'usedBy' => ($linked[$v['id']] ?? collect())->map(fn (Voice $l) => ['id' => $l->user->id, 'name' => $l->user->name, 'email' => $l->user->email])->values(),
            ]),
            'accounts' => User::where('is_admin', false)->whereHas('bot')->orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'label' => "{$u->name} · {$u->email}"]),
        ]);
    }

    public function assign(Request $request, ElevenLabsService $elevenLabs, VapiService $vapi): RedirectResponse
    {
        $data = $request->validate([
            'voiceId' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
            'name' => ['required', 'string', 'max:60'],
            'userId' => ['required', 'integer', Rule::exists('users', 'id')->where('is_admin', false)],
            'activate' => ['boolean'],
        ]);
        $user = User::with('bot')->findOrFail($data['userId']);
        abort_unless($user->bot, 422, 'That account has no bot yet.');

        // Only voices that really exist in our ElevenLabs account can be assigned.
        try {
            $exists = collect($elevenLabs->listVoices())->contains('id', $data['voiceId']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        if (! $exists) {
            return back()->with('error', 'That voice is not in the ElevenLabs account.');
        }

        $voice = $user->voices()->firstOrCreate(
            ['provider_voice_id' => $data['voiceId']],
            ['name' => $data['name'], 'provider' => 'elevenlabs_import', 'status' => 'ready', 'consent_at' => now()],
        );
        AdminAction::record($request->user(), $user, 'assign_voice', ['voice' => $data['name'], 'provider_voice_id' => $data['voiceId']]);

        if ($request->boolean('activate')) {
            $user->bot->update(['voice_id' => $voice->id, 'preset_voice' => null]);
            if ($error = $vapi->trySync($user->bot->fresh())) {
                return back()->with('error', 'Voice assigned, but Vapi sync failed: '.$error);
            }
        }

        return back()->with('success', "Voice \"{$data['name']}\" assigned to {$user->name}");
    }
}
