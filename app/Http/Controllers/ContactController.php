<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Models\Enquiry;
use Illuminate\Http\RedirectResponse;
use Throwable;

class ContactController extends Controller
{
    public function store(ContactRequest $request): RedirectResponse
    {
        try {
            Enquiry::create($request->safe()->only(['name', 'email', 'category', 'subject', 'message']) + ['consented_at' => now()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput($request->except('website', '_token'))
                ->withErrors(['submission' => 'We could not store your enquiry. Please try again later.']);
        }

        return redirect()->route('contact')->with('status', 'Your enquiry has been securely stored for later review. No email has been sent. Replies are not yet available through this website.');
    }
}
