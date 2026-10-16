<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Super admin inbox for the landing page's contact form. */
class MessageController extends Controller
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ['new', 'read'], true) ? $request->query('status') : 'new';

        return Inertia::render('Admin/Messages', [
            'filter' => $status,
            'messages' => ContactMessage::where('status', $status)->latest()->limit(100)->get()
                ->map(fn (ContactMessage $m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'email' => $m->email,
                    'business' => $m->business,
                    'topic' => $m->topicLabel(),
                    'message' => $m->message,
                    'status' => $m->status,
                    'createdAt' => $m->created_at->toIso8601String(),
                ]),
            'counts' => ContactMessage::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function markRead(ContactMessage $message): RedirectResponse
    {
        $message->update(['status' => 'read']);

        return back()->with('success', 'Marked as read');
    }

    public function destroy(ContactMessage $message): RedirectResponse
    {
        $message->delete();

        return back()->with('success', 'Message deleted');
    }
}
