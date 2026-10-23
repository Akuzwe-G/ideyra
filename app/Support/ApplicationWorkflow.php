<?php

namespace App\Support;

use App\Mail\ApplicationNotification;
use App\Models\JoinApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApplicationWorkflow
{
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', mb_strtolower(preg_replace('/\s+/u', ' ', trim($value))), config('app.key'));
    }

    public static function searchTokens(string $text): array
    {
        $tokens = [];
        foreach (preg_split('/[^\pL\pN]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            for ($length = 1; $length <= min(mb_strlen($word), 80); $length++) {
                $tokens[] = self::hash(mb_substr($word, 0, $length));
            }
        }

        return array_values(array_unique($tokens));
    }

    public function submit(string $type, array $data): JoinApplication
    {
        $nonce = (string) session('application_nonce.'.$type);
        abort_unless(strlen($nonce) === 64 && hash_equals($nonce, $data['submission_key']), 419);
        $submissionHash = self::hash($nonce);
        if ($existing = JoinApplication::where('submission_hash', $submissionHash)->first()) {
            return $existing;
        }
        $duplicate = self::hash($type.'|'.$data['email'].'|'.$data['institution_name'].'|'.now()->toDateString());
        if (JoinApplication::where('duplicate_hash', $duplicate)->exists()) {
            throw ValidationException::withMessages(['email' => 'An application may already exist for these details today. Contact the team for verified assistance rather than submitting it again.']);
        }
        $payload = array_intersect_key($data, ApplicationSchema::fields($type));
        $payload['consent_recorded_at'] = now()->toIso8601String();
        $application = DB::transaction(function () use ($type, $data, $payload, $submissionHash, $duplicate): JoinApplication {
            $uuid = (string) Str::ulid();
            $application = JoinApplication::create([
                'uuid' => $uuid, 'reference' => 'IDEYRA-'.now()->format('Ymd').'-'.substr($uuid, -10),
                'type' => $type, 'country' => $data['country'], 'payload' => $payload,
                'search_tokens' => self::searchTokens($data['applicant_name'].' '.$data['institution_name']),
                'institution_tokens' => self::searchTokens($data['institution_name']),
                'submission_hash' => $submissionHash, 'duplicate_hash' => $duplicate, 'status' => 'submitted',
            ]);
            Audit::record('application.submitted', $application, null, ['type' => $type]);

            return $application;
        });
        try {
            $application->update(['mail_attempted_at' => now()]);
            Mail::to(config('ideyra.application_email'))->send(new ApplicationNotification($application));
            $driver = config('mail.mailers.'.config('mail.default').'.transport');
            $application->update(['mail_status' => match ($driver) {
                'log' => 'logged', 'array' => 'simulated', default => 'unconfirmed'
            }]);
        } catch (Throwable) {
            $application->update(['mail_status' => 'failed']);
        }
        Audit::record('application.notification_attempted', $application, null, ['status' => $application->mail_status]);

        return $application;
    }

    public function receiptUrl(JoinApplication $application): string
    {
        return URL::temporarySignedRoute('applications.receipt', now()->addHours(72), ['application' => $application->uuid]);
    }

    public function review(JoinApplication $application, string $status, ?string $note): void
    {
        InstitutionAccess::requireSystem();
        $transitions = [
            'submitted' => ['under_review', 'more_information_requested', 'approved', 'rejected'],
            'under_review' => ['more_information_requested', 'approved', 'rejected'],
            'more_information_requested' => ['under_review', 'approved', 'rejected'],
            'approved' => ['under_review', 'rejected'],
            'rejected' => ['under_review'],
            'onboarded' => [],
        ];
        DB::transaction(function () use ($application, $status, $note, $transitions): void {
            $locked = JoinApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            if ($status !== $locked->status && ! in_array($status, $transitions[$locked->status], true)) {
                throw ValidationException::withMessages(['status' => 'This application status transition is not permitted.']);
            }
            $notes = $locked->review_notes ?? [];
            $notes[] = ['reviewer_id' => auth()->id(), 'status' => $status, 'note' => $note, 'at' => now()->toIso8601String()];
            $locked->update(['status' => $status, 'reviewer_id' => auth()->id(), 'review_notes' => $notes,
                'decided_at' => in_array($status, ['approved', 'rejected'], true) ? now() : null]);
            Audit::record('application.reviewed', $locked, null, ['status' => $status]);
        });
    }
}
