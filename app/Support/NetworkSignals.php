<?php

namespace App\Support;

use App\Models\Institution;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class NetworkSignals
{
    public static function record(string $action, Model $subject, ?int $institutionId, array $details = []): void
    {
        DB::table('network_actions')->insert(['subject_type' => class_basename($subject), 'subject_id' => $subject->id, 'institution_id' => $institutionId, 'user_id' => auth()->id(), 'action' => $action, 'details' => Crypt::encryptString(json_encode($details)), 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('network.'.$action, $subject, $institutionId, collect($details)->only(['status', 'user_id', 'version', 'report_id'])->all());
    }

    public static function send(array $ids, string $target, Model $subject, string $event, string $title): void
    {
        $key = hash('sha256', $target.':'.$subject->id.':'.$event);
        foreach (User::whereIn('id', array_unique($ids))->where('status', 'active')->get() as $user) {
            if (DB::table('network_notices')->insertOrIgnore(['user_id' => $user->id, 'event_key' => $key, 'created_at' => now(), 'updated_at' => now()])) {
                $user->notifyNow(new WorkspaceNotification(['title' => $title, 'message' => 'An innovation network workflow has changed. Sign in to review it through your current permissions.', 'target' => $target, 'subject_id' => $subject->id, 'institution_id' => $subject->institution_id ?? null]));
            }
        }
    }

    public static function reviewers(Institution $institution, string $permission): array
    {
        return User::whereHas('memberships', fn ($q) => $q->where('institution_id', $institution->id)->where('status', 'active'))->where('status', 'active')->get()->filter(fn ($u) => NetworkAccess::can($u, $institution, $permission))->pluck('id')->all();
    }
}
