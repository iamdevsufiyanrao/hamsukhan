<?php

namespace App\Http\Controllers;

use App\Models\Booking;
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
                    'details' => collect($b->details ?? [])->map(fn ($v, $k) => [
                        'label' => collect($bot->capture_fields ?? [])->firstWhere('key', $k)['label'] ?? str($k)->headline()->toString(),
                        'value' => $v,
                    ])->values(),
                    'sync' => ($d = $b->deliveries->first()) ? ['status' => $d->status, 'reference' => $d->external_reference] : null,
                    'name' => $b->customer_name,
                    'phone' => $b->phone,
                    'request' => $b->request,
                    'when' => $b->preferred_time,
                    'status' => $b->status,
                    'callId' => $b->call_id,
                    'createdAt' => $b->created_at->toIso8601String(),
                ]),
            'filter' => $status,
            'counts' => [
                'new' => (int) ($counts['new'] ?? 0),
                'confirmed' => (int) ($counts['confirmed'] ?? 0),
                'done' => (int) ($counts['done'] ?? 0),
                'month' => $bot->bookings()->where('created_at', '>=', now($request->user()->timezone)->startOfMonth()->utc())->count(),
            ],
        ]);
    }

    public function update(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($booking->bot_id === $request->user()->bot->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(Booking::STATUSES)]]);
        $booking->update($data);

        return back()->with('success', 'Booking marked as '.$data['status']);
    }
}
