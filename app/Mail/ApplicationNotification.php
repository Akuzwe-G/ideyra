<?php

namespace App\Mail;

use App\Models\JoinApplication;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ApplicationNotification extends Mailable
{
    public function __construct(public JoinApplication $application) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ideyra application '.$this->application->reference);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.application', text: 'mail.application-text');
    }
}
