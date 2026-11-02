<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Call;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Conversion funnel, after-hours share, plan cost per lead and the knowledge-gap list (questions the bot could not answer). */
class InsightsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $bot = $user->bot;
        $days = in_array((int) $request->query('days'), [7, 30, 90], true) ? (int) $request->query('days') : 30;
        $from = now($user->timezone)->subDays($days - 1)->startOfDay()->utc();

        $calls = $bot->calls()->where('is_test', false)->where('started_at', '>=', $from);
        $total = (clone $calls)->count();
        $completed = (clone $calls)->where('status', 'completed')->count();
        $afterHours = (clone $calls)->where('after_hours', true)->count();

        $leads = $bot->bookings()->where('created_at', '>=', $from);
        $leadCount = (clone $leads)->count();
        $fromCalls = (clone $leads)->whereNotNull('call_id')->count();
        $won = (clone $leads)->whereIn('status', ['confirmed', 'done'])->count();
        $afterHoursLeads = (clone $leads)->whereHas('call', fn ($q) => $q->where('after_hours', true))->count();
        $byStatus = (clone $leads)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $byType = (clone $leads)->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');

        // Knowledge gaps: the same question asked on several calls counts once, with how often and the latest call.
        $gaps = (clone $calls)->whereNotNull('unanswered')->latest('started_at')->limit(300)->get(['id', 'started_at', 'unanswered'])
            ->flatMap(fn (Call $c) => collect($c->unanswered ?? [])->map(fn ($q) => ['q' => $q, 'call' => $c->id, 'at' => $c->started_at]))
            ->groupBy(fn ($g) => mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $g['q']))))
            ->map(fn ($g) => ['question' => $g->first()['q'], 'count' => $g->count(), 'callId' => $g->first()['call'], 'lastAt' => $g->first()['at']->toIso8601String()])
            ->sortByDesc('count')->values()->take(50);

        // What the period cost on the monthly plan (the plan's price spread over the days shown).
        $cost = $user->account()->planLimits()['price_pkr'] * $days / 30;

        return Inertia::render('Insights', [
            'days' => $days,
            'funnel' => [
                ['label' => 'Conversations', 'value' => $total],
                ['label' => 'Reached a clear end', 'value' => $completed],
                ['label' => 'Leads from calls', 'value' => $fromCalls],
                ['label' => 'Booked or won', 'value' => $won],
            ],
            'stats' => [
                'leads' => $leadCount,
                'widgetLeads' => $leadCount - $fromCalls,
                'leadRate' => $total ? round($fromCalls / $total * 100) : 0,
                'afterHoursShare' => $total ? round($afterHours / $total * 100) : 0,
                'afterHoursLeads' => $afterHoursLeads,
                'costPkr' => (int) round($cost),
                'costPerConversationPkr' => $total ? (int) round($cost / $total) : 0,
                'costPerLeadPkr' => $fromCalls ? (int) round($cost / $fromCalls) : null,
                'hoursSet' => (bool) $bot->business_hours,
            ],
            'byStatus' => collect(Booking::STATUSES)->map(fn ($s) => ['status' => $s, 'count' => (int) ($byStatus[$s] ?? 0)]),
            'byType' => collect(Booking::TYPES)->map(fn ($t) => ['type' => $t, 'count' => (int) ($byType[$t] ?? 0)]),
            'gaps' => $gaps,
        ]);
    }
}
