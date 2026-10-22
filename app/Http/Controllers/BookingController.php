<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\AvailabilityService;
use App\Services\OutboundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function index(Request $request): Response
    {
        $bot = $request->user()->bot;
        $status = in_array($request->query('status'), Booking::STATUSES, true) ? $request->query('status') : '';
        $counts = $bot->bookings()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('Bookings', [
            'bookings' => $bot->bookings()->when($status, fn ($q, $s) => $q->where('status', $s))
                ->with(['deliveries' => fn ($q) => $q->latest()])->latest()->limit(200)->get()
                ->map(fn (Booking $b) => [
                    'id' => $b->id,
                    'type' => $b->type,
                    'source' => $b->source,
                    'details' => collect($b->details ?? [])->map(fn ($v, $k) => [
                        'label' => collect($bot->capture_fields ?? [])->firstWhere('key', $k)['label'] ?? str($k)->headline()->toString(),
                        'value' => $v,
                    ])->values(),
                    'sync' => ($d = $b->deliveries->first()) ? ['status' => $d->status, 'reference' => $d->external_reference] : null,
                    'name' => $b->customer_name,
                    'phone' => $b->phone,
                    'request' => $b->request,
                    'when' => $b->preferred_time,
                    'startsAt' => $b->starts_at?->toIso8601String(),
                    'inCalendar' => (bool) $b->google_event_id,
                    'aiCalledAt' => $b->ai_callback_at?->toIso8601String(),
                    'status' => $b->status,
                    'callId' => $b->call_id,
                    'createdAt' => $b->created_at->toIso8601String(),
                ]),
            'filter' => $status,
            'outboundAvailable' => app(OutboundService::class)->available($bot),
            'counts' => [
                'new' => (int) ($counts['new'] ?? 0),
                'confirmed' => (int) ($counts['confirmed'] ?? 0),
                'done' => (int) ($counts['done'] ?? 0),
                'month' => $bot->bookings()->where('created_at', '>=', now($request->user()->timezone)->startOfMonth()->utc())->count(),
            ],
        ]);
    }

    /** "Call back with AI": the bot phones the customer now. */
    public function aiCallback(Request $request, Booking $booking, OutboundService $outbound): RedirectResponse
    {
        abort_unless($booking->bot_id === $request->user()->bot->id, 404);
        try {
            $outbound->callback($booking->setRelation('bot', $request->user()->bot));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Your AI assistant is calling {$booking->customer_name} now. The call will show in Call Logs.");
    }

    public function update(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($booking->bot_id === $request->user()->bot->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(Booking::STATUSES)]]);
        $booking->update($data);
        // Lost / spam frees the time slot and removes the Google Calendar event.
        if (in_array($data['status'], AvailabilityService::RELEASED, true)) {
            app(AvailabilityService::class)->release($booking->setRelation('bot', $request->user()->bot));
        }

        return back()->with('success', 'Booking marked as '.$data['status']);
    }
}
