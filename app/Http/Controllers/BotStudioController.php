<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\KnowledgeFile;
use App\Models\KnowledgeVersion;
use App\Models\Voice;
use App\Services\VapiService;
use App\Support\BusinessHours;
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
        $user = $request->user()->account();
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
                'hours' => $bot->business_hours,
                'holidays' => $bot->holidays ?? [],
                'allowedDomains' => $bot->allowed_domains ?? [],
                'liveBooking' => $bot->live_booking,
                'slotMinutes' => $bot->slot_minutes,
                'calendarConnected' => (bool) $user->googleCalendar,
                'transferNumber' => $bot->transfer_number,
                'reminderHours' => $bot->reminder_hours,
                'phoneNumber' => $bot->phone_number,
                'outboundAvailable' => app(\App\Services\OutboundService::class)->available($bot),
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
                // ElevenLabs premade voices with a preview to listen to; empty when ElevenLabs is not connected.
                'library' => rescue(fn () => app(\App\Services\ElevenLabsService::class)->libraryVoices(), [], report: false),
                'canClone' => app(\App\Services\ElevenLabsService::class)->canClone(),
                'maxVoices' => config('hamsukhan.max_cloned_voices'),
                'minCloneSeconds' => config('hamsukhan.min_clone_seconds'),
                'fieldTypes' => CaptureFields::TYPES,
                'fieldPresets' => CaptureFields::presets(),
            ],
            'embedSrc' => url('/widget.js'),
            'versions' => $bot->knowledgeVersions()->with('user:id,name')->latest('id')->limit(20)->get()->map(fn (KnowledgeVersion $v) => [
                'id' => $v->id,
                'at' => $v->created_at->toIso8601String(),
                'by' => $v->user?->name,
                'note' => $v->note,
                'greeting' => $v->greeting,
                'prompt' => $v->prompt,
                'quickFacts' => $v->quick_facts,
            ]),
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
            'end_message' => $data['endMessage'] ?? null,
            'language' => $data['language'],
            'prompt' => $data['prompt'],
            'max_call_minutes' => $data['maxCallMinutes'],
            ...($request->has('businessName') ? ['business_name' => $data['businessName']] : []),
        ], 'Bot updated, live on your site');
    }

    public function updateVoice(Request $request): RedirectResponse
    {
        $user = $request->user()->account();
        $data = $request->validate([
            'voiceId' => ['nullable', 'integer', Rule::exists('voices', 'id')->where('user_id', $user->id)->where('status', 'ready')],
            // A preset id, or "el:<voice id>" for a voice from the ElevenLabs library (premade voices only).
            'presetVoice' => ['nullable', 'string', 'max:80', function ($attr, $value, $fail) {
                $ok = collect(config('hamsukhan.preset_voices'))->pluck('id')->contains($value)
                    || (str_starts_with($value, 'el:') && collect(app(\App\Services\ElevenLabsService::class)->libraryVoices())->contains('id', substr($value, 3)));
                $ok || $fail('That voice is not available.');
            }],
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

    /** Weekly opening hours + holiday closures (sent to the bot in its prompt). */
    public function updateHours(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'hours' => ['present', 'array'],
            'holidays' => ['present', 'array', 'max:60'],
            'holidays.*.date' => ['required', 'date_format:Y-m-d'],
            'holidays.*.note' => ['nullable', 'string', 'max:60'],
            'liveBooking' => ['boolean'],
            'slotMinutes' => ['nullable', 'integer', Rule::in([15, 20, 30, 45, 60, 90, 120])],
        ]);
        $hours = BusinessHours::normalize($data['hours']);
        if ($request->boolean('liveBooking') && ! $hours) {
            return back()->withErrors(['liveBooking' => 'Set your opening hours first, the bot books only inside them.']);
        }

        return $this->save($request->user()->bot, [
            'business_hours' => $hours,
            'holidays' => BusinessHours::normalizeHolidays($data['holidays']),
            'live_booking' => $request->boolean('liveBooking'),
            'slot_minutes' => $data['slotMinutes'] ?? 30,
        ], 'Opening hours saved, your bot knows them now');
    }

    /** Transfer-to-a-person number and appointment reminder calls. */
    public function updatePhone(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'transferNumber' => ['nullable', 'string', 'max:30'],
            'reminderHours' => ['nullable', 'integer', Rule::in([2, 4, 24])],
        ]);
        $transfer = null;
        if (filled($data['transferNumber'] ?? null)) {
            $transfer = \App\Support\Phone::normalize($data['transferNumber']);
            if (! $transfer) {
                return back()->withErrors(['transferNumber' => 'Enter a valid number, e.g. 0300 1234567.']);
            }
        }

        return $this->save($request->user()->bot, ['transfer_number' => $transfer, 'reminder_hours' => $data['reminderHours'] ?? null], 'Phone settings saved');
    }

    /** Websites allowed to show the widget (empty = any). */
    public function updateDomains(Request $request): RedirectResponse
    {
        $data = $request->validate(['domains' => ['present', 'array', 'max:10'], 'domains.*' => ['nullable', 'string', 'max:120']]);

        $domains = [];
        foreach ($data['domains'] as $raw) {
            $host = strtolower(trim((string) $raw));
            if ($host === '') {
                continue;
            }
            $host = (string) (parse_url(str_contains($host, '://') ? $host : "https://{$host}", PHP_URL_HOST) ?: '');
            $host = preg_replace('/^www\./', '', $host);
            if (! preg_match('/^(?=.{3,120}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $host)) {
                return back()->withErrors(['domains' => "\"{$raw}\" is not a valid domain, e.g. mysalon.pk"]);
            }
            $domains[] = $host;
        }

        $request->user()->bot->update(['allowed_domains' => array_values(array_unique($domains)) ?: null]); // widget-side only, no Vapi sync

        return back()->with('success', $domains ? 'Allowed websites saved' : 'Widget allowed on any website');
    }

    /** "Retry" button in Bot Studio when the last sync with Vapi failed. */
    public function sync(Request $request): RedirectResponse
    {
        $error = $this->vapi->trySync($request->user()->bot);

        return back()->with($error ? 'error' : 'success', $error ? 'Vapi sync failed: '.$error : 'Bot is live on Vapi');
    }

    /** Puts an earlier greeting / instructions / quick facts back (the current ones are kept in history too). */
    public function restoreVersion(Request $request, KnowledgeVersion $version): RedirectResponse
    {
        $bot = $request->user()->bot;
        abort_unless($version->bot_id === $bot->id, 404);

        return $this->save($bot, [
            'greeting' => $version->greeting ?? $bot->greeting,
            'prompt' => $version->prompt ?? $bot->prompt,
            'quick_facts' => $version->quick_facts,
        ], 'Earlier version restored', 'Before restoring the version of '.$version->created_at->format('j M, g:i A'));
    }

    private function save(Bot $bot, array $attributes, string $message, ?string $note = null): RedirectResponse
    {
        // History: keep what the bot knew before any change to its greeting, instructions or facts (last 50).
        $tracked = ['greeting', 'prompt', 'quick_facts'];
        if (collect($tracked)->contains(fn ($k) => array_key_exists($k, $attributes) && (string) $attributes[$k] !== (string) $bot->{$k})) {
            $bot->knowledgeVersions()->create(['user_id' => request()->user()?->id, 'greeting' => $bot->greeting, 'prompt' => $bot->prompt, 'quick_facts' => $bot->quick_facts, 'note' => $note]);
            $keep = $bot->knowledgeVersions()->latest('id')->limit(50)->pluck('id');
            $bot->knowledgeVersions()->whereNotIn('id', $keep)->delete();
        }
        $bot->update($attributes);

        if ($error = $this->vapi->trySync($bot->fresh())) {
            return back()->with('error', 'Saved, but Vapi sync failed: '.$error);
        }

        return back()->with('success', $message);
    }
}
