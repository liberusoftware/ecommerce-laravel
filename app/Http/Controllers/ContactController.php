<?php

namespace App\Http\Controllers;

use App\Mail\ContactMessage;
use App\Models\Store;
use App\Settings\GeneralSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function show(): View
    {
        return view('contact');
    }

    public function send(Request $request, GeneralSettings $settings): RedirectResponse
    {
        // Honeypot first, deliberately. A hidden field no human can reach, so anything
        // in it is a bot — and it answers exactly as a successful send would. Validating
        // it instead would hand the bot an error naming the field that caught it.
        if (filled($request->input('website'))) {
            return redirect()->route('contact')->with('success', $this->confirmation());
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // This address becomes a Reply-To header, so a CRLF payload here would be
            // header injection. email:rfc rejects that on its own — verified, and
            // ContactFormTest pins the behaviour rather than the mechanism, so it
            // still bites if this rule is ever loosened.
            'email' => ['required', 'string', 'max:254', 'email:rfc'],
            'subject' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ], [
            'message.min' => 'Please add a little more detail so we can help.',
        ]);

        // The store's own inbox; the platform's only when the merchant has not set one.
        Mail::to(Store::current()?->email ?: $settings->site_email)->send(new ContactMessage(
            senderName: $validated['name'],
            senderEmail: $validated['email'],
            contactSubject: ($validated['subject'] ?? null) ?: 'No subject',
            body: $validated['message'],
        ));

        return redirect()->route('contact')->with('success', $this->confirmation());
    }

    private function confirmation(): string
    {
        return 'Thanks — your message is on its way. We usually reply within one working day.';
    }
}
