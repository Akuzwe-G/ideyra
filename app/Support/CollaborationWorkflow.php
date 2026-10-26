<?php

namespace App\Support;

use App\Models\CollaborationEntry;
use App\Models\CollaborationFile;
use App\Models\CollaborationMember;
use App\Models\CollaborationRequest;
use App\Models\CollaborationWorkspace;
use App\Models\Institution;
use App\Models\NetworkPublication;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CollaborationWorkflow
{
    public function save(User $user, NetworkPublication $publication, array $data, ?CollaborationRequest $request = null): CollaborationRequest
    {
        abort_unless(NetworkAccess::publicationVisible($user, $publication) && in_array($publication->visibility, ['network', 'public'], true) && ($publication->approved_snapshot['collaboration_open'] ?? false), 403);
        $institution = Institution::findOrFail($data['institution_id']);
        $target = $publication->institution;
        abort_unless($institution->id !== $target->id && NetworkAccess::can($user, $institution, 'collaborations.request') && NetworkAccess::setting($institution->id)->allow_network && NetworkAccess::setting($target->id)->allow_network, 403, 'Both institutions must explicitly participate in cross-institution collaboration.');
        abort_if(NetworkAccess::blocked($user, $target->id), 403, 'New collaboration requests are restricted.');
        $representative = $publication->submitter;
        abort_unless($representative && NetworkAccess::publicationEdit($representative, $publication), 422, 'The publication needs a current authorised representative.');
        abort_if($representative->id === $user->id, 403, 'You cannot request collaboration with yourself.');
        $support = null;
        if (! empty($data['supporting_project_id'])) {
            $project = Project::findOrFail($data['supporting_project_id']);
            Gate::forUser($user)->authorize('view', $project);
            $support = ['reference' => $project->identifier, 'title' => 'Authorised supporting project reference', 'shared_by' => $user->id];
        }

        return DB::transaction(function () use ($user, $publication, $institution, $representative, $data, $request, $support): CollaborationRequest {
            NetworkPublication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            $key = hash('sha256', $publication->id.':'.$user->id.':'.$institution->id);
            CollaborationRequest::where('active_key', $key)->where('expires_at', '<=', now())->whereIn('status', ['draft', 'submitted', 'under_review'])->update(['status' => 'expired', 'active_key' => null]);
            if ($request) {
                Gate::forUser($user)->authorize('update', $request);
                abort_unless($request->status === 'draft' && $request->network_publication_id === $publication->id && $request->institution_id === $institution->id, 422);
            } else {
                abort_if(CollaborationRequest::where('active_key', $key)->exists(), 422, 'An active request already exists. Open it from your requests.');
                $request = new CollaborationRequest(['identifier' => (string) Str::ulid(), 'network_publication_id' => $publication->id, 'user_id' => $user->id, 'institution_id' => $institution->id, 'representative_id' => $representative->id, 'status' => 'draft', 'active_key' => $key, 'expires_at' => now()->addDays(config('network.request_days'))]);
            }
            $request->fill(collect($data)->only(['type', 'motivation', 'skills', 'contribution', 'supporting_project_id'])->all());
            $request->supporting_snapshot = $support;
            $request->save();
            NetworkSignals::record('collaboration.draft_saved', $request, $institution->id);

            return $request;
        });
    }

    public function action(User $user, CollaborationRequest $request, string $action, string $reason): void
    {
        DB::transaction(function () use ($user, $request, $action, $reason): void {
            $r = CollaborationRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('view', $r);
            if ($r->expires_at->isPast() && in_array($r->status, ['draft', 'submitted', 'under_review'], true)) {
                $r->update(['status' => 'expired', 'active_key' => null]);
                abort(422, 'This request has expired.');
            }
            if ($action === 'submit') {
                Gate::forUser($user)->authorize('update', $r);
                abort_unless($r->status === 'draft', 422);
                abort_unless(NetworkAccess::publicationVisible($user, $r->publication) && $r->publication->collaboration_open && ! NetworkAccess::blocked($user, $r->publication->institution_id), 403);
                foreach ([$r->institution_id, $r->publication->institution_id] as $id) {
                    abort_unless(NetworkAccess::setting($id)->allow_network, 403);
                }
                $r->update(['status' => 'submitted', 'submitted_at' => now()]);
                NetworkSignals::send([$r->representative_id], 'network_request', $r, 'submitted', 'New collaboration request');
            } elseif ($action === 'withdraw') {
                Gate::forUser($user)->authorize('update', $r);
                abort_unless(in_array($r->status, ['draft', 'submitted', 'under_review'], true), 422);
                $r->update(['status' => 'withdrawn', 'active_key' => null]);
            } elseif (in_array($action, ['under_review', 'accept', 'decline'], true)) {
                abort_unless(NetworkAccess::publicationEdit($user, $r->publication) && $user->id !== $r->user_id && in_array($r->status, ['submitted', 'under_review'], true), 403);
                if ($action === 'decline') {
                    $r->update(['status' => 'declined', 'active_key' => null]);
                } elseif ($action === 'under_review') {
                    $r->update(['status' => 'under_review']);
                } else {
                    abort_unless(NetworkAccess::publicationVisible($r->requester, $r->publication) && ! NetworkAccess::blocked($r->requester, $r->publication->institution_id), 403);
                    $r->update(['status' => 'under_review', 'representative_id' => $user->id, 'representative_accepted_at' => now()]);
                    foreach ([$r->institution_id, $r->publication->institution_id] as $id) {
                        $required = NetworkAccess::setting($id)->require_collaboration_approval;
                        $r->approvals()->firstOrCreate(['institution_id' => $id], ['status' => $required ? 'pending' : 'approved']);
                    }
                    $this->activate($r);
                }
            } else {
                abort(422);
            }
            NetworkSignals::record('collaboration.'.$action, $r, $r->publication->institution_id, ['status' => $r->status, 'reason' => $reason]);
            NetworkSignals::send([$r->user_id, $r->representative_id], 'network_request', $r, $r->status, 'Collaboration request '.$r->status);
        });
    }

    public function approve(User $user, CollaborationRequest $request, int $institutionId, bool $approve, string $reason): void
    {
        DB::transaction(function () use ($user, $request, $institutionId, $approve, $reason): void {
            $r = CollaborationRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($r->status === 'under_review' && $r->representative_accepted_at && $r->expires_at->isFuture(), 422);
            abort_unless(NetworkAccess::can($user, Institution::findOrFail($institutionId), 'collaborations.review') && ! in_array($user->id, [$r->user_id, $r->representative_id], true), 403, 'An independent authorised institutional approver is required.');
            $approval = $r->approvals()->where('institution_id', $institutionId)->where('status', 'pending')->firstOrFail();
            $approval->update(['status' => $approve ? 'approved' : 'rejected', 'actor_id' => $user->id, 'reason' => $reason, 'decided_at' => now()]);
            if (! $approve) {
                $r->update(['status' => 'declined', 'active_key' => null]);
            } else {
                $this->activate($r);
            }
            NetworkSignals::record('collaboration.institution_decision', $r, $institutionId, ['status' => $approval->status, 'reason' => $reason]);
            NetworkSignals::send([$r->user_id, $r->representative_id], 'network_request', $r, 'approval:'.$approval->id, 'Institutional collaboration decision recorded');
        });
    }

    private function activate(CollaborationRequest $r): void
    {
        if (! $r->representative_accepted_at || $r->approvals()->count() !== 2 || $r->approvals()->where('status', '!=', 'approved')->exists()) {
            return;
        }
        $ids = [$r->publication->institution_id, $r->institution_id];
        foreach ($ids as $id) {
            abort_unless(NetworkAccess::setting($id)->allow_network && Institution::whereKey($id)->where('status', 'active')->exists(), 403);
        }
        abort_unless(NetworkAccess::publicationEdit($r->publication->submitter, $r->publication) && NetworkAccess::publicationVisible($r->requester, $r->publication) && InstitutionAccess::membership($r->institution, $r->requester), 403);
        $workspace = CollaborationWorkspace::firstOrCreate(['collaboration_request_id' => $r->id], ['identifier' => (string) Str::ulid(), 'network_publication_id' => $r->network_publication_id, 'institution_id' => $r->publication->institution_id, 'created_by' => $r->representative_id, 'title' => $r->publication->approved_snapshot['title'].' collaboration', 'purpose' => $r->contribution, 'institution_ids' => $ids, 'starts_at' => today(config('intelligence.timezone')), 'status' => 'active']);
        foreach ([[$r->representative_id, $ids[0]], [$r->user_id, $ids[1]]] as [$uid,$iid]) {
            $workspace->members()->firstOrCreate(['user_id' => $uid], ['institution_id' => $iid, 'invited_by' => $r->representative_id, 'role' => 'admin', 'status' => 'active', 'accepted_at' => now()]);
        }
        $r->update(['status' => 'accepted', 'active_key' => null]);
        NetworkSignals::record('workspace.created', $workspace, $workspace->institution_id, ['status' => 'active']);
        NetworkSignals::send([$r->user_id, $r->representative_id], 'network_workspace', $workspace, 'created', 'Your isolated collaboration workspace is ready');
    }

    public function invite(User $user, CollaborationWorkspace $workspace, string $email, int $institutionId, string $role): void
    {
        Gate::forUser($user)->authorize('update', $workspace);
        if ($role === 'admin') {
            $actor = NetworkAccess::member($user, $workspace, true);
            abort_unless($actor && NetworkAccess::can($user, $actor->institution, 'collaborations.manage'), 403, 'Granting another collaboration administrator requires institutional delegation authority.');
        }
        abort_unless(in_array($institutionId, $workspace->institution_ids, true) && in_array($role, ['member', 'mentor', 'admin'], true), 422);
        $invitee = User::where('email', mb_strtolower(trim($email)))->where('status', 'active')->first();
        abort_unless($invitee && InstitutionAccess::membership(Institution::findOrFail($institutionId), $invitee) && ! NetworkAccess::blocked($invitee, $workspace->institution_id), 422, 'Choose an active identity from a participating institution.');
        $member = $workspace->members()->firstOrNew(['user_id' => $invitee->id]);
        abort_if($member->exists && in_array($member->status, ['active', 'invited'], true), 422, 'This identity already has a current invitation or membership.');
        $member->fill(['institution_id' => $institutionId, 'invited_by' => $user->id, 'role' => $role, 'status' => 'invited', 'accepted_at' => null])->save();
        NetworkSignals::record('workspace.invitation_sent', $workspace, $workspace->institution_id, ['user_id' => $invitee->id]);
        NetworkSignals::send([$invitee->id], 'network_workspace', $workspace, 'invite:'.$member->updated_at->format('U.u').':'.$member->id, 'Collaboration invitation received');
    }

    public function acceptInvitation(User $user, CollaborationWorkspace $workspace, bool $accept): void
    {
        abort_unless(NetworkAccess::workspaces($user, true)->whereKey($workspace->id)->exists(), 403);
        $member = $workspace->members()->where('user_id', $user->id)->where('status', 'invited')->firstOrFail();
        abort_unless($workspace->status === 'active' && InstitutionAccess::membership($member->institution, $user) && NetworkAccess::setting($member->institution_id)->allow_network, 403);
        $member->update(['status' => $accept ? 'active' : 'declined', 'accepted_at' => $accept ? now() : null]);
        NetworkSignals::record('workspace.invitation_decided', $workspace, $workspace->institution_id, ['status' => $member->status, 'user_id' => $user->id]);
    }

    public function membership(User $user, CollaborationWorkspace $workspace, CollaborationMember $member, string $action, ?string $role = null): void
    {
        Gate::forUser($user)->authorize('update', $workspace);
        if ($action === 'role' && $role === 'admin' && $member->role !== 'admin') {
            $actor = NetworkAccess::member($user, $workspace, true);
            abort_unless($actor && NetworkAccess::can($user, $actor->institution, 'collaborations.manage'), 403, 'Granting another collaboration administrator requires institutional delegation authority.');
        }
        abort_unless($member->collaboration_workspace_id === $workspace->id, 404);
        DB::transaction(function () use ($workspace, $member, $action, $role): void {
            CollaborationWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if ($member->role === 'admin' && ($action === 'remove' || $role !== 'admin')) {
                abort_if($workspace->members()->where('status', 'active')->where('role', 'admin')->count() <= 1, 422, 'At least one active workspace administrator must remain.');
            }
            if ($action === 'remove') {
                $member->update(['status' => 'revoked']);
            } else {
                abort_unless(in_array($role, ['admin', 'member', 'mentor'], true), 422);
                $member->update(['role' => $role]);
            }
            NetworkSignals::record('workspace.membership_changed', $workspace, $workspace->institution_id, ['status' => $member->status, 'user_id' => $member->user_id]);
            NetworkSignals::send([$member->user_id], 'network_workspace', $workspace, 'member:'.$member->id.':'.$member->updated_at->format('U.u'), 'Collaboration membership updated');
        });
    }

    public function entry(User $user, CollaborationWorkspace $workspace, array $data, ?CollaborationEntry $entry = null): CollaborationEntry
    {
        Gate::forUser($user)->authorize('view', $workspace);
        abort_unless($workspace->status === 'active', 422, 'Closed workspaces are read-only.');
        $admin = NetworkAccess::member($user, $workspace, true);
        if ($entry) {
            abort_unless($entry->collaboration_workspace_id === $workspace->id && $entry->removed_at === null, 404);
            abort_unless($entry->user_id === $user->id || $admin, 403);
            abort_unless(($data['kind'] ?? $entry->kind) === $entry->kind && (int) ($data['parent_id'] ?? 0) === (int) $entry->parent_id, 422, 'Entry type and thread cannot be transferred.');
        }
        if (! empty($data['parent_id'])) {
            abort_unless($workspace->entries()->whereKey($data['parent_id'])->where('kind', 'thread')->whereNull('removed_at')->exists(), 422);
            $data['kind'] = 'reply';
        }
        if (! empty($data['assigned_to'])) {
            abort_unless($workspace->members()->where('user_id', $data['assigned_to'])->where('status', 'active')->exists(), 422);
        }
        $mentions = array_values(array_unique(array_map('intval', $data['mention_ids'] ?? [])));
        abort_if(array_diff($mentions, $workspace->members()->where('status', 'active')->pluck('user_id')->all()), 422);
        $entry ??= new CollaborationEntry(['collaboration_workspace_id' => $workspace->id, 'user_id' => $user->id, 'status' => 'open']);
        $entry->fill(collect($data)->only(['kind', 'parent_id', 'title', 'body', 'assigned_to', 'due_at'])->all());
        $entry->save();
        NetworkSignals::record('workspace.entry_saved', $workspace, $workspace->institution_id, ['entry_id' => $entry->id, 'kind' => $entry->kind]);
        NetworkSignals::send($mentions, 'network_workspace', $workspace, 'mention:'.$entry->id.':'.$entry->updated_at->format('U.u'), 'You were mentioned in a collaboration discussion');

        return $entry;
    }

    public function entryAction(User $user, CollaborationEntry $entry, string $action): void
    {
        $workspace = $entry->workspace;
        Gate::forUser($user)->authorize('view', $workspace);
        abort_unless($workspace->status === 'active' && $entry->removed_at === null, 422);
        $admin = NetworkAccess::member($user, $workspace, true);
        if ($action === 'remove') {
            abort_unless($entry->user_id === $user->id || $admin, 403);
            $entry->update(['removed_at' => now()]);
        } else {
            abort_unless(in_array($entry->kind, ['task', 'milestone'], true) && ($admin || $entry->assigned_to === $user->id || $entry->user_id === $user->id), 403);
            abort_unless(in_array($action, ['open', 'completed'], true), 422);
            $entry->update(['status' => $action, 'completed_at' => $action === 'completed' ? now() : null]);
        }
        NetworkSignals::record('workspace.entry_'.$action, $workspace, $workspace->institution_id, ['entry_id' => $entry->id]);
    }

    public function file(User $user, CollaborationWorkspace $workspace, UploadedFile $file, ?int $entryId = null): CollaborationFile
    {
        Gate::forUser($user)->authorize('view', $workspace);
        abort_unless($workspace->status === 'active', 422);
        if ($entryId) {
            abort_unless($workspace->entries()->whereKey($entryId)->whereNull('removed_at')->exists(), 422);
        }
        $path = $file->store('network/collaboration-files', 'local');
        abort_unless($path, 422);
        try {
            $record = $workspace->files()->create(['user_id' => $user->id, 'collaboration_entry_id' => $entryId, 'path' => $path, 'name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename($file->getClientOriginalName())), 0, 180), 'mime' => $file->getMimeType(), 'bytes' => $file->getSize()]);
            NetworkSignals::record('workspace.file_shared', $workspace, $workspace->institution_id, ['file_id' => $record->id]);

            return $record;
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function close(User $user, CollaborationWorkspace $workspace, string $reason): void
    {
        Gate::forUser($user)->authorize('update', $workspace);
        $workspace->update(['status' => 'closed']);
        NetworkSignals::record('workspace.closed', $workspace, $workspace->institution_id, ['reason' => $reason]);
    }
}
