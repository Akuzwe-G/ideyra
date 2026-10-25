<?php

namespace App\Notifications;

use App\Models\ReportRun;
use App\Support\IntelligenceReports;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IntelligenceReportReady extends Notification
{
    public function __construct(public int $runId) {}

    public function via(object $notifiable): array
    {
        return IntelligenceReports::emailOperational() ? ['database', 'mail'] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'Your confidential report is ready', 'message' => IntelligenceReports::emailOperational() ? 'A report is available through your authorised report centre.' : 'A report is ready in-app. Email delivery is disabled or unverified.', 'target' => 'report', 'subject_id' => $this->runId, 'institution_id' => ReportRun::find($this->runId)?->institution_id];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Ideyra confidential report ready')->line('A requested report is ready. Sign in to access it through your current permissions.')->action('Open report', route('reports.show', $this->runId));
    }
}
