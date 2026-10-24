<?php

namespace App\Support;

use App\Models\Membership;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use Illuminate\Database\Eloquent\Model;

class WorkspaceNotifications
{
    public static function record(string $action, Model $subject, ?int $institutionId, array $metadata): void
    {
        $definition = match ($action) {
            'application.submitted' => ['New application received', 'An application is ready for review.', 'application'],
            'application.reviewed' => ['Application review recorded', 'An application review or status has been updated.', 'application'],
            'application.onboarded' => ['Institution onboarding completed', 'An approved application has completed controlled onboarding.', 'application'],
            'institution.created' => ['Institution created', 'A draft institution is ready for administrator appointment.', 'institution'],
            'institution.status_changed' => ['Institution status updated', 'The institution is now '.str_replace('_', ' ', $metadata['status'] ?? 'updated').'.', 'institution'],
            'membership.invited' => ['Membership invitation prepared', 'A new institutional membership is pending acceptance. Use your private invitation channel to accept it.', 'membership'],
            'membership.accepted' => ['Membership accepted', 'Your institutional membership has been accepted.', 'membership'],
            'membership.updated', 'membership.permissions_changed' => ['Membership updated', 'Your institutional access has changed. Review your current authorised workspace.', 'membership'],
            'identity.profile_updated' => ['Profile updated', 'Your account profile was updated.', 'profile'],
            'identity.password_reset' => ['Password reset completed', 'Your password was reset. Contact your administrator if this was unexpected.', 'profile'],
            default => null,
        };
        if (! $definition) {
            return;
        }
        [$title, $message, $target] = $definition;
        $ids = collect();
        if (in_array($target, ['application', 'institution'], true)) {
            $ids = User::where('status', 'active')->whereHas('roles', fn ($query) => $query->where('name', 'System Administrator')->where('scope', 'platform'))->pluck('id');
        }
        if ($target === 'institution' && $institutionId) {
            $ids = $ids->merge(Membership::where('institution_id', $institutionId)->where('status', 'active')->whereHas('role', fn ($query) => $query->where('name', 'Institution Administrator'))->pluck('user_id'));
        }
        if ($target === 'membership') {
            $ids->push($subject->user_id);
        }
        if ($target === 'profile') {
            $ids->push($subject->getKey());
        }
        User::whereIn('id', $ids->unique())->whereIn('status', ['active', 'pending'])->chunkById(100, function ($users) use ($title, $message, $target, $subject, $institutionId): void {
            foreach ($users as $user) {
                $user->notifyNow(new WorkspaceNotification(['title' => $title, 'message' => $message, 'target' => $target, 'subject_id' => $subject->getKey(), 'institution_id' => $institutionId]));
            }
        });
    }
}
