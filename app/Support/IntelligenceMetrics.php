<?php

namespace App\Support;

use App\Models\Institution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IntelligenceMetrics
{
    public function __construct(public IntelligenceFilters $filters) {}

    public function cached(array $context, array $filters): array
    {
        return Cache::remember('intelligence:'.hash('sha256', json_encode([config('intelligence.version'), config('intelligence.small_group'), $context, $filters])), 60, fn () => $this->data($context, $filters) + ['as_of' => now()->toIso8601String()]);
    }

    public function students(array $context, array $filters, bool $studentOnly = true): Builder
    {
        $q = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('roles as r', 'r.id', '=', 'm.role_id')
            ->where('m.status', 'active')->where('u.status', 'active')->where('m.created_at', '<', $this->end($filters))->select('m.user_id')->distinct();
        if ($context['institution_id']) {
            $q->where('m.institution_id', $context['institution_id']);
        }
        if ($studentOnly) {
            $q->where('r.name', 'Student');
        }
        if ($this->filters->hasAcademic($context, $filters)) {
            $q->whereIn('m.id', DB::table('class_assignments as ca')->whereIn('ca.academic_class_id', $this->filters->classes($context, $filters)->select('c.id'))->when($studentOnly, fn ($q) => $q->where('ca.assignment', 'student'))->select('ca.membership_id'));
        }
        if (! empty($filters['project_group_id'])) {
            $q->whereIn('m.id', DB::table('group_members')->where('group_id', $filters['project_group_id'])->select('membership_id'));
        }

        return $q;
    }

    public function projects(array $context, array $filters): Builder
    {
        $q = DB::table('projects as p')->whereNotNull('p.institution_id')->when($context['institution_id'], fn ($q, $id) => $q->where('p.institution_id', $id));
        if ($this->filters->hasAcademic($context, $filters)) {
            $q->whereIn('p.academic_class_id', $this->filters->classes($context, $filters)->select('c.id'));
        }
        foreach (['status' => 'p.status', 'project_category' => 'p.category', 'project_group_id' => 'p.group_id'] as $key => $column) {
            if (! empty($filters[$key])) {
                $q->where($column, $filters[$key]);
            }
        }
        if (! empty($filters['supervisor_id'])) {
            $q->where(function ($q) use ($filters): void {
                $q->whereExists(fn ($s) => $s->selectRaw('1')->from('project_members as pm')->whereColumn('pm.project_id', 'p.id')->where('pm.role', 'supervisor')->where('pm.user_id', $filters['supervisor_id']))
                    ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('group_supervisors as gs')->join('memberships as sm', 'sm.id', '=', 'gs.membership_id')->whereColumn('gs.group_id', 'p.group_id')->where('sm.user_id', $filters['supervisor_id'])->where('sm.status', 'active'));
            });
        }

        return $q;
    }

    public function ideas(array $context, array $filters): Builder
    {
        $q = DB::table('ideas as i')->whereNotNull('i.institution_id')->when($context['institution_id'], fn ($q, $id) => $q->where('i.institution_id', $id));
        if ($this->filters->hasAcademic($context, $filters)) {
            $q->whereIn('i.owner_id', $this->students($context, $filters));
        }
        if (! empty($filters['project_category'])) {
            $q->where('i.category', $filters['project_category']);
        }
        if (! empty($filters['status']) || ! empty($filters['supervisor_id'])) {
            $q->whereIn('i.id', $this->projects($context, $filters)->whereNotNull('p.idea_id')->select('p.idea_id'));
        }

        return $q;
    }

    public function applications(array $context, array $filters): Builder
    {
        $q = DB::table('opportunity_applications as a')->when($context['institution_id'], fn ($q, $id) => $q->where('a.institution_id', $id));
        if ($this->filters->hasAcademic($context, $filters)) {
            $students = $this->students($context, $filters);
            $q->where(fn ($q) => $q->whereIn('a.applicant_id', clone $students)->orWhereIn('a.id', DB::table('application_participants')->whereIn('user_id', clone $students)->select('application_id')));
        }
        if (! empty($filters['opportunity_category_id'])) {
            $q->whereIn('a.opportunity_id', DB::table('opportunities')->where('category_id', $filters['opportunity_category_id'])->select('id'));
        }
        if (! empty($filters['status']) || ! empty($filters['project_category']) || ! empty($filters['project_group_id']) || ! empty($filters['supervisor_id'])) {
            $q->whereIn('a.project_id', $this->projects($context, $filters)->select('p.id'));
        }

        return $q;
    }

    public function participants(Builder $projects): Builder
    {
        $ids = (clone $projects)->select('p.id');
        $owners = (clone $projects)->selectRaw('p.id as project_id,p.owner_id as user_id,p.created_at as activity_at,p.institution_id');
        $members = DB::table('project_members as pm')->join('projects as pmproject', 'pmproject.id', '=', 'pm.project_id')->whereIn('pm.project_id', clone $ids)->where('pm.role', '!=', 'supervisor')->selectRaw('pm.project_id,pm.user_id,pmproject.created_at as activity_at,pmproject.institution_id');
        $group = DB::table('group_members as gm')->join('memberships as gmemb', 'gmemb.id', '=', 'gm.membership_id')->join('projects as gp', 'gp.group_id', '=', 'gm.group_id')->whereIn('gp.id', clone $ids)->selectRaw('gp.id as project_id,gmemb.user_id,gp.created_at as activity_at,gp.institution_id');

        return DB::query()->fromSub($owners->unionAll($members)->unionAll($group), 'participation')->whereExists(fn ($q) => $q->selectRaw('1')->from('memberships as student_membership')->join('roles as student_role', 'student_role.id', '=', 'student_membership.role_id')->whereColumn('student_membership.user_id', 'participation.user_id')->whereColumn('student_membership.institution_id', 'participation.institution_id')->where('student_membership.status', 'active')->where('student_role.name', 'Student'));
    }

    private function end(array $filters): string
    {
        return CarbonImmutable::parse($filters['to'], config('intelligence.timezone'))->addDay()->utc()->toDateTimeString();
    }

    private function window(Builder $query, array $filters, string $column): Builder
    {
        return $this->filters->window($query, $filters, $column);
    }

    private function ratio(int|float $n, int|float $d): ?float
    {
        return $d > 0 ? round($n / $d * 100, 2) : null;
    }

    public function protect(int|float|null $value, bool $people = false): int|float|null
    {
        return $people && $value !== null && $value > 0 && $value < config('intelligence.small_group') ? null : $value;
    }

    private function dateExpression(string $column, string $group): string
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $local = $sqlite ? "datetime($column,'+240 minutes')" : "DATE_ADD($column, INTERVAL 240 MINUTE)";

        return match ($group) {
            'daily' => "DATE($local)",
            'weekly' => $sqlite ? "DATE($local,'weekday 0','-6 days')" : "DATE(DATE_SUB($local, INTERVAL WEEKDAY($local) DAY))",
            default => $sqlite ? "strftime('%Y-%m-01',$local)" : "DATE_FORMAT($local,'%Y-%m-01')",
        };
    }

    private function chart(string $key, string $title, string $type, array $labels, array $values, string $note, string $unit = 'Count', array $links = [], bool $people = false): array
    {
        $protected = $people && collect($values)->contains(fn ($v) => $v > 0 && $v < config('intelligence.small_group'));
        if ($protected) {
            $values = array_fill(0, count($values), null);
        }

        return compact('key', 'title', 'type', 'labels', 'values', 'note', 'unit', 'links', 'protected');
    }

    public function timeline(string $key, string $title, Builder $query, array $filters, string $column, string $note, ?string $distinct = null): array
    {
        $expr = $this->dateExpression($column, $filters['group']);
        $rows = (clone $query)->select([])->selectRaw($expr.' as bucket,COUNT('.($distinct ? 'DISTINCT '.$distinct : '*').') as total')->groupByRaw($expr)->get()->pluck('total', 'bucket');
        $bucket = fn ($d) => match ($filters['group']) {
            'daily' => $d->startOfDay(),'weekly' => $d->startOfWeek(),default => $d->startOfMonth()
        };
        $values = [];
        $end = $bucket(CarbonImmutable::parse($filters['to']));
        for ($date = $bucket(CarbonImmutable::parse($filters['from'])); $date->lte($end); $date = match ($filters['group']) {
            'daily' => $date->addDay(),'weekly' => $date->addWeek(),default => $date->addMonth()
        }) {
            $values[$date->toDateString()] = (int) ($rows[$date->toDateString()] ?? 0);
        }

        return $this->chart($key, $title, 'line', array_keys($values), array_values($values), $note, $distinct ? 'People' : 'Activities', [], (bool) $distinct);
    }

    public function groups(string $key, string $title, Builder $query, string $column, string $note, string $type = 'bar', string $unit = 'Count', bool $people = false, ?string $distinct = null): array
    {
        $rows = (clone $query)->select([])->selectRaw("COALESCE($column, 'Unassigned') as label,COUNT(".($distinct ? 'DISTINCT '.$distinct : '*').') as total')->groupBy($column)->orderByDesc('total')->limit(100)->get();

        return $this->chart($key, $title, $type, $rows->pluck('label')->map(fn ($v) => ucwords(str_replace('_', ' ', $v)))->all(), $rows->pluck('total')->map(fn ($v) => (int) $v)->all(), $note, $unit, [], $people);
    }

    public function data(array $context, array $filters, bool $comparison = true): array
    {
        if ($filters['section'] === 'network') {
            return app(NetworkAnalytics::class)->data($context, $filters, $this);
        }
        $students = $this->students($context, $filters);
        $members = $this->students($context, $filters, false);
        $projects = $this->projects($context, $filters);
        $createdProjects = $this->window(clone $projects, $filters, 'p.created_at');
        $projectIds = (clone $createdProjects)->select('p.id');
        $ideas = $this->window($this->ideas($context, $filters), $filters, 'i.created_at');
        $groups = DB::table('project_groups as g')->when($context['institution_id'], fn ($q, $id) => $q->where('g.institution_id', $id));
        if ($this->filters->hasAcademic($context, $filters)) {
            $groups->whereIn('g.academic_class_id', $this->filters->classes($context, $filters)->select('c.id'));
        }
        if (! empty($filters['project_group_id'])) {
            $groups->where('g.id', $filters['project_group_id']);
        }
        $groups = $this->window($groups, $filters, 'g.created_at');
        $completed = $this->window(clone $projects, $filters, 'p.completed_at');
        $participants = $this->participants($createdProjects)->whereIn('participation.user_id', clone $students);
        $tasks = DB::table('project_tasks')->whereIn('project_id', clone $projectIds);
        $milestones = DB::table('milestones')->whereIn('project_id', clone $projectIds);
        $deliverables = DB::table('deliverables')->whereIn('project_id', clone $projectIds);
        $cutoff = min(CarbonImmutable::today(config('intelligence.timezone'))->toDateString(), CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
        $apps = $this->window($this->applications($context, $filters)->where('a.external_self_reported', false), $filters, 'a.submitted_at');
        $appIds = (clone $apps)->select('a.id');
        $applicants = (clone $apps)->selectRaw('a.applicant_id as user_id')->unionAll(DB::table('application_participants')->whereIn('application_id', clone $appIds)->select('user_id'));
        $applicants = DB::query()->fromSub($applicants, 'applicants')->whereIn('user_id', clone $students);
        $verifications = DB::table('achievement_verifications as v')->where('v.status', 'verified')->when($context['institution_id'], fn ($q, $id) => $q->where('v.institution_id', $id))->whereIn('v.user_id', clone $students);
        $verifications = $this->window($verifications, $filters, 'v.verified_at');
        $profiles = $this->window(DB::table('professional_profiles as pp')->whereIn('pp.user_id', clone $students), $filters, 'pp.created_at');
        $cvs = $this->window(DB::table('cv_documents')->whereIn('user_id', clone $students), $filters, 'created_at');
        $portfolios = $this->window(DB::table('portfolios as po')->join('professional_profiles as pp', 'pp.id', '=', 'po.professional_profile_id')->whereIn('pp.user_id', clone $students)->where('po.published', true), $filters, 'po.published_at');
        $showcases = $this->window(DB::table('project_showcases as s')->join('professional_profiles as sp', 'sp.id', '=', 's.professional_profile_id')->whereIn('sp.user_id', clone $students)->whereIn('s.project_id', (clone $projects)->select('p.id')), $filters, 's.created_at');
        $ai = DB::table('ai_usages as ai')->whereNotNull('ai.institution_id')->when($context['institution_id'], fn ($q, $id) => $q->where('ai.institution_id', $id));
        if ($this->filters->hasAcademic($context, $filters)) {
            $ai->whereIn('ai.user_id', clone $students);
        }
        $ai = $this->window($ai, $filters, 'ai.created_at');
        $institutions = $this->window(DB::table('institutions')->when($context['institution_id'], fn ($q, $id) => $q->where('id', $id)), $filters, 'created_at');
        $users = $this->window(DB::table('users')->when($context['institution_id'] || $this->filters->hasAcademic($context, $filters), fn ($q) => $q->whereIn('id', clone $members)), $filters, 'created_at');
        $opportunities = DB::table('opportunities as o')->whereIn('o.status', ['published', 'closed'])->when($context['institution_id'], fn ($q, $id) => $q->where('o.institution_id', $id));
        if (! empty($filters['opportunity_category_id'])) {
            $opportunities->where('o.category_id', $filters['opportunity_category_id']);
        }
        $published = $this->window(clone $opportunities, $filters, 'o.published_at');
        $available = $context['institution_id'] ? app(OpportunityAnalytics::class)->availableToMembers(Institution::findOrFail($context['institution_id']), clone $students)->toBase() : DB::table('opportunities')->whereIn('status', ['published', 'closed']);
        if (! empty($filters['opportunity_category_id'])) {
            $available->where('category_id', $filters['opportunity_category_id']);
        }
        $available = $this->window($available, $filters, 'opportunities.created_at');
        $visits = DB::table('institution_opportunity_visits')->whereIn('user_id', clone $students)->when($context['institution_id'], fn ($q, $id) => $q->where('institution_id', $id))->whereBetween('visited_on', [$filters['from'], $filters['to']]);
        if (! empty($filters['opportunity_category_id'])) {
            $visits->whereIn('opportunity_id', (clone $opportunities)->select('o.id'));
        }
        $mature = (clone $ideas)->where('i.created_at', '<', CarbonImmutable::parse($filters['to'], config('intelligence.timezone'))->addDay()->subDays($filters['cohort_days'])->utc()->toDateTimeString());
        $converted = (clone $mature)->whereIn('i.id', (clone $projects)->where('p.created_at', '<', $this->end($filters))->whereNotNull('p.idea_id')->select('p.idea_id'));
        $studentCount = (clone $students)->count('m.user_id');
        $participantCount = (clone $participants)->distinct()->count('user_id');
        $startExpression = DB::connection()->getDriverName() === 'sqlite' ? "COALESCE(datetime(p.starts_at,'-240 minutes'),p.created_at)" : 'COALESCE(DATE_SUB(p.starts_at,INTERVAL 240 MINUTE),p.created_at)';
        $validCompletions = (clone $completed)->whereRaw('p.completed_at >= '.$startExpression);
        $duration = DB::connection()->getDriverName() === 'sqlite' ? 'julianday(p.completed_at)-julianday('.$startExpression.')' : 'TIMESTAMPDIFF(SECOND,'.$startExpression.',p.completed_at)/86400';
        $average = (clone $validCompletions)->selectRaw('AVG('.$duration.') as duration')->value('duration');
        $onTime = (clone $completed)->whereNotNull('p.target_at');
        $onTimeNumerator = (clone $onTime)->whereRaw($this->dateExpression('p.completed_at', 'daily').' <= p.target_at')->count();
        $completeProfiles = (clone $profiles)->whereRaw("LENGTH(TRIM(COALESCE(pp.display_name,'')))>0")->whereRaw("LENGTH(TRIM(COALESCE(pp.headline,'')))>0")->whereRaw("LENGTH(TRIM(COALESCE(pp.biography,'')))>0");
        foreach (['education', 'skill'] as $type) {
            $completeProfiles->whereExists(fn ($q) => $q->selectRaw('1')->from('professional_entries as pe')->whereColumn('pe.professional_profile_id', 'pp.id')->where('pe.type', $type));
        }
        $raw = [
            'institutions' => (clone $institutions)->count(), 'active_institutions' => (clone $institutions)->where('status', 'active')->count(), 'users' => (clone $users)->count(),
            'members' => (clone $members)->count('m.user_id'), 'students' => $studentCount, 'ideas' => (clone $ideas)->count(), 'approved_ideas' => (clone $ideas)->where('i.status', 'approved')->count(),
            'submitted_ideas' => $this->window($this->ideas($context, $filters), $filters, 'i.first_submitted_at')->count(),
            'projects' => (clone $createdProjects)->count(), 'active_projects' => (clone $createdProjects)->whereIn('p.status', ['approved', 'in_progress', 'on_hold'])->count(), 'completed_projects' => (clone $completed)->count(),
            'overdue_projects' => (clone $createdProjects)->whereNotIn('p.status', ['completed', 'cancelled', 'archived'])->where('p.target_at', '<', $cutoff)->count(), 'groups' => (clone $groups)->count(),
            'participating_students' => $participantCount, 'student_participation_rate' => $studentCount >= config('intelligence.small_group') && ($participantCount === 0 || $participantCount >= config('intelligence.small_group')) ? $this->ratio($participantCount, $studentCount) : null,
            'group_leaders' => DB::table('group_members as gl')->join('memberships as lm', 'lm.id', '=', 'gl.membership_id')->whereIn('gl.group_id', (clone $groups)->select('g.id'))->where('gl.role', 'leader')->whereIn('lm.user_id', clone $students)->distinct()->count('lm.user_id'),
            'approved_deliverable_students' => (clone $deliverables)->where('status', 'approved')->whereIn('assigned_user_id', clone $students)->distinct()->count('assigned_user_id'),
            'milestone_rate' => $this->ratio((clone $milestones)->where('status', 'completed')->count(), (clone $milestones)->count()),
            'overdue_milestones' => (clone $milestones)->where('status', '!=', 'completed')->where('due_at', '<', $cutoff)->count(),
            'task_rate' => $this->ratio((clone $tasks)->where('status', 'completed')->count(), (clone $tasks)->count()), 'deliverable_rate' => $this->ratio((clone $deliverables)->where('status', 'approved')->count(), (clone $deliverables)->count()),
            'awaiting_review' => (clone $deliverables)->where('status', 'submitted')->distinct()->count('project_id'), 'completion_days' => $average === null ? null : round((float) $average, 2),
            'on_time_completion_rate' => $this->ratio($onTimeNumerator, (clone $onTime)->count()), 'published_opportunities' => (clone $published)->count(), 'available_opportunities' => (clone $available)->count(),
            'accessed_opportunities' => (clone $visits)->distinct()->count('opportunity_id'), 'internal_applications' => (clone $apps)->count(), 'student_applicants' => (clone $applicants)->distinct()->count('user_id'),
            'group_applications' => (clone $apps)->whereNotNull('a.group_id')->count(), 'shortlisted_applications' => (clone $apps)->where('a.status', 'shortlisted')->count(), 'accepted_applications' => (clone $apps)->where('a.status', 'accepted')->count(),
            'external_reports' => $this->window($this->applications($context, $filters)->where('a.external_self_reported', true), $filters, 'a.created_at')->count(),
            'profiles' => (clone $profiles)->distinct()->count('pp.user_id'), 'complete_profiles' => (clone $completeProfiles)->distinct()->count('pp.user_id'), 'cvs' => (clone $cvs)->count(), 'cv_users' => (clone $cvs)->distinct()->count('user_id'),
            'published_portfolios' => (clone $portfolios)->distinct()->count('pp.user_id'), 'verified_achievements' => (clone $verifications)->count(), 'verified_students' => (clone $verifications)->distinct()->count('v.user_id'), 'showcases' => (clone $showcases)->count(),
            'idea_conversion' => $this->ratio((clone $converted)->count(), (clone $mature)->count()),
            'ai_requests' => (clone $ai)->count(), 'ai_users' => (clone $ai)->distinct()->count('ai.user_id'), 'ai_tokens' => (int) (clone $ai)->sum('ai.input_tokens') + (int) (clone $ai)->sum('ai.output_tokens'),
            'ai_failures' => (clone $ai)->whereNotIn('ai.status', ['success', 'pending'])->count(), 'ai_cost' => (clone $ai)->whereNotNull('ai.estimated_cost')->exists() ? (float) (clone $ai)->sum('ai.estimated_cost') : null,
        ];
        $raw['ai_error_rate'] = $this->ratio($raw['ai_failures'], $raw['ai_requests']);
        $metrics = [];
        foreach (config('intelligence.metrics') as $key => [$label,$unit,$definition,$section]) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            $metrics[$key] = ['key' => $key, 'label' => $label, 'value' => $this->protect($raw[$key], $unit === 'People'), 'unit' => $unit, 'definition' => $definition, 'section' => $section];
        }
        $statuses = $this->groups('project-status', 'Project lifecycle', $createdProjects, 'p.status', 'Current status of institutional projects created in this period.', 'doughnut', 'Projects');
        $statuses['links'] = array_map(fn ($label) => route('intelligence.records', ['type' => 'projects'] + array_replace($filters, ['status' => strtolower(str_replace(' ', '_', $label))])), $statuses['labels']);
        if ($context['platform']) {
            $statuses['links'] = array_map(fn ($label) => route('intelligence.index', array_replace($filters, ['section' => 'projects', 'status' => strtolower(str_replace(' ', '_', $label))])), $statuses['labels']);
        }
        $programmeProjects = (clone $createdProjects)->leftJoin('academic_classes as pc', 'pc.id', '=', 'p.academic_class_id')->leftJoin('programmes as prog', 'prog.id', '=', 'pc.programme_id');
        $intakePeople = (clone $participants)->join('memberships as im', 'im.user_id', '=', 'participation.user_id')->whereColumn('im.institution_id', 'participation.institution_id')->join('class_assignments as ia', 'ia.membership_id', '=', 'im.id')->join('academic_classes as ic', 'ic.id', '=', 'ia.academic_class_id')->leftJoin('intakes as it', 'it.id', '=', 'ic.intake_id')->where('im.status', 'active')->where('ia.assignment', 'student')->whereIn('ic.id', $this->filters->classes($context, $filters)->select('c.id'));
        $achievementChart = $this->groups('achievements', 'Verified achievement categories', $verifications, 'v.category', 'Recorded institutional verification decisions; no private evidence is included.', 'doughnut', 'Verifications');
        $createdTimeline = $this->timeline('project-creation', 'Projects initiated over time', $createdProjects, $filters, 'p.created_at', 'Unique institutional projects created per local reporting period.');
        $completionTimeline = $this->timeline('project-completion', 'Actual project completions', $completed, $filters, 'p.completed_at', 'Actual completion events, including projects created before this reporting period.');
        $participationTimeline = $this->timeline('student-participation', 'Unique participating students', $participants, $filters, 'participation.activity_at', 'Distinct current students per reporting bucket in period-created institutional projects. A person may appear in multiple periods.', 'participation.user_id');
        $applicationTimeline = $this->timeline('opportunity-participation', 'Internal application submissions', $apps, $filters, 'a.submitted_at', 'Submission timestamps; excludes external self-reports.');
        $opportunityCategories = (clone $published)->join('opportunity_categories as oc', 'oc.id', '=', 'o.category_id');
        $yearProjects = (clone $createdProjects)->leftJoin('academic_classes as yc', 'yc.id', '=', 'p.academic_class_id')->leftJoin('academic_years as yr', 'yr.id', '=', 'yc.academic_year_id');
        $programmeApplications = (clone $apps)->leftJoin('memberships as am', fn ($join) => $join->on('am.user_id', '=', 'a.applicant_id')->on('am.institution_id', '=', 'a.institution_id')->where('am.status', 'active'))->leftJoin('class_assignments as aca', fn ($join) => $join->on('aca.membership_id', '=', 'am.id')->where('aca.assignment', 'student'))->leftJoin('academic_classes as ac', 'ac.id', '=', 'aca.academic_class_id')->leftJoin('programmes as ap', 'ap.id', '=', 'ac.programme_id');
        if ($this->filters->hasAcademic($context, $filters)) {
            $programmeApplications->whereIn('ac.id', $this->filters->classes($context, $filters)->select('c.id'));
        }
        $applicationCategories = (clone $apps)->join('opportunities as ao', 'ao.id', '=', 'a.opportunity_id')->join('opportunity_categories as aoc', 'aoc.id', '=', 'ao.category_id');
        $journeyLabels = ['Mature ideas created', 'Submission / review evidence', 'Approved at least once', 'Converted to a project', 'Linked projects completed', 'Linked projects with verified outcomes'];
        $matureIds = (clone $mature)->select('i.id');
        $reviewed = (clone $mature)->where(fn ($q) => $q->where('i.first_submitted_at', '<', $this->end($filters))->orWhereIn('i.id', DB::table('idea_reviews')->where('created_at', '<', $this->end($filters))->select('idea_id')))->count();
        $approved = (clone $mature)->whereIn('i.id', DB::table('idea_reviews')->where('decision', 'approved')->where('created_at', '<', $this->end($filters))->select('idea_id'))->count();
        $journeyProjects = (clone $projects)->whereIn('p.idea_id', clone $matureIds)->where('p.created_at', '<', $this->end($filters));
        $verifiedProjectIds = DB::table('achievement_verifications as jv')->join('project_showcases as js', 'js.id', '=', 'jv.project_showcase_id')->where('jv.status', 'verified')->where('jv.category', 'academic_project')->where('jv.verified_at', '<', $this->end($filters))->select('js.project_id');
        $journey = $this->chart('innovation-journey', 'Mature idea cohort journey', 'bar', $journeyLabels, [(clone $mature)->count(), $reviewed, $approved, (clone $converted)->count(), (clone $journeyProjects)->whereNotNull('p.completed_at')->where('p.completed_at', '<', $this->end($filters))->count(), (clone $journeyProjects)->whereIn('p.id', $verifiedProjectIds)->count()], 'Unique institutional ideas at least '.$filters['cohort_days'].' days old by period end. Review evidence is not a reconstructed submission timestamp. Stages are linked cohort events, not a ranking.', 'Cohort records');
        $activityEvents = (clone $ideas)->selectRaw('i.created_at as activity_at')->unionAll((clone $createdProjects)->selectRaw('p.created_at as activity_at'))->unionAll((clone $apps)->selectRaw('a.submitted_at as activity_at'))->unionAll((clone $verifications)->selectRaw('v.verified_at as activity_at'));
        $activityTimeline = $this->timeline('platform-activity', 'Defined innovation activity', DB::query()->fromSub($activityEvents, 'events'), $filters, 'activity_at', 'Activity events = institutional ideas created + projects initiated + internal submissions + institutional verification decisions. Events are not unique people.');
        $charts = match ($filters['section']) {
            'projects' => [$createdTimeline, $completionTimeline, $statuses, $this->groups('programmes', 'Projects by programme', $programmeProjects, 'prog.name', 'Recorded project class/programme; missing assignments remain visible as Unassigned.', 'bar', 'Projects'), $this->groups('years', 'Projects by academic year', $yearProjects, 'yr.name', 'Recorded project academic class; dates filter project creation.', 'bar', 'Projects')],
            'participation' => [$participationTimeline, $this->groups('intakes', 'Student participation by intake', $intakePeople, 'it.name', 'Unique people per intake; students assigned to multiple intakes can appear in each. Small breakdowns are suppressed.', 'bar', 'People', true, 'participation.user_id')],
            'programmes' => [$this->groups('programmes', 'Projects by programme', $programmeProjects, 'prog.name', 'Current recorded academic context, not historical programme reconstruction.', 'bar', 'Projects'), $this->groups('years', 'Projects by academic year', $yearProjects, 'yr.name', 'Project creation cohort.', 'bar', 'Projects')],
            'opportunities' => [$applicationTimeline, $this->groups('opportunity-category', 'Published opportunities by category', $opportunityCategories, 'oc.name', 'Publication events in period and current published/closed state.', 'bar', 'Opportunities'), $this->groups('application-outcomes', 'Internal application outcomes', $apps, 'a.status', 'Current reviewer outcomes of period-submitted internal applications. Not independently verified external outcomes.', 'doughnut', 'Applications'), $this->groups('applications-programme', 'Applications by programme', $programmeApplications, 'ap.name', 'Unique applications per current applicant programme. Multiple programme assignments may overlap. Group co-participants do not change the applicant programme attribution.', 'bar', 'Applications', false, 'a.id'), $this->groups('applications-category', 'Applications by opportunity category', $applicationCategories, 'aoc.name', 'Internal submissions only; category of the source opportunity.', 'bar', 'Applications')],
            'career' => [$this->timeline('profile-adoption', 'Student profile adoption', $profiles, $filters, 'pp.created_at', 'Distinct student profiles created in this period.', 'pp.user_id'), $this->timeline('cv-adoption', 'Unique CV creators', $cvs, $filters, 'created_at', 'Distinct cohort students creating private CV documents; no contents are read.', 'user_id'), $achievementChart],
            'achievements' => [$achievementChart, $this->timeline('verification-trend', 'Institutional verification decisions', $verifications, $filters, 'v.verified_at', 'Current verified decisions with an actual verification timestamp.')],
            'ai' => [$this->timeline('ai-timeline', 'AI requests over time', $ai, $filters, 'ai.created_at', 'Institution-context metadata only; includes failures.'), $this->groups('ai-features', 'Requests by feature', $ai, 'ai.feature', 'No conversation or prompt contents are accessed.', 'bar', 'Requests'), $this->groups('ai-outcomes', 'AI request outcomes', $ai, 'ai.status', 'Failures and pending requests are retained as metadata.', 'doughnut', 'Requests')],
            'quality' => [],
            default => $context['platform'] ? [
                $this->timeline('institution-growth', 'Institutions created over time', $institutions, $filters, 'created_at', 'Creation timestamps are used; historic activation events were not consistently retained.'), $participationTimeline,
                $this->groups('projects-institution', 'Projects by institution', (clone $createdProjects)->join('institutions as pi', 'pi.id', '=', 'p.institution_id'), 'pi.name', 'Institutional project creation counts; no personal projects. Counts do not measure institutional quality.', 'bar', 'Projects'),
                $statuses, $this->groups('opportunity-category', 'Published opportunities by category', $opportunityCategories, 'oc.name', 'Publication timestamps, not decorative estimates.', 'bar', 'Opportunities'), $activityTimeline,
            ] : [$createdTimeline, $completionTimeline, $statuses, $this->groups('programmes', 'Projects by programme', $programmeProjects, 'prog.name', 'Recorded project academic context; current statuses.', 'bar', 'Projects'), $this->groups('intakes', 'Unique students by intake', $intakePeople, 'it.name', 'Distinct participating students per intake. Small-group suppression applies to the entire breakdown.', 'bar', 'People', true, 'participation.user_id'), $applicationTimeline, $achievementChart, $journey],
        };
        $sections = match ($filters['section']) {
            'overview' => ['overview', 'projects', 'participation', 'opportunities', 'career', 'achievements', 'ai'],'programmes' => ['projects', 'participation'],default => [$filters['section']]
        };
        $selected = array_filter($metrics, fn ($m) => in_array($m['section'], $sections, true));
        if ($filters['section'] === 'projects') {
            foreach (['ideas', 'approved_ideas', 'idea_conversion'] as $key) {
                $selected[$key] = $metrics[$key];
            }
        }
        if ($filters['section'] === 'overview') {
            $overviewKeys = $context['platform'] ? ['institutions', 'active_institutions', 'users', 'members', 'ideas', 'projects', 'completed_projects', 'published_opportunities', 'internal_applications', 'published_portfolios', 'verified_achievements', 'ai_requests'] : ['students', 'participating_students', 'ideas', 'approved_ideas', 'projects', 'active_projects', 'completed_projects', 'overdue_projects', 'groups', 'accessed_opportunities', 'internal_applications', 'verified_achievements', 'published_portfolios'];
            $selected = array_intersect_key($metrics, array_flip($overviewKeys));
        }
        if ($filters['section'] === 'participation') {
            $selected['published_portfolios'] = $metrics['published_portfolios'];
        }
        $quality = $this->quality($context, $filters, $createdProjects, $students, $verifications);
        $result = ['metrics' => $metrics, 'selected' => $selected, 'charts' => $charts, 'journey' => $journey, 'quality' => $quality, 'filters' => $filters, 'context' => $context,
            'coverage' => ['eligible_students' => $this->protect($studentCount, true), 'project_cohort' => $raw['projects'], 'mature_ideas' => (clone $mature)->count(), 'timezone' => config('intelligence.timezone'), 'definitions' => config('intelligence.version')], 'insights' => [], 'comparison' => []];
        if ($comparison && $filters['compare']) {
            $previous = $this->data($context, $this->filters->previous($filters), false);
            foreach (['projects', 'completed_projects', 'internal_applications'] as $key) {
                $current = $metrics[$key]['value'];
                $before = $previous['metrics'][$key]['value'];
                $result['comparison'][$key] = ['current' => $current, 'previous' => $before, 'change' => $current !== null && $before !== null ? $current - $before : null];
                $result['insights'][] = $metrics[$key]['label'].': '.$this->display($current).' in '.$filters['from'].'–'.$filters['to'].'; '.$this->display($before).' in '.$previous['filters']['from'].'–'.$previous['filters']['to'].'. This is a descriptive comparison, not a causal explanation.';
            }
        }
        if ($raw['overdue_milestones'] > 0) {
            $result['insights'][] = $raw['overdue_milestones'].' unfinished milestones in this project cohort are due before '.$cutoff.'. Check schedules and context before drawing conclusions.';
        }
        if ($context['platform']) {
            $result['institutions'] = $this->institutionComparison($context, $filters);
        }

        return $result;
    }

    public function display(int|float|null $value): string
    {
        return $value === null ? 'Protected / unavailable' : number_format($value, is_float($value) ? 2 : 0);
    }

    public function quality(array $context, array $filters, Builder $projects, Builder $students, Builder $verifications): array
    {
        $verifications = $this->window(DB::table('achievement_verifications as v')->where('v.status', 'verified')->when($context['institution_id'], fn ($q, $id) => $q->where('v.institution_id', $id))->whereIn('v.user_id', clone $students), $filters, 'v.created_at');
        $statuses = ['draft', 'pending_approval', 'approved', 'in_progress', 'on_hold', 'completed', 'cancelled', 'archived'];
        $items = [
            'idea_submission' => ['Legacy ideas missing first-submission time', $this->window($this->ideas($context, $filters), $filters, 'i.created_at')->whereIn('i.status', ['submitted', 'under_review', 'approved', 'returned', 'converted'])->whereNull('i.first_submitted_at')->count(), 'First-submission timestamps are captured for new submissions. Legacy records are not backdated; review available source history.', 'ideas'],
            'ownership' => ['Projects without an existing owner', (clone $projects)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('users')->whereColumn('users.id', 'p.owner_id'))->count(), 'Check project ownership before reporting participation.', 'projects'],
            'unknown_status' => ['Projects with unsupported status', (clone $projects)->whereNotIn('p.status', $statuses)->count(), 'Review the project lifecycle status.', 'projects'],
            'completion_time' => ['Completed projects missing completion time', (clone $projects)->where('p.status', 'completed')->whereNull('p.completed_at')->count(), 'Record an actual completion through the authorised project workflow; no target-date substitute is used.', 'projects'],
            'academic_context' => ['Institutional projects without class context', (clone $projects)->whereNull('p.academic_class_id')->count(), 'Assign an authorised academic class where appropriate.', 'projects'],
            'invalid_dates' => ['Projects with invalid date order', (clone $projects)->where(fn ($q) => $q->whereColumn('p.target_at', '<', 'p.starts_at')->orWhereColumn('p.completed_at', '<', 'p.starts_at'))->count(), 'Check recorded start, target and actual completion dates.', 'projects'],
            'assignments' => ['Students without academic assignments', $this->protect((clone $students)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('class_assignments as da')->join('memberships as dm', 'dm.id', '=', 'da.membership_id')->whereColumn('dm.user_id', 'm.user_id')->whereColumn('dm.institution_id', 'm.institution_id')->where('da.assignment', 'student'))->count('m.user_id'), true), 'Review current student class assignments; unassigned students cannot be attributed to a programme.', 'members'],
            'verification' => ['Verified decisions missing required metadata', (clone $verifications)->where(fn ($q) => $q->whereNull('v.approved_content')->orWhereNull('v.verified_by')->orWhereNull('v.verified_at'))->count(), 'Review evidence/approval metadata through the original verification workflow. Evidence contents are not inspected here.', 'verifications'],
        ];
        $duplicates = DB::query()->fromSub(DB::table('memberships')->when($context['institution_id'], fn ($q, $id) => $q->where('institution_id', $id))->whereIn('user_id', clone $students)->selectRaw('institution_id,user_id,COUNT(*) as total')->groupBy('institution_id', 'user_id')->havingRaw('COUNT(*)>1'), 'duplicates')->count();
        $items['duplicates'] = ['Duplicate membership relationships', $duplicates, 'The current unique constraint normally prevents these; imported legacy records still require review.', 'members'];

        return collect($items)->map(fn ($v, $key) => ['key' => $key, 'label' => $v[0], 'count' => $v[1], 'action' => $v[2], 'type' => $v[3]])->values()->all();
    }

    private function institutionComparison(array $context, array $filters): array
    {
        $projects = $this->window($this->projects($context, $filters), $filters, 'p.created_at')->selectRaw('p.institution_id,COUNT(*) as total')->groupBy('p.institution_id');
        $completed = $this->window($this->projects($context, $filters), $filters, 'p.completed_at')->selectRaw('p.institution_id,COUNT(*) as total')->groupBy('p.institution_id');
        $students = $this->students($context, $filters)->select([])->selectRaw('m.institution_id,COUNT(DISTINCT m.user_id) as total')->groupBy('m.institution_id');
        $applications = $this->window($this->applications($context, $filters)->where('a.external_self_reported', false), $filters, 'a.submitted_at')->selectRaw('a.institution_id,COUNT(*) as total')->groupBy('a.institution_id');
        $participation = $this->participants($this->window($this->projects($context, $filters), $filters, 'p.created_at'))->whereIn('participation.user_id', $this->students($context, $filters))->selectRaw('participation.institution_id,COUNT(DISTINCT participation.user_id) as total')->groupBy('participation.institution_id');
        $portfolios = $this->window($this->students($context, $filters)->join('professional_profiles as cpr', 'cpr.user_id', '=', 'm.user_id')->join('portfolios as cpo', 'cpo.professional_profile_id', '=', 'cpr.id')->where('cpo.published', true), $filters, 'cpo.published_at')->select([])->selectRaw('m.institution_id,COUNT(DISTINCT m.user_id) as total')->groupBy('m.institution_id');

        return DB::table('institutions as ci')->when($context['institution_id'], fn ($q, $id) => $q->where('ci.id', $id))->leftJoinSub($projects, 'cp', 'cp.institution_id', '=', 'ci.id')->leftJoinSub($completed, 'cc', 'cc.institution_id', '=', 'ci.id')->leftJoinSub($students, 'cs', 'cs.institution_id', '=', 'ci.id')->leftJoinSub($applications, 'ca', 'ca.institution_id', '=', 'ci.id')->leftJoinSub($participation, 'cpa', 'cpa.institution_id', '=', 'ci.id')->leftJoinSub($portfolios, 'cpoa', 'cpoa.institution_id', '=', 'ci.id')
            ->selectRaw('ci.id,ci.name,COALESCE(cp.total,0) as projects,COALESCE(cc.total,0) as completions,COALESCE(cs.total,0) as students,COALESCE(ca.total,0) as applications,COALESCE(cpa.total,0) as participants,COALESCE(cpoa.total,0) as portfolios')->orderBy('ci.name')->limit(100)->get()->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'students' => $this->protect((int) $r->students, true), 'projects' => (int) $r->projects, 'completions' => (int) $r->completions, 'applications' => (int) $r->applications,
                'participants' => $this->protect((int) $r->participants, true), 'portfolios' => $this->protect((int) $r->portfolios, true),
                'participation_rate' => (int) $r->students >= config('intelligence.small_group') && ((int) $r->participants === 0 || (int) $r->participants >= config('intelligence.small_group')) ? $this->ratio((int) $r->participants, (int) $r->students) : null,
                'context' => 'Current enrolled Student cohort; activity events in the selected period. No rankings or quality inference.',
            ])->all();
    }
}
