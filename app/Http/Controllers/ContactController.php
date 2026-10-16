<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/** "Contact us" form on the landing page. Saved for the super admin and, if a support email is set, emailed there too. */
class ContactController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        // Honeypot: real visitors never see this field, bots usually fill it. Pretend it worked.
        if (filled($request->input('company_site'))) {
            return back()->with('success', 'Thanks! We got your message.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'business' => ['nullable', 'string', 'max:120'],
            'topic' => ['required', Rule::in(array_keys(ContactMessage::TOPICS))],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
        ]);

        $msg = ContactMessage::create($data + ['ip' => $request->ip()]);

        if ($to = config('hamsukhan.site.support_email')) {
            try {
                Mail::raw(
                    "{$msg->topicLabel()} from {$msg->name} <{$msg->email}>".($msg->business ? " ({$msg->business})" : '')."\n\n{$msg->message}",
                    fn ($m) => $m->to($to)->replyTo($msg->email, $msg->name)->subject('New contact message: '.$msg->topicLabel()),
                );
            } catch (\Throwable $e) {
                Log::warning('Contact email failed: '.$e->getMessage()); // the message is saved either way
            }
        }

        return back()->with('success', 'Thanks! We got your message and will reply by email soon.');
    }
}
