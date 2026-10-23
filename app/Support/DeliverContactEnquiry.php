<?php

namespace App\Support;

use App\Mail\ContactEnquiryMail;
use App\Models\Enquiry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class DeliverContactEnquiry
{
    public function send(Enquiry $enquiry): string
    {
        $recipient = config('ideyra.contact_email');
        $mailer = config('mail.default');
        $transport = config('mail.mailers.'.$mailer.'.transport');
        $enquiry->forceFill([
            'mail_status' => 'pending', 'mail_recipient' => $recipient,
            'mail_mailer' => $mailer, 'mail_attempted_at' => now(),
        ])->save();

        try {
            if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid contact recipient configuration.');
            }
            $result = Mail::mailer($mailer)->to($recipient)->send(new ContactEnquiryMail($enquiry));
            $status = match ($transport) {
                'log' => 'logged',
                'array' => 'simulated',
                'failover', 'roundrobin' => 'unconfirmed',
                default => $result === null ? 'unconfirmed' : 'accepted',
            };
        } catch (Throwable $exception) {
            Log::warning('Contact mail attempt failed; enquiry remains stored.', [
                'enquiry_id' => $enquiry->id, 'exception_type' => $exception::class,
            ]);
            $status = 'failed';
        }

        $enquiry->forceFill([
            'mail_status' => $status,
            'mail_accepted_at' => $status === 'accepted' ? now() : null,
        ])->save();

        return $status;
    }
}
