<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Models\Enquiry;
use App\Support\DeliverContactEnquiry;
use Illuminate\Http\RedirectResponse;
use Throwable;

class ContactController extends Controller
{
    public function store(ContactRequest $request, DeliverContactEnquiry $delivery): RedirectResponse
    {
        try {
            $enquiry = Enquiry::create($request->safe()->only(['name', 'email', 'category', 'subject', 'message']) + ['consented_at' => now()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput($request->except('website', '_token'))
                ->withErrors(['submission' => 'We could not store your enquiry. Please try again later.']);
        }

        try {
            $status = $delivery->send($enquiry);
        } catch (Throwable $exception) {
            report($exception);
            $status = 'unconfirmed';
        }
        $message = match ($status) {
            'logged' => 'Your enquiry has been securely stored. Email was recorded in the local development log only; it was not delivered to an inbox.',
            'simulated' => 'Your enquiry has been securely stored. Email delivery was simulated; it was not delivered to an inbox.',
            'accepted' => 'Your enquiry has been securely stored and accepted by the mail service for sending. Inbox delivery is not yet confirmed.',
            'failed' => 'Your enquiry has been securely stored, but the email notification could not be sent. Please do not submit it again.',
            default => 'Your enquiry has been securely stored. Email delivery could not be confirmed. Please do not submit it again.',
        };

        return redirect()->route('contact')->with('status', $message);
    }
}
