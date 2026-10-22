<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\KnowledgeFile;
use App\Models\Voice;
use App\Services\VapiService;
use App\Support\CaptureFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BotStudioController extends Controller
{
    public function __construct(private VapiService $vapi) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $bot = $user->bot()->with('knowledgeFiles')->firstOrFail();
        $tabs = ['configure', 'voice', 'knowledge', 'widget', 'embed'];

        return Inertia::render('BotStudio', [
            'tab' => in_array($request->query('tab'), $tabs, true) ? $request->query('tab') : 'configure',
            'bot' => [
                'businessName' => $bot->business_name,
                'greeting' => $bot->greeting,
                'endMessage' => $bot->end_message,
                'language' => $bot->language,
                'prompt' => $bot->prompt,
                'quickFacts' => $bot->quick_facts,
                'captureFields' => $bot->capture_fields ?? [],
                'maxCallMinutes' => $bot->max_call_minutes,
                'voiceId' => $bot->voice_id,
                'presetVoice' => $bot->preset_voice,
                'widget' => $bot->widget,
                'publicKey' => $bot->public_key,
                'installedAt' => $bot->installed_at?->toIso8601String(),
                'syncError' => $bot->sync_error,
                'live' => $this->vapi->isLive($bot),
            ],
            'providers' => ['vapi' => $this->vapi->enabled(), 'elevenlabs' => app(\App\Services\ElevenLabsService::class)->enabled()],
            'voices' => $user->voices()->latest()->get()->map(fn (Voice $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'createdAt' => $v->created_at->toIso8601String(),
                'seconds' => $v->sample_seconds,
                'status' => $v->status,
                'hasSample' => (bool) $v->sample_path,
                // Recorded before ElevenLabs was connected: it gets cloned on ElevenLabs on the next sync.
                'pending' => \App\Services\ElevenLabsService::isSimulatedId($v->provider_voice_id) && app(\App\Services\ElevenLabsService::class)->enabled(),
            ]),
            'files' => $bot->knowledgeFiles->sortByDesc('created_at')->values()->map(fn (KnowledgeFile $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'size' => $f->size_bytes,
                'status' => $f->status,
                'createdAt' => $f->created_at->toIso8601String(),
            ]),
            'options' => [
                'languages' => array_keys(config('hamsukhan.languages')),
                'presetVoices' => config('hamsukhan.preset_voices'),
                'maxVoices' => config('hamsukhan.max_cloned_voices'),
                'minCloneSeconds' => config('hamsukhan.min_clone_seconds'),
                'fieldTypes' => CaptureFields::TYPES,
                'fieldPresets' => CaptureFields::presets(),
            ],
            'embedSrc' => url('/widget.js'),
        ]);
    }

    public function updateConfig(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'greeting' => ['required', 'string', 'max:300'],
            'endMessage' => ['nullable', 'string', 'max:300'],
            'language' => ['required', Rule::in(array_keys(config('hamsukhan.languages')))],
            'prompt' => ['required', 'string', 'max:6000'],
            'businessName' => ['nullable', 'string', 'max:100'],
            'maxCallMinutes' => ['required', 'integer', Rule::in([5, 10, 15])],
        ]);

        return $this->save($request->user()->bot, [
            'greeting' => $data['greeting'],
            'end_message' => $data['endMessage'],
            'language' => $data['language'],
            'prompt' => $data['prompt'],
            'max_call_minutes' => $data['maxCallMinutes'],
            ...($request->has('businessName') ? ['business_name' => $data['businessName']] : []),
        ], 'Bot updated, live on your site');
    }

    public function updateVoice(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'voiceId' => ['nullable', 'integer', Rule::exists('voices', 'id')->where('user_id', $user->id)->where('status', 'ready')],
            'presetVoice' => ['nullable', Rule::in(collect(config('hamsukhan.preset_voices'))->pluck('id'))],
        ]);
        if (empty($data['voiceId']) && empty($data['presetVoice'])) {
            return back()->withErrors(['voiceId' => 'Pick a voice for your bot.']);
        }

        return $this->save($user->bot, [
            'voice_id' => $data['voiceId'] ?? null,
            'preset_voice' => empty($data['voiceId']) ? $data['presetVoice'] : null,
        ], 'Voice saved');
    }

    /** What the bot must collect on every booking/order (sent to Vapi as the structured-data schema). */
    public function updateCaptureFields(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fields' => ['present', 'array', 'max:12'],
            'fields.*.label' => ['required', 'string', 'max:40'],
            'fields.*.key' => ['nullable', 'string', 'max:40'],
            'fields.*.type' => ['required', Rule::in(CaptureFields::TYPES)],
            'fields.*.required' => ['boolean'],
            'fields.*.hint' => ['nullable', 'string', 'max:80'],
        ]);

        return $this->save($request->user()->bot, ['capture_fields' => CaptureFields::normalize($data['fields']) ?: null], 'Fields saved, your bot will ask for them');
    }

    public function updateKnowledge(Request $request): RedirectResponse
    {
        $data = $request->validate(['quickFacts' => ['nullable', 'string', 'max:2000']]);

        return $this->save($request->user()->bot, ['quick_facts' => $data['quickFacts']], 'Knowledge saved');
    }

    public function updateWidget(Request $request): RedirectResponse
    {
        $state = fn ($k) => [
            "states.$k.title" => ['required', 'string', 'max:40'],
            "states.$k.sub" => ['nullable', 'string', 'max:60'],
            "states.$k.color" => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            "states.$k.iconUrl" => ['nullable', 'url', 'max:300'],
        ];
        $data = $request->validate([
            'position' => ['required', Rule::in(['bottom-right', 'bottom-left', 'top-right', 'top-left'])],
            'offset' => ['required', 'integer', 'between:0,120'],
            'width' => ['required', 'integer', 'between:54,400'],
            'height' => ['required', 'integer', 'between:40,120'],
            ...$state('idle'), ...$state('loading'), ...$state('active'), ...$state('error'),
        ]);

        $request->user()->bot->update(['widget' => $data]); // appearance only, no Vapi sync needed

        return back()->with('success', 'Widget saved');
    }

    /** "Retry" button in Bot Studio when the last sync with Vapi failed. */
    public function sync(Request $request): RedirectResponse
    {
        $error = $this->vapi->trySync($request->user()->bot);

        return back()->with($error ? 'error' : 'success', $error ? 'Vapi sync failed: '.$error : 'Bot is live on Vapi');
    }

    private function save(Bot $bot, array $attributes, string $message): RedirectResponse
    {
        $bot->update($attributes);

        if ($error = $this->vapi->trySync($bot->fresh())) {
            return back()->with('error', 'Saved, but Vapi sync failed: '.$error);
        }

        return back()->with('success', $message);
    }
}
