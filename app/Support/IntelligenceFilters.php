<?php

namespace App\Support;

use App\Http\Requests\IntelligenceFilterRequest;
use App\Models\Institution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class IntelligenceFilters
{
    public function normalise(User $user, array $input): array
    {
        $data = Validator::make($input, IntelligenceFilterRequest::filterRules())->validate();
        $tz = config('intelligence.timezone');
        $now = CarbonImmutable::now($tz);
        $data['from'] ??= $now->subMonths(5)->startOfMonth()->toDateString();
        $data['to'] ??= $now->toDateString();
        $from = CarbonImmutable::parse($data['from'], $tz);
        $to = CarbonImmutable::parse($data['to'], $tz);
        if ($from->gt($to) || $from->diffInDays($to) > 1096) {
            throw ValidationException::withMessages(['from' => 'Choose an ordered period of at most three years.']);
        }
        $data['group'] ??= 'monthly';
        $data['compare'] = (bool) ($data['compare'] ?? false);
        $data['cohort_days'] = (int) ($data['cohort_days'] ?? 30);
        $data['section'] ??= 'overview';
        if (! InstitutionAccess::system($user)) {
            abort_if(($data['scope'] ?? 'institution') === 'platform', 403);
            if (empty($data['institution_id'])) {
                $member = InnovationAccess::memberships($user)->first(fn ($m) => IntelligenceAccess::canEnter($user, $m->institution));
                abort_unless($member, 403);
                $data['institution_id'] = $member->institution_id;
            }
            $data['scope'] = 'institution';
        } else {
            $data['scope'] = 'platform';
        }
        foreach (['institution_id', 'academic_year_id', 'programme_id', 'intake_id', 'academic_class_id', 'project_group_id', 'supervisor_id', 'opportunity_category_id'] as $key) {
            if (! empty($data[$key])) {
                $data[$key] = (int) $data[$key];
            } else {
                unset($data[$key]);
            }
        }
        $context = app(IntelligenceAccess::class)->context($user, $data);
        foreach (['academic_year_id' => 'academic_years', 'programme_id' => 'programmes', 'intake_id' => 'intakes', 'academic_class_id' => 'academic_classes', 'project_group_id' => 'project_groups'] as $key => $table) {
            if (! empty($data[$key])) {
                abort_unless($context['institution_id'] && DB::table($table)->where('id', $data[$key])->where('institution_id', $context['institution_id'])->exists(), 422, 'Choose academic filters from the selected institution.');
            }
        }
        if (! empty($data['supervisor_id'])) {
            abort_unless($context['institution_id'] && DB::table('memberships')->where('institution_id', $context['institution_id'])->where('user_id', $data['supervisor_id'])->where('status', 'active')->exists(), 422);
        }
        if (! empty($data['opportunity_category_id'])) {
            abort_unless(DB::table('opportunity_categories')->where('id', $data['opportunity_category_id'])->exists(), 422);
        }

        return $data;
    }

    public function window(Builder $query, array $filters, string $column): Builder
    {
        $tz = config('intelligence.timezone');

        return $query->where($column, '>=', CarbonImmutable::parse($filters['from'], $tz)->startOfDay()->utc()->toDateTimeString())->where($column, '<', CarbonImmutable::parse($filters['to'], $tz)->addDay()->startOfDay()->utc()->toDateTimeString());
    }

    public function classes(array $context, array $filters): Builder
    {
        $q = DB::table('academic_classes as c')->when($context['institution_id'], fn ($q, $id) => $q->where('c.institution_id', $id));
        if ($context['class_ids'] !== null) {
            $q->whereIn('c.id', $context['class_ids']);
        }
        foreach (['academic_year_id', 'programme_id', 'intake_id'] as $key) {
            if (! empty($filters[$key])) {
                $q->where('c.'.$key, $filters[$key]);
            }
        }
        if (! empty($filters['academic_class_id'])) {
            $q->where('c.id', $filters['academic_class_id']);
        }
        if (! empty($filters['project_group_id'])) {
            $q->whereIn('c.id', DB::table('project_groups')->where('id', $filters['project_group_id'])->select('academic_class_id'));
        }

        return $q;
    }

    public function hasAcademic(array $context, array $filters): bool
    {
        return $context['class_ids'] !== null || collect(['academic_year_id', 'programme_id', 'intake_id', 'academic_class_id', 'project_group_id'])->contains(fn ($key) => ! empty($filters[$key]));
    }

    public function previous(array $filters): array
    {
        $from = CarbonImmutable::parse($filters['from']);
        $days = (int) $from->diffInDays(CarbonImmutable::parse($filters['to'])) + 1;

        return array_replace($filters, ['from' => $from->subDays($days)->toDateString(), 'to' => $from->subDay()->toDateString(), 'compare' => false]);
    }

    public function options(User $user, array $filters): array
    {
        $context = app(IntelligenceAccess::class)->context($user, $filters);
        $id = $context['institution_id'];
        $institutions = InstitutionAccess::system($user) ? Institution::orderBy('name')->get(['id', 'name']) : InnovationAccess::memberships($user)->filter(fn ($m) => IntelligenceAccess::canEnter($user, $m->institution))->pluck('institution');
        $options = ['institutions' => $institutions, 'categories' => DB::table('opportunity_categories')->where('active', true)->orderBy('name')->get(['id', 'name'])];
        foreach (['academic_years', 'intakes', 'programmes', 'academic_classes', 'project_groups'] as $table) {
            $q = DB::table($table)->where('institution_id', $id ?? 0);
            if ($table === 'academic_classes' && $context['class_ids'] !== null) {
                $q->whereIn('id', $context['class_ids']);
            }$options[$table] = $q->orderBy('name')->get(['id', 'name']);
        }
        $options['supervisors'] = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('roles as r', 'r.id', '=', 'm.role_id')->where('m.institution_id', $id ?? 0)->where('m.status', 'active')->whereIn('r.name', ['Lecturer / Teacher', 'Mentor / Supervisor'])->orderBy('u.name')->get(['u.id', 'u.name']);

        return $options;
    }
}
