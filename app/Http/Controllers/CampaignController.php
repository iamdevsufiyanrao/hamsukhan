<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Services\OutboundService;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Outbound campaigns: the bot calls a list of customers who agreed to be called (reminders, follow-ups, offers). */
class CampaignController extends Controller
{
    public const MAX_CONTACTS = 500;

    public function index(Request $request, OutboundService $outbound): Response
    {
        $bot = $request->user()->bot;

        return Inertia::render('Campaigns', [
            'available' => $outbound->available($bot),
            'rate' => (int) round(config('hamsukhan.rate_per_minute') * config('hamsukhan.pkr_per_usd')),
            'campaigns' => $bot->campaigns()->latest()->get()->map(fn (Campaign $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'purpose' => $c->purpose,
                'firstMessage' => $c->first_message,
                'status' => $c->status,
                'perRun' => $c->per_run,
                'createdAt' => $c->created_at->toIso8601String(),
                'counts' => collect(['pending', 'calling', 'done', 'failed', 'skipped'])->mapWithKeys(fn ($s) => [$s => $c->contacts()->where('status', $s)->count()]),
                'failures' => $c->contacts()->where('status', 'failed')->latest('updated_at')->limit(5)->get(['name', 'phone', 'error']),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'purpose' => ['required', 'string', 'max:1000'],
            'firstMessage' => ['required', 'string', 'max:300'],
            'contacts' => ['required', 'string', 'max:40000'],
            'perRun' => ['required', 'integer', 'between:1,10'],
            'consent' => ['accepted'],
        ], ['consent.accepted' => 'Confirm that everyone on the list agreed to be called by your business.']);

        // One contact per line: "Name, 0300 1234567" or just the number.
        $rows = collect(preg_split('/\r?\n/', $data['contacts']))->map(fn ($line) => trim($line))->filter()
            ->map(function ($line) {
                $parts = array_map('trim', preg_split('/[,;\t]/', $line));
                $phone = collect($parts)->map(fn ($p) => Phone::normalize($p))->first(fn ($p) => $p);
                $name = collect($parts)->first(fn ($p) => $p !== '' && ! Phone::normalize($p));

                return $phone ? ['name' => $name ? mb_substr($name, 0, 80) : null, 'phone' => $phone] : null;
            });
        $valid = $rows->filter()->unique('phone')->values();
        if ($valid->isEmpty()) {
            return back()->withErrors(['contacts' => 'No valid phone numbers found. One per line, e.g. "Ayesha, 0300 1234567".']);
        }
        if ($valid->count() > self::MAX_CONTACTS) {
            return back()->withErrors(['contacts' => 'Up to '.self::MAX_CONTACTS.' contacts per campaign.']);
        }

        $campaign = $request->user()->bot->campaigns()->create([
            'name' => $data['name'], 'purpose' => $data['purpose'], 'first_message' => $data['firstMessage'],
            'per_run' => $data['perRun'], 'status' => 'draft', 'consent_confirmed_at' => now(),
        ]);
        $campaign->contacts()->createMany($valid->all());
        $skipped = $rows->count() - $valid->count();

        return back()->with('success', "Campaign saved with {$valid->count()} contacts".($skipped ? ", {$skipped} lines skipped (no valid number or duplicate)" : '').'. Press Start when ready.');
    }

    public function status(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->bot_id === $request->user()->bot->id, 404);
        $data = $request->validate(['status' => ['required', 'in:running,paused']]);
        if ($campaign->status === 'done') {
            return back()->with('error', 'This campaign is finished.');
        }
        $campaign->update(['status' => $data['status']]);

        return back()->with('success', $data['status'] === 'running' ? 'Campaign started. Calls go out during your opening hours, a few at a time.' : 'Campaign paused');
    }

    public function destroy(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->bot_id === $request->user()->bot->id, 404);
        $campaign->delete();

        return back()->with('success', 'Campaign deleted');
    }
}
