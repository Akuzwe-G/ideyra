<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\WorkspaceNotification;
use Illuminate\Database\Eloquent\Model;

class ProjectSignals
{
    public static function send(array $ids, string $target, Model $subject, string $title, string $message): void
    {
        foreach (User::whereIn('id', array_unique($ids))->where('status', 'active')->get() as $user) {
            $visible = match ($target) {
                'idea' => InnovationAccess::ideas($user)->whereKey($subject->id)->exists(),
                'project' => InnovationAccess::projects($user)->whereKey($subject->id)->exists(),
                'group' => InnovationAccess::groups($user)->whereKey($subject->id)->exists(),
                default => false,
            };
            if ($visible) {
                $user->notifyNow(new WorkspaceNotification(['title' => $title, 'message' => $message, 'target' => $target, 'subject_id' => $subject->id, 'institution_id' => $subject->institution_id]));
            }
        }
    }
}
