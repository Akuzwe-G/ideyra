<?php

namespace App\Support;

use App\Models\GroupMember;
use App\Models\Idea;
use App\Models\Institution;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InnovationAccess
{
    public static function memberships(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->memberships()->with('role', 'institution')->where('status', 'active')->whereHas('institution', fn ($q) => $q->where('status', 'active'))->get();
    }

    public static function can(?Institution $institution, string $permission, User $user, array $delegated = []): bool
    {
        if ($user->status !== 'active' || ! $institution) {
            return false;
        }
        $member = InstitutionAccess::membership($institution, $user);
        if (! $member) {
            return false;
        }
        $keys = match ($permission) {
            'sponsorships.request' => ['sponsorships.request', 'projects.sponsor'],
            'projects.supervise' => ['projects.supervise', 'academic.supervise_projects'],
            'deliverables.review' => ['deliverables.review', 'academic.review_work'],
            default => [$permission],
        };
        $denied = DB::table('membership_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')
            ->where('membership_id', $member->id)->whereIn('permissions.name', $keys)->where('allowed', false)->exists();

        return ! $denied && count(array_intersect($keys, array_merge(InstitutionAccess::effective($member), $delegated))) > 0;
    }

    public static function ideas(User $user): Builder
    {
        return Idea::query()->where(function ($query) use ($user): void {
            $query->where(fn ($personal) => $personal->whereNull('institution_id')->where(fn ($own) => $own->where('owner_id', $user->id)->orWhere(fn ($shared) => $shared->where('visibility', 'shared')->whereHas('collaborators', fn ($q) => $q->where('users.id', $user->id)))));
            foreach (self::memberships($user) as $membership) {
                $query->orWhere(function ($q) use ($user, $membership): void {
                    $q->where('institution_id', $membership->institution_id)->where(function ($visible) use ($user, $membership): void {
                        $visible->whereRaw('1 = 0');
                        if (self::can($membership->institution, 'ideas.view_own', $user)) {
                            $visible->orWhere('owner_id', $user->id);
                        }
                        if (self::can($membership->institution, 'ideas.view_shared', $user)) {
                            $visible->orWhere('visibility', 'institutional')->orWhere(fn ($shared) => $shared->where('visibility', 'shared')->whereHas('collaborators', fn ($users) => $users->where('users.id', $user->id)));
                        }
                    });
                });
            }
        });
    }

    public static function groups(User $user): Builder
    {
        return ProjectGroup::query()->where(function ($query) use ($user): void {
            $query->whereRaw('1 = 0');
            foreach (self::memberships($user) as $membership) {
                if (! self::can($membership->institution, 'groups.view_assigned', $user)) {
                    continue;
                }
                $query->orWhere(function ($q) use ($membership, $user): void {
                    $q->where('institution_id', $membership->institution_id);
                    if (! self::can($membership->institution, 'groups.view_all', $user)) {
                        $q->where(fn ($scope) => $scope->where('created_by', $user->id)->orWhereHas('members', fn ($m) => $m->where('membership_id', $membership->id))->orWhereHas('supervisors', fn ($s) => $s->where('memberships.id', $membership->id)));
                    }
                });
            }
        });
    }

    public static function projects(User $user): Builder
    {
        return Project::query()->where(function ($query) use ($user): void {
            $query->where(fn ($q) => $q->whereNull('institution_id')->where(fn ($p) => $p->where('owner_id', $user->id)->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id))));
            foreach (self::memberships($user) as $membership) {
                if (! self::can($membership->institution, 'projects.view_assigned', $user)) {
                    continue;
                }
                $query->orWhere(function ($q) use ($user, $membership): void {
                    $q->where('institution_id', $membership->institution_id);
                    if (! self::can($membership->institution, 'projects.view_all', $user)) {
                        $q->where(fn ($p) => $p->where('owner_id', $user->id)->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id))
                            ->orWhereHas('group.members', fn ($m) => $m->where('membership_id', $membership->id))
                            ->orWhereHas('group.supervisors', fn ($m) => $m->where('memberships.id', $membership->id)));
                    }
                });
            }
        });
    }

    public static function delegated(ProjectGroup $group, User $user): array
    {
        if (in_array($group->status, ['completed', 'archived'], true)) {
            return [];
        }
        $membership = InstitutionAccess::membership($group->institution, $user);

        return $membership ? (GroupMember::where('group_id', $group->id)->where('membership_id', $membership->id)->where('role', 'leader')->value('delegated_permissions') ?? []) : [];
    }

    public static function groupManage(ProjectGroup $group, User $user, string $permission = 'groups.manage_assigned'): bool
    {
        if (! self::groups($user)->whereKey($group->id)->exists()) {
            return false;
        }
        $delegated = self::delegated($group, $user);
        $assignedManager = (int) $group->created_by === (int) $user->id || ! empty($delegated);

        return self::can($group->institution, $permission, $user, $delegated) && ($assignedManager || self::can($group->institution, 'groups.manage_all', $user));
    }

    public static function projectManage(Project $project, User $user): bool
    {
        if (! self::projects($user)->whereKey($project->id)->exists()) {
            return false;
        }
        if (! $project->institution_id) {
            return (int) $project->owner_id === (int) $user->id;
        }
        if ((int) $project->owner_id === (int) $user->id && self::can($project->institution, 'projects.edit_own', $user)) {
            return true;
        }
        $delegated = $project->group ? self::delegated($project->group, $user) : [];
        $leader = $project->members()->where('user_id', $user->id)->where('role', 'leader')->exists() || ! empty($delegated);

        return self::can($project->institution, 'projects.manage_assigned', $user, $delegated) && ($leader || self::can($project->institution, 'projects.view_all', $user));
    }

    public static function supervisor(Project $project, User $user): bool
    {
        if (! self::projects($user)->whereKey($project->id)->exists()) {
            return false;
        }
        $assigned = $project->members()->where('user_id', $user->id)->where('role', 'supervisor')->exists();
        if ($project->group) {
            $member = InstitutionAccess::membership($project->institution, $user);
            $assigned = $assigned || ($member && $project->group->supervisors()->where('memberships.id', $member->id)->exists());
        }

        return $assigned && (! $project->institution_id || self::can($project->institution, 'projects.supervise', $user));
    }

    public static function supervised(User $user): Builder
    {
        $membershipIds = self::memberships($user)->filter(fn ($m) => self::can($m->institution, 'projects.supervise', $user))->pluck('id');
        $institutionIds = self::memberships($user)->filter(fn ($m) => self::can($m->institution, 'projects.supervise', $user))->pluck('institution_id');

        return self::projects($user)->where(function ($query) use ($user, $membershipIds, $institutionIds): void {
            $query->where(fn ($q) => $q->whereNull('projects.institution_id')->whereHas('members', fn ($m) => $m->where('user_id', $user->id)->where('role', 'supervisor')))
                ->orWhere(fn ($q) => $q->whereIn('projects.institution_id', $institutionIds)->where(fn ($assigned) => $assigned->whereHas('members', fn ($m) => $m->where('user_id', $user->id)->where('role', 'supervisor'))->orWhereHas('group.supervisors', fn ($m) => $m->whereIn('memberships.id', $membershipIds))));
        });
    }

    public static function reviewDeliverables(Project $project, User $user): bool
    {
        if (! self::projects($user)->whereKey($project->id)->exists()) {
            return false;
        }
        if (! $project->institution_id) {
            return self::supervisor($project, $user) || (int) $project->owner_id === (int) $user->id;
        }

        return self::can($project->institution, 'deliverables.review', $user)
            && (self::supervisor($project, $user) || self::can($project->institution, 'deliverables.review_all', $user));
    }

    public static function participants(Project $project): Collection
    {
        $ids = collect([$project->owner_id])->merge($project->members()->pluck('user_id'));
        if ($project->group) {
            $ids = $ids->merge($project->group->members()->with('membership')->get()->pluck('membership.user_id'))
                ->merge($project->group->supervisors()->pluck('memberships.user_id'));
        }

        return User::whereIn('id', $ids->filter()->unique())->where('status', 'active')->get()->filter(fn ($user) => self::projects($user)->whereKey($project->id)->exists())->values();
    }

    public static function projectCapability(Project $project, string $permission, User $user): bool
    {
        return ! $project->institution_id ? self::projectManage($project, $user) : self::can($project->institution, $permission, $user, $project->group ? self::delegated($project->group, $user) : []);
    }
}
