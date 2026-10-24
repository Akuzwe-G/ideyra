<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectTaskRequest;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Submission;
use App\Support\DashboardAnalytics;
use App\Support\InnovationAccess;
use App\Support\ProjectAnalytics;
use App\Support\ProjectWorkWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectWorkController extends Controller
{
    public function milestone(Request $request, Project $project, ProjectWorkWorkflow $workflow, ?Milestone $milestone = null): RedirectResponse
    {
        $workflow->milestone($project, $request->validate(['title' => 'required|string|max:180', 'description' => 'nullable|string|max:6000', 'due_at' => 'required|date_format:Y-m-d', 'status' => 'required|in:not_started,in_progress,completed', 'sort_order' => 'required|integer|min:0|max:10000']), $milestone);

        return back()->with('status', 'Milestone saved.');
    }

    public function task(ProjectTaskRequest $request, Project $project, ProjectWorkWorkflow $workflow, ?ProjectTask $task = null): RedirectResponse
    {
        $workflow->task($project, $request->validated(), $task);

        return back()->with('status', 'Task saved.');
    }

    public function progress(Request $request, Project $project, ProjectTask $task, ProjectWorkWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:to_do,in_progress,in_review,completed,blocked', 'comment' => 'nullable|string|max:6000']);
        $workflow->progress($project, $task, $data['status'], $data['comment'] ?? null);

        return back()->with('status', 'Task progress saved.');
    }

    public function taskShow(Project $project, ProjectTask $task): View
    {
        Gate::authorize('view', $project);
        abort_unless($task->project_id === $project->id, 404);
        $task->load('assignees', 'milestone');
        $participants = InnovationAccess::participants($project);
        $comments = DB::table('task_comments')->join('users', 'users.id', '=', 'author_id')->where('task_id', $task->id)->select('task_comments.*', 'users.name')->orderBy('task_comments.id')->paginate(20);

        return view('innovation.projects.task', compact('project', 'task', 'participants', 'comments'));
    }

    public function deliverable(Request $request, Project $project, ProjectWorkWorkflow $workflow, ?Deliverable $deliverable = null): RedirectResponse
    {
        $workflow->deliverable($project, $request->validate(['title' => 'required|string|max:180', 'description' => 'nullable|string|max:6000', 'milestone_id' => 'nullable|integer', 'assigned_user_id' => 'required|integer', 'due_at' => 'required|date_format:Y-m-d']), $deliverable);

        return back()->with('status', 'Deliverable saved.');
    }

    public function submit(Request $request, Project $project, Deliverable $deliverable, ProjectWorkWorkflow $workflow): RedirectResponse
    {
        $workflow->submit($project, $deliverable, $request->validate(['body' => 'required_without:file|nullable|string|max:12000', 'file' => 'required_without:body|nullable|file|mimes:pdf,txt,csv,png,jpg,jpeg,webp,docx,xlsx,pptx,zip|max:10240']));

        return back()->with('status', 'Submission version stored privately.');
    }

    public function review(Request $request, Project $project, Deliverable $deliverable, ProjectWorkWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => 'required|in:approved,changes_requested', 'feedback' => 'required|string|max:6000']);
        $workflow->review($project, $deliverable, $data['decision'], $data['feedback']);

        return back()->with('status', 'Review recorded.');
    }

    public function download(Project $project, Submission $submission): StreamedResponse
    {
        Gate::authorize('view', $project);
        abort_unless($submission->project_id === $project->id && $submission->file_path, 404);
        abort_unless(Storage::disk('local')->exists($submission->file_path), 404);

        return Storage::disk('local')->download($submission->file_path, $submission->file_name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function note(Request $request, Project $project, ProjectWorkWorkflow $workflow): RedirectResponse
    {
        $workflow->note($project, $request->validate(['body' => 'required|string|max:12000', 'visibility' => 'required|in:feedback,internal', 'deliverable_id' => 'nullable|integer']));

        return back()->with('status', 'Supervision note stored.');
    }

    public function mine(Request $request): View
    {
        $ids = InnovationAccess::projects($request->user())->select('projects.id');
        $tasks = ProjectTask::with('project', 'assignees')->whereIn('project_id', clone $ids)->whereHas('assignees', fn ($q) => $q->where('users.id', $request->user()->id))->where('status', '!=', 'completed')->orderBy('due_at')->paginate(15);
        $deliverables = Deliverable::with('project')->whereIn('project_id', clone $ids)->where('assigned_user_id', $request->user()->id)->where('status', '!=', 'approved')->orderBy('due_at')->paginate(10, ['*'], 'deliverables_page');

        return view('innovation.mine', compact('tasks', 'deliverables'));
    }

    public function supervision(Request $request): View
    {
        $projects = InnovationAccess::supervised($request->user())->with('institution', 'group')->withCount(['deliverables as pending_reviews' => fn ($q) => $q->where('status', 'submitted')])->latest()->paginate(12)->withQueryString();

        $filters = app(DashboardAnalytics::class)->filters($request);
        $analytics = app(ProjectAnalytics::class)->data($filters, null, true);

        return view('innovation.supervision', compact('projects', 'filters', 'analytics'));
    }
}
