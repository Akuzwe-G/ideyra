<?php

namespace App\Support;

use App\Models\Deliverable;
use App\Models\Institution;
use App\Models\Project;
use App\Models\ProjectTask;
use Carbon\CarbonImmutable;

class ProjectAnalytics
{
    public function data(array $filters, ?Institution $institution = null, bool $supervision = false): array
    {
        $scope = auth()->user() ? InnovationAccess::projects(auth()->user()) : Project::query()->whereRaw('1=0');
        $query = Project::query()->whereIn('projects.id', $scope->select('projects.id'))->when($institution, fn ($q) => $q->where('projects.institution_id', $institution->id));
        if ($supervision) {
            $query->whereIn('projects.id', InnovationAccess::supervised(auth()->user())->select('projects.id'));
        }
        $cohort = (clone $query)->whereBetween('projects.created_at', [$filters['from'].' 00:00:00', CarbonImmutable::parse($filters['to'])->addDay()->toDateTimeString()])->where('projects.created_at', '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateTimeString());
        $statuses = ['draft', 'pending_approval', 'approved', 'in_progress', 'on_hold', 'completed', 'cancelled', 'archived'];
        $counts = (clone $cohort)->select('projects.status')->selectRaw('COUNT(*) as total')->groupBy('projects.status')->pluck('total', 'status');
        $charts = [$this->chart('project-status', 'Project status', 'doughnut', array_map(fn ($s) => ucwords(str_replace('_', ' ', $s)), $statuses), array_map(fn ($s) => (int) ($counts[$s] ?? 0), $statuses), 'Current status of authorised projects created in this date range.')];
        foreach (['programmes' => ['programme_id', 'Projects by programme'], 'academic_years' => ['academic_year_id', 'Projects by academic year']] as $table => [$column,$title]) {
            $groups = (clone $cohort)->leftJoin('academic_classes as c', 'c.id', '=', 'projects.academic_class_id')->leftJoin($table.' as category', 'category.id', '=', 'c.'.$column)
                ->selectRaw("COALESCE(category.name, 'No academic context') as label, COUNT(*) as total")->groupBy('category.id', 'category.name')->orderByDesc('total')->get();
            $charts[] = $this->chart('project-'.$table, $title, 'bar', $groups->pluck('label')->all(), $groups->pluck('total')->map(fn ($n) => (int) $n)->all(), 'Authorised projects created in this date range. Personal projects have no academic context.');
        }
        $bucket = fn (CarbonImmutable $date) => match ($filters['group']) {
            'daily' => $date->startOfDay(),'weekly' => $date->startOfWeek(),default => $date->startOfMonth()
        };
        $values = [];
        $end = $bucket(CarbonImmutable::parse($filters['to']));
        for ($cursor = $bucket(CarbonImmutable::parse($filters['from'])); $cursor->lte($end); $cursor = match ($filters['group']) {
            'daily' => $cursor->addDay(),'weekly' => $cursor->addWeek(),default => $cursor->addMonth()
        }) {
            $values[$cursor->toDateString()] = 0;
        }
        $completed = (clone $query)->whereBetween('completed_at', [$filters['from'].' 00:00:00', CarbonImmutable::parse($filters['to'])->addDay()->toDateTimeString()])->where('completed_at', '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateTimeString())->selectRaw('DATE(completed_at) as day, COUNT(*) as total')->groupByRaw('DATE(completed_at)')->get();
        foreach ($completed as $record) {
            $values[$bucket(CarbonImmutable::parse($record->day))->toDateString()] += (int) $record->total;
        }
        $charts[] = $this->chart('project-completion', 'Project completion trend', 'line', array_keys($values), array_values($values), 'Final completion decisions dated in this range, including projects created earlier and subsequently archived. Not automatic work progress.');
        $ids = (clone $cohort)->select('projects.id');
        $tasks = ProjectTask::whereIn('project_id', clone $ids);
        $deliverables = Deliverable::whereIn('project_id', clone $ids);
        $pending = (clone $deliverables)->where('status', 'submitted')->count();
        $overdue = (clone $tasks)->where('status', '!=', 'completed')->whereDate('due_at', '<', today())->count();
        if ($supervision) {
            $workload = (clone $cohort)->withCount(['deliverables' => fn ($q) => $q->where('status', 'submitted')])->orderByDesc('deliverables_count')->get();
            $charts[] = $this->chart('project-workload', 'Supervision review workload', 'bar', $workload->pluck('title')->all(), $workload->pluck('deliverables_count')->map(fn ($n) => (int) $n)->all(), 'Submitted deliverables awaiting review per assigned project in this creation cohort.', 'Deliverables');
        }

        return ['charts' => $charts, 'kpis' => ['Total projects' => array_sum($counts->all()), 'Active projects' => array_sum($counts->only(['approved', 'in_progress', 'on_hold'])->all()), 'Completed projects' => (int) ($counts['completed'] ?? 0), 'Awaiting project approval' => (int) ($counts['pending_approval'] ?? 0), 'Pending submission reviews' => $pending, 'Overdue tasks' => $overdue], 'recentApplications' => collect(), 'recentInstitutions' => collect(), 'activities' => collect()];
    }

    private function chart(string $key, string $title, string $type, array $labels, array $values, string $note, string $unit = 'Projects'): array
    {
        return compact('key', 'title', 'type', 'labels', 'values', 'note', 'unit');
    }
}
