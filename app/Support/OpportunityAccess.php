<?php

namespace App\Support;

use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\OpportunityApplication;
use App\Models\Project;
use App\Models\SponsorshipRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OpportunityAccess
{
    public static function permission(Opportunity $opportunity, string $key, User $user): bool
    {
        return $opportunity->institution_id ? InnovationAccess::can($opportunity->institution, $key, $user) : InstitutionAccess::system($user);
    }

    public static function manages(Opportunity $opportunity, User $user, string $key = 'opportunities.manage'): bool
    {
        if (! self::permission($opportunity, $key, $user) || ($opportunity->institution_id && ! self::permission($opportunity, 'opportunities.view', $user))) {
            return false;
        }

        return ! $opportunity->institution_id || $opportunity->created_by === $user->id || $opportunity->managers()->whereKey($user->id)->exists() || self::permission($opportunity, 'opportunities.manage_all', $user);
    }

    public static function managed(User $user, string $permission = 'opportunities.manage'): Builder
    {
        return Opportunity::query()->where(function ($query) use ($user, $permission): void {
            $query->whereRaw('1=0');
            if (InstitutionAccess::system($user)) {
                $query->orWhereNull('opportunities.institution_id');
            }
            foreach (InnovationAccess::memberships($user) as $member) {
                if (! InnovationAccess::can($member->institution, 'opportunities.view', $user) || ! InnovationAccess::can($member->institution, $permission, $user)) {
                    continue;
                }
                $query->orWhere(function ($scope) use ($user, $member): void {
                    $scope->where('opportunities.institution_id', $member->institution_id);
                    if (! InnovationAccess::can($member->institution, 'opportunities.manage_all', $user)) {
                        $scope->where(fn ($assigned) => $assigned->where('created_by', $user->id)->orWhereHas('managers', fn ($m) => $m->where('users.id', $user->id)));
                    }
                });
            }
        });
    }

    public static function audienceIds(User $user, ?int $institutionId = null): array
    {
        $members = InnovationAccess::memberships($user)->when($institutionId, fn ($list) => $list->where('institution_id', $institutionId));
        $classes = DB::table('class_assignments as a')->join('academic_classes as c', 'c.id', '=', 'a.academic_class_id')->whereIn('a.membership_id', $members->pluck('id'))->get(['c.id', 'c.programme_id', 'c.academic_year_id', 'c.intake_id']);

        return ['role' => $members->pluck('role_id')->unique()->all(), 'class' => $classes->pluck('id')->unique()->all(), 'programme' => $classes->pluck('programme_id')->unique()->all(), 'academic_year' => $classes->pluck('academic_year_id')->unique()->all(), 'intake' => $classes->pluck('intake_id')->unique()->all(), 'group' => DB::table('group_members')->whereIn('membership_id', $members->pluck('id'))->pluck('group_id')->all()];
    }

    public static function visible(User $user, bool $includeManagement = true): Builder
    {
        $members = InnovationAccess::memberships($user)->filter(fn ($m) => InnovationAccess::can($m->institution, 'opportunities.view', $user));
        $query = Opportunity::query()->where(function ($query) use ($user, $members, $includeManagement): void {
            $query->where(function ($published) use ($user, $members): void {
                $published->whereIn('opportunities.status', ['published', 'closed'])->where(function ($access) use ($user, $members): void {
                    $access->where(fn ($platform) => $platform->whereNull('opportunities.institution_id')->where('visibility', 'platform'));
                    foreach ($members as $member) {
                        $ids = self::audienceIds($user, $member->institution_id);
                        $access->orWhere(function ($institution) use ($member, $ids): void {
                            $institution->where('opportunities.institution_id', $member->institution_id)->where(function ($audience) use ($ids): void {
                                $audience->where('visibility', 'institution')->orWhere(function ($restricted) use ($ids): void {
                                    $restricted->where('visibility', 'restricted')->whereExists(function ($targets) use ($ids): void {
                                        $targets->selectRaw('1')->from('opportunity_audiences as audience')->whereColumn('audience.opportunity_id', 'opportunities.id')->where(function ($match) use ($ids): void {
                                            $match->whereRaw('1=0');
                                            foreach ($ids as $kind => $values) {
                                                $match->orWhere(fn ($item) => $item->where('audience.kind', $kind)->whereIn('audience.reference_id', $values));
                                            }
                                        });
                                    });
                                });
                            });
                        });
                    }
                });
            });
            if ($includeManagement) {
                if (InstitutionAccess::system($user)) {
                    $query->orWhereNull('opportunities.institution_id');
                }
                foreach ($members as $member) {
                    $canManage = InnovationAccess::can($member->institution, 'opportunities.manage', $user);
                    $canEdit = InnovationAccess::can($member->institution, 'opportunities.edit_own', $user) || InnovationAccess::can($member->institution, 'opportunities.create', $user);
                    if (! $canManage && ! $canEdit) {
                        continue;
                    }
                    $query->orWhere(function ($manage) use ($user, $member, $canManage): void {
                        $manage->where('opportunities.institution_id', $member->institution_id);
                        if (! $canManage || ! InnovationAccess::can($member->institution, 'opportunities.manage_all', $user)) {
                            $manage->where(fn ($assigned) => $assigned->where('created_by', $user->id)->when($canManage, fn ($q) => $q->orWhereHas('managers', fn ($m) => $m->where('users.id', $user->id))));
                        }
                    });
                }
            }
        });

        return $user->status === 'active' ? $query : $query->whereRaw('1=0');
    }

    public static function eligible(Opportunity $opportunity, User $user, int $institutionId): bool
    {
        $member = Membership::with('institution', 'role')->where('institution_id', $institutionId)->where('user_id', $user->id)->where('status', 'active')->first();
        if (! $member || $member->role->name !== 'Student' || ! InnovationAccess::can($member->institution, 'opportunities.apply', $user) || ! self::visible($user, false)->whereKey($opportunity->id)->exists() || ! $opportunity->isOpen()) {
            return false;
        }
        if ($opportunity->institution_id && $opportunity->institution_id !== $institutionId) {
            return false;
        }
        $ids = self::audienceIds($user, $institutionId);
        foreach ($opportunity->eligibility ?? [] as $kind => $allowed) {
            if ($allowed && empty(array_intersect($ids[$kind] ?? [], $allowed))) {
                return false;
            }
        }

        return true;
    }

    public static function applications(User $user): Builder
    {
        return OpportunityApplication::query()->where(function ($query) use ($user): void {
            $query->where(function ($own) use ($user): void {
                $own->where('applicant_id', $user->id)->whereIn('institution_id', InnovationAccess::memberships($user)->pluck('institution_id'));
            })->orWhere(function ($participant) use ($user): void {
                $participant->whereIn('institution_id', InnovationAccess::memberships($user)->pluck('institution_id'))->whereExists(fn ($c) => $c->selectRaw('1')->from('opportunity_consents')->whereColumn('application_id', 'opportunity_applications.id')->where('user_id', $user->id))
                    ->where(fn ($history) => $history->whereNotIn('status', ['draft', 'more_information_requested'])->orWhereHas('group.members', fn ($member) => $member->whereIn('membership_id', InnovationAccess::memberships($user)->pluck('id'))));
            });
            $ids = self::managed($user, 'opportunities.review_applications')->select('opportunities.id');
            $query->orWhere(fn ($managed) => $managed->whereIn('opportunity_id', $ids)->whereNotNull('submitted_at'));
        })->when($user->status !== 'active', fn ($q) => $q->whereRaw('1=0'));
    }

    public static function reviews(OpportunityApplication $application, User $user): bool
    {
        return ! $application->external_self_reported && ! in_array($user->id, $application->participant_ids ?? [$application->applicant_id], true) && $application->applicant_id !== $user->id && self::manages($application->opportunity, $user, 'opportunities.review_applications');
    }

    public static function staff(Project $project, User $user, string $key): bool
    {
        if (! InnovationAccess::projects($user)->whereKey($project->id)->exists()) {
            return false;
        }
        $members = InnovationAccess::memberships($user)->filter(fn ($m) => in_array($m->role->name, ['Institution Administrator', 'Lecturer / Teacher', 'Mentor / Supervisor'], true));
        if ($project->institution_id) {
            $members = $members->where('institution_id', $project->institution_id);
        } elseif (! InnovationAccess::supervisor($project, $user)) {
            return false;
        }
        $aliases = match ($key) {
            'sponsorships.manage' => ['sponsorships.manage', 'projects.manage_sponsorship'],'sponsorships.review' => ['sponsorships.review', 'projects.review_supported'],default => [$key]
        };
        foreach ($members as $member) {
            $denied = DB::table('membership_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')->where('membership_id', $member->id)->whereIn('permissions.name', $aliases)->where('allowed', false)->exists();
            if (! $denied && array_intersect($aliases, InstitutionAccess::effective($member))) {
                return true;
            }
        }

        return false;
    }

    public static function sponsorships(User $user): Builder
    {
        return SponsorshipRequest::whereIn('project_id', InnovationAccess::projects($user)->select('projects.id'));
    }
}
