<?php

namespace App\Mail;

use App\Models\Membership;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class IdentityInvitationMail extends Mailable
{
    public function __construct(public Membership $membership, public string $activationUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ideyra — secure institutional invitation');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.identity-invitation', text: 'mail.identity-invitation-text');
    }
}
