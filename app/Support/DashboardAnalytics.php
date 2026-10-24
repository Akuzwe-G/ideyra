<?php

namespace App\Support;

use App\Models\Institution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardAnalytics
{
    public function filters(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'group' => ['nullable', Rule::in(['daily', 'weekly', 'monthly'])]]);
        $from = CarbonImmutable::parse($data['from'] ?? now()->subMonths(5)->startOfMonth()->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? today()->toDateString())->startOfDay();
        if ($from->gt($to) || $from->diffInDays($to) > 1096) {
            throw ValidationException::withMessages(['from' => 'Choose an ordered date range of at most three years.']);
        }

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'group' => $data['group'] ?? 'monthly'];
    }

    private function window(Builder $query, array $filters, string $column = 'created_at'): Builder
    {
        return $query->where($column, '>=', $filters['from'].' 00:00:00')->where($column, '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateTimeString());
    }

    private function chart(string $key, string $title, string $type, array $labels, array $values, string $note, string $unit = 'Count'): array
    {
        return compact('key', 'title', 'type', 'labels', 'values', 'note', 'unit');
    }

    private function grouped(Builder $query, string $column, array $labels): array
    {
        $counts = $query->select($column)->selectRaw('COUNT(*) as total')->groupBy($column)->pluck('total', $column);

        return array_map(fn ($key) => (int) ($counts[$key] ?? 0), array_keys($labels));
    }

    private function timeline(Builder $query, array $filters): array
    {
        $counts = $this->window($query, $filters)->selectRaw('DATE(created_at) as day, COUNT(*) as total')->groupByRaw('DATE(created_at)')->get();
        $bucket = fn (CarbonImmutable $date) => match ($filters['group']) {
            'daily' => $date->startOfDay(), 'weekly' => $date->startOfWeek(), default => $date->startOfMonth(),
        };
        $values = [];
        $end = $bucket(CarbonImmutable::parse($filters['to']));
        for ($cursor = $bucket(CarbonImmutable::parse($filters['from'])); $cursor->lte($end); $cursor = match ($filters['group']) {
            'daily' => $cursor->addDay(), 'weekly' => $cursor->addWeek(), default => $cursor->addMonth(),
        }) {
            $values[$cursor->toDateString()] = 0;
        }
        foreach ($counts as $count) {
            $key = $bucket(CarbonImmutable::parse($count->day))->toDateString();
            $values[$key] += (int) $count->total;
        }

        return [array_keys($values), array_values($values)];
    }

    public function system(array $filters): array
    {
        $applications = $this->window(DB::table('join_applications'), $filters);
        $statuses = ['submitted' => 'Submitted', 'under_review' => 'Under review', 'more_information_requested' => 'More information requested', 'approved' => 'Approved', 'rejected' => 'Rejected', 'onboarded' => 'Onboarded'];
        $institutionStatuses = ['active' => 'Active', 'pending_activation' => 'Pending activation', 'suspended' => 'Suspended', 'archived' => 'Archived', 'draft' => 'Draft'];
        [$dates, $received] = $this->timeline(DB::table('join_applications'), $filters);
        [$userDates, $users] = $this->timeline(DB::table('users'), $filters);
        $countries = (clone $applications)->select('country')->selectRaw('COUNT(*) as total')->groupBy('country')->orderByDesc('total')->orderBy('country')->get();
        $institutions = $this->window(DB::table('institutions'), $filters);
        $charts = [
            $this->chart('applications', 'Applications over time', 'line', $dates, $received, 'Applications received in the selected period. Weeks start Monday. Partial boundary buckets include only selected dates.', 'Applications'),
            $this->chart('statuses', 'Application status', 'doughnut', array_values($statuses), $this->grouped(clone $applications, 'status', $statuses), 'Current status of applications received in the selected period.', 'Applications'),
            $this->chart('countries', 'Applications by country', 'bar', $countries->pluck('country')->all(), $countries->pluck('total')->map(fn ($n) => (int) $n)->all(), 'Sorted by count. Top 15 shown; every country is available in the table and CSV.', 'Applications'),
            $this->chart('onboarding', 'Institution onboarding', 'bar', array_values($institutionStatuses), $this->grouped(clone $institutions, 'status', $institutionStatuses), 'Current status of institutions created in the selected period. Drafts are shown separately.', 'Institutions'),
            $this->chart('users', 'User growth', 'line', $userDates, $users, 'New identities created in the selected period, including pending accounts. Not a cumulative total.', 'New accounts'),
        ];

        return ['charts' => $charts, 'kpis' => ['Applications received' => array_sum($received), 'Awaiting review' => (clone $applications)->whereIn('status', ['submitted', 'under_review', 'more_information_requested'])->count(), 'Institutions created' => (clone $institutions)->count(), 'New accounts' => array_sum($users)],
            'recentApplications' => (clone $applications)->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get(['uuid', 'reference', 'status', 'created_at']),
            'recentInstitutions' => (clone $institutions)->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get(['id', 'name', 'status']),
            'activities' => $this->window(DB::table('audit_entries'), $filters)->orderByDesc('id')->limit(5)->get(['action', 'created_at'])];
    }

    public function institution(Institution $institution, array $filters): array
    {
        $members = $this->window(DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('roles as r', 'r.id', '=', 'm.role_id')
            ->where('m.institution_id', $institution->id)->where('m.status', 'active')->where('u.status', 'active'), $filters, 'm.created_at');
        $students = (clone $members)->where('r.name', 'Student');
        $charts = [];
        foreach (['programmes' => ['programme_id', 'Students by programme'], 'intakes' => ['intake_id', 'Students by intake'], 'academic_years' => ['academic_year_id', 'Students by academic year'], 'academic_classes' => ['id', 'Class distribution']] as $table => [$column, $title]) {
            $enrolled = (clone $students)->join('class_assignments as a', fn ($join) => $join->on('a.membership_id', '=', 'm.id')->on('a.institution_id', '=', 'm.institution_id'))
                ->join('academic_classes as c', fn ($join) => $join->on('c.id', '=', 'a.academic_class_id')->on('c.institution_id', '=', 'm.institution_id'))
                ->select('c.'.$column.' as record_id')->selectRaw('COUNT(DISTINCT m.user_id) as total')->groupBy('c.'.$column);
            $records = DB::table($table.' as record')->leftJoinSub($enrolled, 'enrolled', 'enrolled.record_id', '=', 'record.id')->where('record.institution_id', $institution->id)
                ->select('record.name')->selectRaw('COALESCE(enrolled.total, 0) as total')->orderByDesc('total')->orderBy('record.name')->get();
            $charts[] = $this->chart($table, $title, 'bar', $records->pluck('name')->all(), $records->pluck('total')->map(fn ($n) => (int) $n)->all(), 'Unique active students who joined during the selected period, counted once per group via class assignments. Groups may overlap. Top 15 shown; all groups in table/CSV.', 'Students');
        }
        $composition = (clone $members)->select('r.name')->selectRaw('COUNT(DISTINCT m.user_id) as total')->groupBy('r.name')->pluck('total', 'name');
        $values = [(int) ($composition['Student'] ?? 0), (int) ($composition['Lecturer / Teacher'] ?? 0), (int) ($composition['Mentor / Supervisor'] ?? 0), (int) $composition->except(['Student', 'Lecturer / Teacher', 'Mentor / Supervisor'])->sum()];
        $charts[] = $this->chart('composition', 'User composition', 'doughnut', ['Students', 'Lecturers', 'Mentors', 'Other institution roles'], $values, 'Unique active users with active memberships created during the selected period. One primary role per institution; class leadership never adds another user.', 'Members');
        [$dates, $growth] = $this->timeline(DB::table('memberships')->where('institution_id', $institution->id), $filters);
        $charts[] = $this->chart('members', 'Account growth', 'line', $dates, $growth, 'New institutional memberships created in the selected period, including pending or subsequently suspended members. Not global account creation.', 'New members');

        return ['charts' => $charts, 'kpis' => ['Active members in cohort' => array_sum($values), 'Active students in cohort' => $values[0], 'New memberships' => array_sum($growth), 'Institution classes (all)' => DB::table('academic_classes')->where('institution_id', $institution->id)->count()],
            'recentApplications' => collect(), 'recentInstitutions' => collect(),
            'activities' => InstitutionAccess::allows($institution, 'audit.view') ? $this->window(DB::table('audit_entries')->where('institution_id', $institution->id), $filters)->orderByDesc('id')->limit(5)->get(['action', 'created_at']) : collect()];
    }

    public static function canViewInstitution(Institution $institution): bool
    {
        return InstitutionAccess::system() || InstitutionAccess::membership($institution)?->role->name === 'Institution Administrator';
    }
}
