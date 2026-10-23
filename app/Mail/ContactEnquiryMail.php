<?php

namespace App\Mail;

use App\Models\Enquiry;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ContactEnquiryMail extends Mailable
{
    public function __construct(public Enquiry $enquiry) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            replyTo: [new Address($this->enquiry->email, $this->enquiry->name)],
            subject: config('ideyra.name').' enquiry #'.$this->enquiry->id.' — '.str_replace(["\r", "\n"], ' ', $this->enquiry->subject),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.contact-enquiry', text: 'mail.contact-enquiry-text');
    }
}
