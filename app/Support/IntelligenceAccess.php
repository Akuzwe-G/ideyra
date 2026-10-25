<?php

namespace App\Support;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class IntelligenceAccess
{
    public static function permits(User $user, array $filters, string $permission): bool
    {
        try {
            app(self::class)->context($user, $filters, $permission);

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    public static function platform(User $user, string $permission = 'analytics.view_platform'): bool
    {
        return InstitutionAccess::system($user) && $user->roles()->where('name', 'System Administrator')->where('scope', 'platform')->whereJsonContains('permissions', $permission)->exists();
    }

    public static function institution(User $user, Institution $institution, string $permission = 'analytics.view_institution'): bool
    {
        $member = InstitutionAccess::membership($institution, $user);
        if (! $member || $member->role->name === 'Student') {
            return false;
        }

        return InnovationAccess::can($institution, $permission, $user);
    }

    public static function canEnter(User $user, Institution $institution): bool
    {
        return self::institution($user, $institution) || self::institution($user, $institution, 'analytics.view_programme');
    }

    /** @return array{institution_id: ?int, platform: bool, class_ids: ?array, user_id: int} */
    public function context(User $user, array $filters, string $permission = 'analytics.view'): array
    {
        $id = empty($filters['institution_id']) ? null : (int) $filters['institution_id'];
        $platform = InstitutionAccess::system($user);
        if ($platform) {
            abort_unless(self::platform($user), 403);
            if ($permission !== 'analytics.view') {
                abort_unless(self::platform($user, $permission.'_platform'), 403);
            }
            if ($id) {
                Institution::findOrFail($id);
            }

            return ['institution_id' => $id, 'platform' => true, 'class_ids' => null, 'user_id' => $user->id];
        }
        $institution = Institution::findOrFail($id);
        abort_unless(self::canEnter($user, $institution), 403);
        if ($permission !== 'analytics.view') {
            abort_unless(self::institution($user, $institution, $permission), 403);
        }
        $classes = null;
        $member = InstitutionAccess::membership($institution, $user);
        if (! self::institution($user, $institution) || in_array($member?->role->name, ['Lecturer / Teacher', 'Mentor / Supervisor'], true)) {
            $member = InstitutionAccess::membership($institution, $user);
            $classes = DB::table('class_assignments')->where('institution_id', $id)->where('membership_id', $member->id)->whereIn('assignment', ['lecturer', 'mentor', 'supervisor'])->pluck('academic_class_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
            $groupClasses = DB::table('group_supervisors as gs')->join('project_groups as g', 'g.id', '=', 'gs.group_id')->where('gs.membership_id', $member->id)->where('g.institution_id', $id)->pluck('g.academic_class_id')->all();
            $projectClasses = DB::table('project_members as pm')->join('projects as p', 'p.id', '=', 'pm.project_id')->where('pm.user_id', $user->id)->where('pm.role', 'supervisor')->where('p.institution_id', $id)->whereNotNull('p.academic_class_id')->pluck('p.academic_class_id')->all();
            $classes = collect([...$classes, ...$groupClasses, ...$projectClasses])->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
            abort_if(! $classes, 403, 'No authorised academic classes are assigned.');
        }
        if (! empty($filters['academic_class_id']) && $classes !== null) {
            abort_unless(in_array((int) $filters['academic_class_id'], $classes, true), 403);
        }

        return ['institution_id' => $id, 'platform' => false, 'class_ids' => $classes, 'user_id' => $user->id];
    }

    public static function kpi(User $user, int $institutionId, string $permission): bool
    {
        $institution = Institution::find($institutionId);
        if ($institution && in_array(InstitutionAccess::membership($institution, $user)?->role->name, ['Student', 'Lecturer / Teacher', 'Mentor / Supervisor'], true)) {
            return false;
        }

        return $institution && self::institution($user, $institution) && self::institution($user, $institution, $permission);
    }
}
