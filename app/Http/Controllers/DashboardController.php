<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $bot = $user->bot()->with('voice')->withCount('knowledgeFiles')->firstOrFail();
        $tz = $user->timezone;

        $today = Carbon::now($tz)->startOfDay();
        $monthStart = Carbon::now($tz)->startOfMonth();
        $lastMonthStart = $monthStart->copy()->subMonth();
        $yesterday = $today->copy()->subDay();

        $calls = $bot->calls()->where('is_test', false);
        $countBetween = fn ($from, $to = null) => (clone $calls)->where('started_at', '>=', $from->utc())
            ->when($to, fn ($q) => $q->where('started_at', '<', $to->copy()->utc()))->count();

        $thisMonth = (clone $calls)->where('started_at', '>=', $monthStart->copy()->utc());
        $monthCount = (clone $thisMonth)->count();
        $lastMonthCount = $countBetween($lastMonthStart->copy(), $monthStart->copy());
        $avgSeconds = (int) round((clone $thisMonth)->where('status', 'completed')->avg('duration_seconds') ?? 0);
        $completedPct = $monthCount ? (int) round((clone $thisMonth)->where('status', 'completed')->count() / $monthCount * 100) : 0;

        // Calls per day for the last 30 days, in the user's timezone.
        $recent = (clone $calls)->where('started_at', '>=', $today->copy()->subDays(29)->utc())->pluck('started_at');
        $byDay = $recent->countBy(fn ($t) => $t->copy()->setTimezone($tz)->toDateString());
        $series = collect(range(29, 0))->map(function ($ago) use ($today, $byDay) {
            $d = $today->copy()->subDays($ago);

            return ['date' => $d->toDateString(), 'count' => $byDay[$d->toDateString()] ?? 0];
        })->values();

        $busiestHour = $recent->countBy(fn ($t) => $t->copy()->setTimezone($tz)->hour)->sortDesc()->keys()->first();

        $intents = (clone $thisMonth)->selectRaw('outcome_tag, count(*) as n')->groupBy('outcome_tag')->orderByDesc('n')->get()
            ->map(fn ($r) => ['tag' => $r->outcome_tag, 'count' => (int) $r->n])->values();

        return Inertia::render('Overview', [
            'stats' => [
                'today' => $countBetween($today->copy()),
                'yesterday' => $countBetween($yesterday, $today),
                'month' => $monthCount,
                'lastMonth' => $lastMonthCount,
                'avgSeconds' => $avgSeconds,
                'completedPct' => $completedPct,
                'bookingsThisMonth' => $bot->bookings()->where('created_at', '>=', $monthStart->copy()->utc())->count(),
                'busiestHour' => $busiestHour,
            ],
            'series' => $series,
            'intents' => $intents,
            'recentCalls' => $bot->calls()->latest('started_at')->limit(5)->get()->map->toRow(),
            'bot' => [
                'greeting' => $bot->greeting,
                'language' => $bot->language,
                'voice' => $bot->voiceLabel(),
                'maxMinutes' => $bot->max_call_minutes,
                'isActive' => $bot->is_active,
                'website' => $bot->website,
                'updatedAt' => $bot->updated_at->toIso8601String(),
            ],
            'setup' => [
                'configure' => $bot->prompt !== '' && ! str_contains($bot->prompt, '[Business name]'),
                'voice' => $bot->voice_id !== null,
                'widget' => $bot->widget !== \App\Models\Bot::defaultWidget(),
                'knowledge' => $bot->knowledge_files_count > 0 || filled($bot->quick_facts),
                'embed' => $bot->installed_at !== null,
            ],
        ]);
    }
}
