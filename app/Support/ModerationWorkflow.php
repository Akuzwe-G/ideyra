<?php

namespace App\Support;

use App\Models\CollaborationEntry;
use App\Models\CollaborationRequest;
use App\Models\CollaborationWorkspace;
use App\Models\ContentReport;
use App\Models\NetworkPublication;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ModerationWorkflow
{
    public function target(string $type, int $id): Model
    {
        return match ($type) {
            'publication' => NetworkPublication::findOrFail($id),'request' => CollaborationRequest::findOrFail($id),'entry' => CollaborationEntry::findOrFail($id),default => abort(422)
        };
    }

    public function institutionId(string $type, Model $target): int
    {
        return match ($type) {
            'publication' => $target->institution_id,'request' => $target->publication->institution_id,'entry' => $target->workspace->institution_id
        };
    }

    public function canReview(User $user, ContentReport $report): bool
    {
        return NetworkAccess::platform($user) || NetworkAccess::can($user, $report->institution, 'moderation.review');
    }

    public function report(User $user, string $type, int $id, string $reason): ContentReport
    {
        $target = $this->target($type, $id);
        $allowed = match ($type) {
            'publication' => NetworkAccess::publicationVisible($user, $target) || NetworkAccess::publicationEdit($user, $target),'request' => NetworkAccess::requestView($user, $target),'entry' => (bool) NetworkAccess::member($user, $target->workspace)
        };
        abort_unless($user->status === 'active' && $allowed, 403);
        abort_if(ContentReport::where('target_type', $type)->where('target_id', $id)->where('user_id', $user->id)->where('status', 'open')->exists(), 422, 'Your report is already awaiting review.');
        $report = ContentReport::create(['user_id' => $user->id, 'institution_id' => $this->institutionId($type, $target), 'target_type' => $type, 'target_id' => $id, 'reason' => $reason, 'status' => 'open']);
        NetworkSignals::record('moderation.reported', $report, $report->institution_id, ['target_type' => $type, 'target_id' => $id]);
        $ids = NetworkSignals::reviewers($report->institution, 'moderation.review');
        $ids = [...$ids, ...User::where('status', 'active')->whereHas('roles', fn ($q) => $q->where('scope', 'platform')->whereJsonContains('permissions', 'moderation.review_platform'))->pluck('id')->all()];
        NetworkSignals::send(array_diff($ids, [$user->id]), 'network_moderation', $report, 'new', 'Innovation content report awaiting review');

        return $report;
    }

    public function decide(User $user, ContentReport $report, string $action, string $reason, int $blockDays = 0): void
    {
        abort_unless($this->canReview($user, $report) && $report->user_id !== $user->id, 403);
        DB::transaction(function () use ($user, $report, $action, $reason, $blockDays): void {
            $r = ContentReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            abort_unless($r->status === 'open', 422);
            $target = $this->target($r->target_type, $r->target_id);
            $author = match ($r->target_type) {
                'publication' => $target->submitted_by,default => $target->user_id
            };
            abort_if($author === $user->id, 403, 'Another authorised moderator must review content involving you.');
            if ($action === 'remove') {
                if ($r->target_type === 'publication') {
                    $target->update(['status' => 'unpublished']);
                } elseif ($r->target_type === 'request') {
                    $target->update(['status' => 'declined', 'active_key' => null]);
                    CollaborationWorkspace::where('collaboration_request_id', $target->id)->update(['status' => 'restricted']);
                } else {
                    $target->update(['removed_at' => now()]);
                }
            } else {
                abort_unless($action === 'dismiss', 422);
            }
            if ($blockDays > 0) {
                abort_unless($blockDays <= 365 && $action === 'remove', 422);
                DB::table('network_blocks')->insert(['user_id' => $author, 'institution_id' => NetworkAccess::platform($user) ? null : $r->institution_id, 'created_by' => $user->id, 'expires_at' => now()->addDays($blockDays), 'reason' => Crypt::encryptString($reason), 'created_at' => now(), 'updated_at' => now()]);
            }
            $r->update(['status' => $action === 'remove' ? 'actioned' : 'dismissed', 'decided_by' => $user->id, 'decision_reason' => $reason, 'decided_at' => now()]);
            NetworkSignals::record('moderation.'.$action, $r, $r->institution_id, ['status' => $r->status, 'target_type' => $r->target_type, 'target_id' => $r->target_id, 'reason' => $reason, 'block_days' => $blockDays]);
            NetworkSignals::send([$r->user_id, $author],'network_moderation',$r,'decided','An innovation content moderation decision was recorded');
        });
    }
}
