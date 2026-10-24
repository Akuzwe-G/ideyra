<?php

namespace App\Support;

use App\Models\AchievementVerification;
use App\Models\Institution;
use App\Models\ProfessionalProfile;
use App\Models\Project;
use App\Models\ProjectShowcase;
use App\Models\User;

class CareerAccess
{
    public static function own(User $user, int $ownerId): bool
    {
        return $user->status === 'active' && $user->id === $ownerId;
    }

    public static function reviewer(User $user, Institution $institution, ?Project $project = null): bool
    {
        $member = InstitutionAccess::membership($institution, $user);
        if (! $member || ! in_array($member->role->name, ['Institution Administrator', 'Lecturer / Teacher', 'Mentor / Supervisor'], true)
            || ! InnovationAccess::can($institution, 'career.verify', $user)) {
            return false;
        }

        return $member->role->name === 'Institution Administrator'
            || ($project && InnovationAccess::supervisor($project, $user));
    }

    public static function canReview(AchievementVerification $verification, User $user): bool
    {
        return $user->id !== $verification->user_id
            && self::reviewer($user, $verification->institution, $verification->showcase?->project)
            && InstitutionAccess::membership($verification->institution, $verification->user) !== null;
    }

    public static function role(Project $project, User $user): ?string
    {
        if (! InnovationAccess::projects($user)->whereKey($project->id)->exists()) {
            return null;
        }
        if ($project->owner_id === $user->id) {
            return 'Project creator';
        }
        $role = $project->members()->where('user_id', $user->id)->value('role');
        if ($role) {
            return match ($role) {
                'leader' => 'Project leader', 'supervisor' => 'Supervisor', 'contributor' => 'Contributor', default => 'Team member'
            };
        }
        if ($project->group) {
            $membership = InstitutionAccess::membership($project->institution, $user);
            $groupRole = $project->group->members()->where('membership_id', $membership?->id)->value('role');
            if ($groupRole) {
                return $groupRole === 'leader' ? 'Project leader' : 'Team member';
            }
            if ($project->group->supervisors()->where('memberships.id', $membership?->id)->exists()) {
                return 'Supervisor';
            }
        }

        return null;
    }

    public static function projectContent(ProjectShowcase $showcase): ?array
    {
        $project = $showcase->project;
        $user = $showcase->profile->user;
        $role = self::role($project, $user);
        if (! $role || $user->status !== 'active') {
            return null;
        }

        return ['showcase_id' => $showcase->id, 'project_id' => $project->id, 'title' => $project->title, 'description' => $project->description,
            'problem' => $project->problem, 'solution' => $project->outcomes, 'category' => $project->category, 'role' => $role,
            'status' => $project->status, 'completed_at' => $project->completed_at?->toDateString(), 'technologies' => $showcase->technologies,
            'links' => $showcase->links ?? [], 'reflection' => $showcase->reflection, 'institution_id' => $project->institution_id,
            'institution' => $project->institution?->name];
    }

    public static function publicProject(ProjectShowcase $showcase, ?array $expected = null): ?array
    {
        $content = self::projectContent($showcase);
        if (! $content || ($expected && $expected !== $content)) {
            return null;
        }
        if (! $showcase->project->institution_id) {
            return $showcase->project->owner_id === $showcase->profile->user_id ? $content : null;
        }
        $verifications = $showcase->relationLoaded('verifications') ? $showcase->verifications : $showcase->verifications()->where('category', 'academic_project')->where('status', 'verified')->where('public_allowed', true)->get();
        $approved = $verifications->contains(fn ($v) => $v->category === 'academic_project' && $v->status === 'verified' && $v->public_allowed && $v->institution_id === $showcase->project->institution_id && $v->approved_content === $content);

        return $approved ? $content : null;
    }

    public static function publicVerification(AchievementVerification $verification): bool
    {
        $profile = ProfessionalProfile::where('user_id', $verification->user_id)->with('portfolio', 'user')->first();
        if (! $profile || $profile->user->status !== 'active' || ! $profile->portfolio?->published || ! $verification->public_allowed
            || $verification->institution->status !== 'active') {
            return false;
        }
        $payload = $profile->portfolio->payload ?? [];
        if ($verification->project_showcase_id && (! $verification->showcase || self::projectContent($verification->showcase) === null)) {
            return false;
        }

        return collect($payload['verifications'] ?? [])->contains(fn ($v) => $v['reference'] === $verification->reference);
    }

    public static function report(Institution $institution, User $user): bool
    {
        return InnovationAccess::can($institution, 'career.report', $user);
    }
}
