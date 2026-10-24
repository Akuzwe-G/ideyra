<?php

namespace App\Support;

use App\Models\Opportunity;
use App\Models\OpportunityApplication;
use App\Models\SponsorshipRequest;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class OpportunitySignals
{
    public static function send(array $ids, string $target, Model $subject, string $title, string $message): void
    {
        foreach (User::whereIn('id', array_unique($ids))->where('status', 'active')->get() as $user) {
            $visible = match ($target) {
                'opportunity' => OpportunityAccess::visible($user)->whereKey($subject->id)->exists(),'opportunity_application' => OpportunityAccess::applications($user)->whereKey($subject->id)->exists(),'sponsorship' => OpportunityAccess::sponsorships($user)->whereKey($subject->id)->exists(),default => false
            };
            if ($visible) {
                $user->notifyNow(new WorkspaceNotification(['title' => $title, 'message' => $message, 'target' => $target, 'subject_id' => $subject->id, 'institution_id' => $subject->institution_id ?? null]));
            }
        }
    }

    public static function publication(Opportunity $opportunity): void
    {
        User::where('status', 'active')->whereHas('memberships', fn ($member) => $member->where('status', 'active')->whereHas('institution', fn ($institution) => $institution->where('status', 'active'))->whereHas('role', fn ($role) => $role->where('name', 'Student'))->when($opportunity->institution_id, fn ($query) => $query->where('institution_id', $opportunity->institution_id)))->chunkById(100, function ($users) use ($opportunity): void {
            foreach ($users as $user) {
                $eligible = InnovationAccess::memberships($user)->contains(fn ($member) => OpportunityAccess::eligible($opportunity, $user, $member->institution_id));
                if ($eligible && DB::table('opportunity_notices')->insertOrIgnore(['opportunity_id' => $opportunity->id, 'user_id' => $user->id, 'event_key' => 'publication', 'created_at' => now(), 'updated_at' => now()])) {
                    self::send([$user->id], 'opportunity', $opportunity, 'New opportunity', 'A new opportunity is available in your authorised audience.');
                }
            }
        });
    }

    public static function application(OpportunityApplication $application, bool $submitted = false): void
    {
        $ids = array_merge([$application->applicant_id], $application->participant_ids ?? []);
        if ($submitted) {
            $opportunity = $application->opportunity;
            $candidates = User::where('status', 'active');
            if (! $opportunity->institution_id) {
                $candidates->whereHas('roles', fn ($role) => $role->where('name', 'System Administrator')->where('scope', 'platform'));
            } else {
                $candidates->whereHas('memberships', fn ($member) => $member->where('institution_id', $opportunity->institution_id)->where('status', 'active')->where(function ($rights): void {
                    $rights->whereHas('role', fn ($role) => $role->whereJsonContains('permissions', 'opportunities.review_applications'))->orWhereExists(fn ($override) => $override->selectRaw('1')->from('membership_permissions as override')->join('permissions as permission', 'permission.id', '=', 'override.permission_id')->whereColumn('override.membership_id', 'memberships.id')->where('permission.name', 'opportunities.review_applications')->where('override.allowed', true));
                }));
            }
            $ids = $candidates->get()->filter(fn ($user) => OpportunityAccess::reviews($application, $user))->pluck('id')->all();
        }
        self::send($ids, 'opportunity_application', $application, $submitted ? 'Application awaiting review' : 'Application update', 'An opportunity application in your authorised workspace has an update.');
    }

    public static function sponsorship(SponsorshipRequest $request): void
    {
        self::send([$request->requested_by], 'sponsorship', $request, 'Project support update', 'A sponsorship request or non-payment commitment has an update.');
    }
}
