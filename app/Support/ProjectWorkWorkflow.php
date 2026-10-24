<?php

namespace App\Support;

use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ProjectWorkWorkflow
{
    public function manage(Project $project, string $permission): void
    {
        Gate::authorize('update', $project);
        ProjectWorkflow::writable($project);
        abort_unless(InnovationAccess::projectCapability($project, $permission, auth()->user()), 403);
    }

    public function milestone(Project $project, array $data, ?Milestone $milestone = null): void
    {
        Gate::authorize('update', $project);
        ProjectWorkflow::writable($project);
        abort_if($milestone && $milestone->project_id !== $project->id, 404);
        DB::transaction(function () use ($project, $data, $milestone): void {
            $project->milestones()->updateOrCreate(['id' => $milestone?->id], $data + ['completed_at' => $data['status'] === 'completed' ? now() : null]);
            ProjectWorkflow::activity($project, 'milestone.updated');
        });
    }

    public function task(Project $project, array $data, ?ProjectTask $task = null): void
    {
        $this->manage($project, 'tasks.create');
        abort_if($task && $task->project_id !== $project->id, 404);
        $this->milestoneContext($project, $data['milestone_id'] ?? null);
        $assignees = array_map('intval', $data['assignee_ids'] ?? []);
        $old = $task?->assignees()->pluck('users.id')->all() ?? [];
        sort($assignees);
        sort($old);
        if ($assignees !== $old) {
            abort_unless(InnovationAccess::projectCapability($project, 'tasks.assign', auth()->user()), 403);
        }
        abort_unless(empty(array_diff($assignees, InnovationAccess::participants($project)->pluck('id')->all())), 422, 'Assignees must be active participants in this project.');
        DB::transaction(function () use ($project, $data, $task, $assignees, $old): void {
            $task ??= new ProjectTask(['project_id' => $project->id, 'created_by' => auth()->id()]);
            $task->fill(collect($data)->except('assignee_ids')->all());
            $task->completed_at = $data['status'] === 'completed' ? ($task->completed_at ?? now()) : null;
            $task->save();
            $task->assignees()->syncWithPivotValues($assignees, ['project_id' => $project->id]);
            ProjectWorkflow::activity($project, 'task.updated', ['task_id' => $task->id]);
            ProjectSignals::send(array_diff($assignees, $old), 'project', $project, 'Task assignment', 'A task has been assigned to you in your project workspace.');
        });
    }

    public function progress(Project $project, ProjectTask $task, string $status, ?string $comment): void
    {
        Gate::authorize('view', $project);
        ProjectWorkflow::writable($project);
        abort_unless($task->project_id === $project->id, 404);
        $manager = InnovationAccess::projectManage($project, auth()->user());
        abort_unless($manager || ($task->assignees()->where('users.id', auth()->id())->exists() && (! $project->institution_id || InnovationAccess::can($project->institution, 'tasks.update_assigned', auth()->user()))), 403);
        abort_if(! $manager && $task->status === 'completed' && $status !== 'completed', 403);
        DB::transaction(function () use ($project, $task, $status, $comment): void {
            $task->update(['status' => $status, 'completed_at' => $status === 'completed' ? ($task->completed_at ?? now()) : null]);
            if ($comment) {
                DB::table('task_comments')->insert(['task_id' => $task->id, 'author_id' => auth()->id(), 'body' => $comment, 'created_at' => now(), 'updated_at' => now()]);
            }
            ProjectWorkflow::activity($project, 'task.progress_updated', ['task_id' => $task->id]);
        });
    }

    public function deliverable(Project $project, array $data, ?Deliverable $deliverable = null): void
    {
        $this->manage($project, 'deliverables.create');
        abort_if($deliverable && $deliverable->project_id !== $project->id, 404);
        abort_if($deliverable && $deliverable->status !== 'pending', 422, 'Submitted deliverables retain their original assignment.');
        $this->milestoneContext($project, $data['milestone_id'] ?? null);
        abort_unless(InnovationAccess::participants($project)->contains('id', (int) $data['assigned_user_id']), 422, 'Choose a current project participant.');
        $project->deliverables()->updateOrCreate(['id' => $deliverable?->id], $data);
        ProjectWorkflow::activity($project, 'deliverable.updated');
    }

    public function submit(Project $project, Deliverable $deliverable, array $data): void
    {
        Gate::authorize('view', $project);
        ProjectWorkflow::writable($project);
        abort_unless($deliverable->project_id === $project->id, 404);
        abort_unless($deliverable->assigned_user_id === auth()->id() && (! $project->institution_id || InnovationAccess::can($project->institution, 'deliverables.submit', auth()->user())), 403);
        $path = null;
        try {
            DB::transaction(function () use ($project, $deliverable, $data, &$path): void {
                $deliverable = Deliverable::whereKey($deliverable->id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($deliverable->status, ['pending', 'changes_requested'], true), 422, 'Await a review before submitting another version.');
                $file = $data['file'] ?? null;
                $path = $file?->store('project-submissions', 'local');
                $deliverable->submissions()->create([
                    'project_id' => $project->id, 'submitted_by' => auth()->id(), 'version' => $deliverable->submissions()->max('version') + 1,
                    'body' => $data['body'] ?? null, 'file_path' => $path,
                    'file_name' => $file ? mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename($file->getClientOriginalName())), 0, 180) : null,
                    'mime' => $file?->getMimeType(), 'bytes' => $file?->getSize(),
                ]);
                $deliverable->update(['status' => 'submitted']);
                ProjectWorkflow::activity($project, 'deliverable.submitted', ['deliverable_id' => $deliverable->id]);
                $reviewers = InnovationAccess::participants($project)->filter(fn ($user) => InnovationAccess::reviewDeliverables($project, $user));
                ProjectSignals::send($reviewers->pluck('id')->all(), 'project', $project, 'Submission ready for review', 'A new immutable submission version is ready in your project.');
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function review(Project $project, Deliverable $deliverable, string $decision, string $feedback): void
    {
        Gate::authorize('view', $project);
        ProjectWorkflow::writable($project);
        abort_unless($deliverable->project_id === $project->id, 404);
        abort_unless(InnovationAccess::reviewDeliverables($project, auth()->user()), 403);
        if ($decision === 'approved' && $project->institution_id) {
            abort_unless(InnovationAccess::can($project->institution, 'deliverables.approve', auth()->user()), 403);
        }
        DB::transaction(function () use ($project, $deliverable, $decision, $feedback): void {
            $deliverable = Deliverable::whereKey($deliverable->id)->lockForUpdate()->firstOrFail();
            abort_unless($deliverable->status === 'submitted', 422);
            $submission = $deliverable->submissions()->firstOrFail();
            abort_if($submission->submitted_by === auth()->id(), 403, 'Submissions require an independent reviewer.');
            DB::table('deliverable_reviews')->insert(['submission_id' => $submission->id, 'reviewer_id' => auth()->id(), 'decision' => $decision, 'feedback' => $feedback, 'created_at' => now(), 'updated_at' => now()]);
            $deliverable->update(['status' => $decision]);
            ProjectWorkflow::activity($project, 'deliverable.reviewed', ['deliverable_id' => $deliverable->id, 'decision' => $decision]);
            ProjectSignals::send([$deliverable->assigned_user_id, $project->owner_id], 'project', $project, 'Deliverable feedback available', 'Your project has new submission feedback.');
        });
    }

    public function note(Project $project, array $data): void
    {
        Gate::authorize('supervise', $project);
        ProjectWorkflow::writable($project);
        if (! empty($data['deliverable_id'])) {
            abort_unless($project->deliverables()->whereKey($data['deliverable_id'])->exists(), 422);
        }
        $project->notes()->create($data + ['author_id' => auth()->id()]);
        if ($data['visibility'] === 'feedback') {
            ProjectWorkflow::activity($project, 'supervision.feedback_added');
            ProjectSignals::send(InnovationAccess::participants($project)->pluck('id')->all(), 'project', $project, 'Supervisor feedback', 'New student-visible feedback is available in your project.');
        }
    }

    private function milestoneContext(Project $project, mixed $id): void
    {
        if ($id) {
            abort_unless($project->milestones()->whereKey($id)->exists(), 422, 'Choose a milestone from this project.');
        }
    }
}
