<?php

namespace App\Support;

use App\Models\ChallengeSubmission;
use App\Models\CollaborationMember;
use App\Models\CollaborationRequest;
use App\Models\CollaborationWorkspace;
use App\Models\InnovationChallenge;
use App\Models\InnovationEvent;
use App\Models\Institution;
use App\Models\NetworkPublication;
use App\Models\NetworkSetting;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class NetworkAccess
{
    public static function can(User $user, ?Institution $institution, string $permission): bool
    {
        return $institution && InnovationAccess::can($institution, $permission, $user);
    }

    public static function platform(User $user, string $permission = 'moderation.review_platform'): bool
    {
        return InstitutionAccess::system($user) && $user->roles()->where('scope', 'platform')->whereJsonContains('permissions', $permission)->exists();
    }

    public static function setting(int $institutionId): NetworkSetting
    {
        return NetworkSetting::firstOrNew(['institution_id' => $institutionId], ['allow_network' => false, 'allow_public' => false, 'require_collaboration_approval' => true]);
    }

    public static function participating(User $user): array
    {
        return InnovationAccess::memberships($user)->filter(fn ($m) => self::setting($m->institution_id)->allow_network)->pluck('institution_id')->map(fn ($id) => (int) $id)->all();
    }

    public static function representative(Project $project, User $user): bool
    {
        if ($user->status !== 'active' || ! InnovationAccess::projects($user)->whereKey($project->id)->exists()) {
            return false;
        }
        if ($project->owner_id === $user->id) {
            return true;
        }
        if (! $project->group_id) {
            return false;
        }
        $member = InstitutionAccess::membership($project->institution, $user);

        return $member && $project->group->members()->where('membership_id', $member->id)->where('role', 'leader')->exists();
    }

    public static function contributors(Project $project): array
    {
        return InnovationAccess::participants($project)->pluck('id')->all();
    }

    public static function publicationEdit(User $user, NetworkPublication $publication): bool
    {
        return self::representative($publication->project, $user) && self::can($user, $publication->institution, 'showcase.submit');
    }

    public static function publicationReview(User $user, NetworkPublication $publication): bool
    {
        return $user->id !== $publication->submitted_by && ! in_array($user->id, self::contributors($publication->project), true) && self::can($user, $publication->institution, 'showcase.review');
    }

    public static function publicationUnpublish(User $user, NetworkPublication $publication): bool
    {
        return self::publicationEdit($user, $publication) || self::can($user, $publication->institution, 'showcase.unpublish') || self::platform($user);
    }

    public static function publications(?User $user): Builder
    {
        $q = NetworkPublication::where('status', 'published')->whereColumn('version', 'approved_version')->whereNotNull('approved_snapshot')->where('published_at', '<=', now())->whereHas('institution', fn ($i) => $i->where('status', 'active'));

        return $q->where(function ($visible) use ($user): void {
            $visible->where(fn ($p) => $p->where('visibility', 'public')->whereIn('institution_id', NetworkSetting::where('allow_public', true)->select('institution_id')));
            if ($user && $user->status === 'active') {
                $institutions = InnovationAccess::memberships($user)->pluck('institution_id');
                $visible->orWhere(fn ($p) => $p->where('visibility', 'institution')->whereIn('institution_id', $institutions));
                $visible->orWhere(fn ($p) => $p->where('visibility', 'private')->whereIn('project_id', InnovationAccess::projects($user)->select('projects.id')));
                if (self::platform($user) || self::participating($user)) {
                    $visible->orWhere(fn ($p) => $p->where('visibility', 'network')->whereIn('institution_id', NetworkSetting::where('allow_network', true)->select('institution_id')));
                }
            }
        });
    }

    public static function publicationVisible(?User $user, NetworkPublication $publication): bool
    {
        return self::publications($user)->whereKey($publication->id)->exists();
    }

    public static function requestView(User $user, CollaborationRequest $request): bool
    {
        if ($user->status !== 'active') {
            return false;
        }
        $own = $request->user_id === $user->id && InstitutionAccess::membership($request->institution, $user);
        if ($request->status === 'draft') {
            return (bool) $own;
        }
        $representative = self::publicationEdit($user, $request->publication);
        $review = self::can($user, $request->institution, 'collaborations.review') || self::can($user, $request->publication->institution, 'collaborations.review');

        return (bool) ($own || $representative || $review);
    }

    public static function member(User $user, CollaborationWorkspace $workspace, bool $admin = false): ?CollaborationMember
    {
        if ($user->status !== 'active' || ! in_array($workspace->status, ['active', 'closed'], true)) {
            return null;
        }
        $member = $workspace->members()->where('user_id', $user->id)->where('status', 'active')->when($admin, fn ($q) => $q->where('role', 'admin'))->first();
        if (! $member || ! in_array($member->institution_id, $workspace->institution_ids, true) || ! InstitutionAccess::membership($member->institution, $user)) {
            return null;
        }
        foreach ($workspace->institution_ids as $id) {
            if (! self::setting($id)->allow_network || ! Institution::whereKey($id)->where('status', 'active')->exists()) {
                return null;
            }
        }

        return $member;
    }

    public static function blocked(User $user, int $institutionId): bool
    {
        return DB::table('network_blocks')->where('user_id', $user->id)->where('expires_at', '>', now())->where(fn ($q) => $q->whereNull('institution_id')->orWhere('institution_id', $institutionId))->exists();
    }

    public static function challengeManage(User $user, InnovationChallenge $challenge, string $permission = 'challenges.manage'): bool
    {
        return self::can($user, $challenge->institution, $permission);
    }

    public static function judge(User $user, InnovationChallenge $challenge): bool
    {
        return $user->status === 'active' && self::judgeChallenges($user)->whereKey($challenge->id)->exists();
    }

    public static function judgeChallenges(User $user): Builder
    {
        $members = InnovationAccess::memberships($user)->filter(fn ($m) => self::can($user, $m->institution, 'challenges.judge'));
        $own = $members->pluck('institution_id');
        $cross = $members->contains(fn ($m) => self::setting($m->institution_id)->allow_network);

        return InnovationChallenge::whereHas('institution', fn ($q) => $q->where('status', 'active'))->whereHas('judges', fn ($q) => $q->where('user_id', $user->id)->where('active', true))->where(function ($q) use ($own, $cross): void {
            $q->whereIn('institution_id', $own);
            if ($cross) {
                $q->orWhereIn('institution_id', NetworkSetting::where('allow_network', true)->select('institution_id'));
            }
        });
    }

    public static function workspaces(User $user, bool $invitations = false): Builder
    {
        $institutions = InnovationAccess::memberships($user)->pluck('institution_id');
        $enabled = NetworkSetting::where('allow_network', true)->select('institution_id');

        return CollaborationWorkspace::whereHas('institution', fn ($q) => $q->where('status', 'active'))->whereHas('request.institution', fn ($q) => $q->where('status', 'active'))->whereIn('status', ['active', 'closed'])->whereIn('institution_id', clone $enabled)->whereHas('request', fn ($q) => $q->whereIn('institution_id', clone $enabled))->whereHas('members', fn ($q) => $q->where('user_id', $user->id)->whereIn('institution_id', $institutions)->whereIn('status', $invitations ? ['active', 'invited'] : ['active']));
    }

    public static function challenges(?User $user, bool $management = true): Builder
    {
        return InnovationChallenge::whereHas('institution', fn ($q) => $q->where('status', 'active'))->where(function ($q) use ($user, $management): void {
            $q->where(fn ($visible) => $visible->whereIn('status', ['published', 'closed', 'evaluation', 'results_published'])->whereHas('opportunity', fn ($o) => $o->whereIn('status', ['published', 'closed']))->where(function ($v) use ($user): void {
                $v->where(fn ($p) => $p->where('visibility', 'public')->whereIn('institution_id', NetworkSetting::where('allow_public', true)->select('institution_id')));
                if ($user && $user->status === 'active') {
                    $v->orWhere(fn ($p) => $p->where('visibility', 'institution')->whereIn('institution_id', InnovationAccess::memberships($user)->pluck('institution_id')));
                    if (self::participating($user) || self::platform($user)) {
                        $v->orWhere(fn ($p) => $p->where('visibility', 'network')->whereIn('institution_id', NetworkSetting::where('allow_network', true)->select('institution_id')));
                    }
                }
            }));
            if ($user && $management && $user->status === 'active') {
                $ids = InnovationAccess::memberships($user)->filter(fn ($m) => self::can($user, $m->institution, 'challenges.manage') || self::can($user, $m->institution, 'challenges.create') || self::can($user, $m->institution, 'challenges.publish'))->pluck('institution_id');
                $q->orWhereIn('institution_id', $ids)->orWhereIn('id', self::judgeChallenges($user)->select('innovation_challenges.id'));
            }
        });
    }

    public static function events(?User $user, bool $management = true): Builder
    {
        return InnovationEvent::whereHas('institution', fn ($q) => $q->where('status', 'active'))->where(function ($q) use ($user, $management): void {
            $q->where(fn ($p) => $p->whereIn('status', ['published', 'completed'])->whereHas('opportunity', fn ($o) => $o->whereIn('status', ['published', 'closed']))->where(function ($v) use ($user): void {
                $v->where(fn ($p) => $p->where('visibility', 'public')->whereIn('institution_id', NetworkSetting::where('allow_public', true)->select('institution_id')));
                if ($user && $user->status === 'active') {
                    $v->orWhere(fn ($p) => $p->where('visibility', 'institution')->whereIn('institution_id', InnovationAccess::memberships($user)->pluck('institution_id')));
                    if (self::participating($user) || self::platform($user)) {
                        $v->orWhere(fn ($p) => $p->where('visibility', 'network')->whereIn('institution_id', NetworkSetting::where('allow_network', true)->select('institution_id')));
                    }
                }
            }));
            if ($user && $management && $user->status === 'active') {
                $ids = InnovationAccess::memberships($user)->filter(fn ($m) => self::can($user, $m->institution, 'events.manage') || self::can($user, $m->institution, 'events.create') || self::can($user, $m->institution, 'events.publish'))->pluck('institution_id');
                $q->orWhereIn('institution_id', $ids);
            }
        });
    }

    public static function eventManage(User $user, InnovationEvent $event, string $permission = 'events.manage'): bool
    {
        return self::can($user, $event->institution, $permission);
    }

    public static function submission(User $user, ChallengeSubmission $submission): bool
    {
        $app = $submission->application;
        $challenge = $submission->challenge;
        if ($user->status !== 'active') {
            return false;
        }
        if ($app->collaboration_workspace_id) {
            $w = CollaborationWorkspace::find($app->collaboration_workspace_id);
            $own = $w && self::member($user, $w) && in_array($user->id, $app->participant_ids ?? [], true);
        } else {
            $own = InstitutionAccess::membership($app->institution, $user) && in_array($user->id, $app->participant_ids ?? [], true);
        }

        return (bool) ($own || self::challengeManage($user,$challenge) || (self::judge($user,$challenge) && in_array($challenge->status,['evaluation', 'results_published'],true) && ! app(ChallengeWorkflow::class)->conflict($user,$submission)));
    }

    public static function publicScopeAllowed(Institution $institution,string $visibility): bool
    {
        return $institution->status === 'active' && match ($visibility) {
            'network' => self::setting($institution->id)->allow_network,'public' => self::setting($institution->id)->allow_public,default => in_array($visibility,['private', 'institution'],true)
        };
    }
}
