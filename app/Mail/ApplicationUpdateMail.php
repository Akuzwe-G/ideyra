<?php

namespace App\Mail;

use App\Models\JoinApplication;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ApplicationUpdateMail extends Mailable
{
    public function __construct(public JoinApplication $application, public string $publicMessage) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ideyra application update '.$this->application->reference);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.application-update', text: 'mail.application-update-text');
    }
}
