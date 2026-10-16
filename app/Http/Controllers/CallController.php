<?php

namespace App\Http\Controllers;

use App\Models\Call;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CallController extends Controller
{
    public function index(Request $request): Response
    {
        $bot = $request->user()->bot;
        $filters = $this->filters($request);
        $perPage = in_array((int) $request->query('per'), [10, 25, 50], true) ? (int) $request->query('per') : 10;

        $page = $this->query($request, $filters)
            ->with(['messages' => fn ($q) => $q->where('role', 'user')->orderBy('position')])
            ->latest('started_at')
            ->paginate($perPage)
            ->withQueryString();

        $all = $bot->calls();

        return Inertia::render('CallLogs', [
            'calls' => [
                'data' => collect($page->items())->map->toRow(),
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'filters' => $filters + ['per' => $perPage],
            'summary' => [
                'total' => (clone $all)->count(),
                'talkMinutes' => (int) round((clone $all)->sum('duration_seconds') / 60),
                'spend' => (float) (clone $all)->sum('cost'),
                'completedPct' => ($n = (clone $all)->count()) ? (int) round((clone $all)->where('status', 'completed')->count() / $n * 100) : 0,
            ],
        ]);
    }

    public function show(Request $request, Call $call): JsonResponse
    {
        abort_unless($call->bot_id === $request->user()->bot->id, 404);

        return response()->json($call->load('messages')->toDetail());
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->query($request, $this->filters($request))->with('messages')->latest('started_at');
        $tz = $request->user()->timezone;

        return response()->streamDownload(function () use ($query, $tz) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Started', 'Title', 'Outcome', 'Status', 'Duration (s)', 'Cost (USD)', 'Summary', 'Transcript']);
            $query->chunk(200, function ($calls) use ($out, $tz) {
                foreach ($calls as $c) {
                    fputcsv($out, [
                        $c->started_at->setTimezone($tz)->format('Y-m-d H:i'), $c->title, $c->outcome_tag, $c->status,
                        $c->duration_seconds, number_format((float) $c->cost, 4, '.', ''), $c->summary,
                        $c->messages->map(fn ($m) => ($m->role === 'ai' ? 'Bot: ' : 'Caller: ').$m->text)->implode("\n"),
                    ]);
                }
            });
            fclose($out);
        }, 'hamsukhan-calls-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status'), ['completed', 'dropped', 'failed'], true) ? $request->query('status') : '',
            'days' => in_array((int) $request->query('days'), [1, 7, 30], true) ? (int) $request->query('days') : 0,
        ];
    }

    private function query(Request $request, array $f)
    {
        $tz = $request->user()->timezone;

        return $request->user()->bot->calls()
            ->when($f['status'], fn (Builder $q, $s) => $q->where('status', $s))
            ->when($f['days'], fn (Builder $q, $d) => $q->where('started_at', '>=', $d === 1 ? Carbon::now($tz)->startOfDay()->utc() : now()->subDays($d)))
            ->when($f['q'], function (Builder $q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->where('title', 'like', $like)->orWhere('outcome_tag', 'like', $like)->orWhere('summary', 'like', $like)
                    ->orWhereHas('messages', fn (Builder $m) => $m->where('text', 'like', $like)));
            });
    }
}
