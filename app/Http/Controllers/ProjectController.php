<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Models\AcademicClass;
use App\Models\Idea;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Support\InnovationAccess;
use App\Support\ProjectWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['q' => 'nullable|string|max:180', 'status' => 'nullable|string|max:40', 'institution_id' => 'nullable|integer', 'category' => 'nullable|string|max:100']);
        $projects = InnovationAccess::projects($request->user())->with('institution', 'owner', 'group')->withCount(['tasks', 'tasks as done_count' => fn ($q) => $q->where('status', 'completed'), 'deliverables', 'deliverables as approved_count' => fn ($q) => $q->where('status', 'approved')])
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('institution_id'), fn ($q) => $q->where('institution_id', $request->integer('institution_id')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))->when($request->filled('academic_class_id'), fn ($q) => $q->where('academic_class_id', $request->integer('academic_class_id')))
            ->when($request->filled('programme_id'), fn ($q) => $q->whereHas('academicClass', fn ($c) => $c->where('programme_id', $request->integer('programme_id'))))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->whereHas('academicClass', fn ($c) => $c->where('academic_year_id', $request->integer('academic_year_id'))))->latest()->paginate(12)->withQueryString();

        $request->validate(['academic_year_id' => 'nullable|integer', 'programme_id' => 'nullable|integer', 'academic_class_id' => 'nullable|integer']);
        $classes = AcademicClass::with('programme', 'academicYear')->whereIn('id', InnovationAccess::projects($request->user())->select('academic_class_id'))->get();

        return view('innovation.projects.index', compact('projects', 'classes'));
    }

    public function create(Request $request): View
    {
        $project = new Project;
        if ($request->filled('idea')) {
            $idea = Idea::findOrFail($request->integer('idea'));
            Gate::authorize('view', $idea);
            abort_unless($idea->owner_id === $request->user()->id, 403);
            $project->fill(['idea_id' => $idea->id, 'institution_id' => $idea->institution_id, 'title' => $idea->title, 'description' => $idea->description, 'problem' => $idea->problem, 'objectives' => $idea->solution, 'outcomes' => $idea->beneficiaries, 'category' => $idea->category]);
        }

        return $this->form($project);
    }

    public function edit(Project $project): View
    {
        Gate::authorize('update', $project);

        return $this->form($project);
    }

    private function form(Project $project): View
    {
        $memberships = InnovationAccess::memberships(auth()->user())->filter(fn ($m) => InnovationAccess::can($m->institution, 'projects.create_institutional', auth()->user()) && InnovationAccess::can($m->institution, 'projects.create', auth()->user()));
        $groups = InnovationAccess::groups(auth()->user())->with('institution')->whereIn('status', ['draft', 'active'])->get();
        $classes = AcademicClass::with('institution')->whereIn('institution_id', $memberships->pluck('institution_id')->push($project->institution_id)->filter())->get();

        return view('innovation.projects.form', compact('project', 'memberships', 'groups', 'classes'));
    }

    public function store(ProjectRequest $request, ProjectWorkflow $workflow): RedirectResponse
    {
        return redirect()->route('projects.show', $workflow->save($request->validated()))->with('status', 'Project created.');
    }

    public function update(ProjectRequest $request, Project $project, ProjectWorkflow $workflow): RedirectResponse
    {
        $workflow->save($request->validated(), $project);

        return redirect()->route('projects.show', $project)->with('status', 'Project updated.');
    }

    public function show(Request $request, Project $project): View
    {
        Gate::authorize('view', $project);
        $request->validate(['tab' => 'nullable|in:overview,tasks,milestones,deliverables,team,feedback,activity', 'q' => 'nullable|string|max:180', 'status' => 'nullable|in:to_do,in_progress,in_review,completed,blocked', 'priority' => 'nullable|in:low,medium,high,urgent', 'assignee' => 'nullable|integer', 'due_before' => 'nullable|date_format:Y-m-d', 'view' => 'nullable|in:list,board']);
        $project->load('institution', 'owner', 'group', 'academicClass', 'milestones', 'members.user');
        $tab = $request->query('tab') ?: 'overview';
        $request->validate(['deliverable_q' => 'nullable|string|max:180', 'deliverable_status' => 'nullable|in:pending,submitted,changes_requested,approved', 'deliverable_assignee' => 'nullable|integer', 'deliverable_due_before' => 'nullable|date_format:Y-m-d']);
        $tasks = $project->tasks()->with('assignees', 'milestone')
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->string('priority')))
            ->when($request->filled('assignee'), fn ($q) => $q->whereHas('assignees', fn ($a) => $a->where('users.id', $request->integer('assignee'))))
            ->when($request->filled('due_before'), fn ($q) => $q->whereDate('due_at', '<=', $request->string('due_before')))->orderBy('due_at')->paginate(25)->withQueryString();
        $deliverables = $project->deliverables()->with('assignedUser', 'submissions.submitter')
            ->when($request->filled('deliverable_q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('deliverable_q').'%'))
            ->when($request->filled('deliverable_status'), fn ($q) => $q->where('status', $request->string('deliverable_status')))
            ->when($request->filled('deliverable_assignee'), fn ($q) => $q->where('assigned_user_id', $request->integer('deliverable_assignee')))
            ->when($request->filled('deliverable_due_before'), fn ($q) => $q->whereDate('due_at', '<=', $request->string('deliverable_due_before')))
            ->orderBy('due_at')->paginate(10, ['*'], 'deliverables_page')->withQueryString();
        $notes = $project->notes()->with('author')->when(! InnovationAccess::supervisor($project, $request->user()), fn ($q) => $q->where('visibility', 'feedback'))->paginate(15, ['*'], 'notes_page')->withQueryString();
        $activities = $project->activities()->with('actor')->paginate(15, ['*'], 'activity_page')->withQueryString();
        $participants = InnovationAccess::participants($project);
        $decisions = DB::table('project_decisions')->join('users', 'users.id', '=', 'reviewer_id')->where('project_id', $project->id)->select('project_decisions.*', 'users.name')->orderByDesc('project_decisions.id')->get();
        $reviews = DB::table('deliverable_reviews')->whereIn('submission_id', $deliverables->getCollection()->flatMap(fn ($d) => $d->submissions->pluck('id')))->get()->keyBy('submission_id');

        return view('innovation.projects.show', compact('project', 'tab', 'tasks', 'deliverables', 'notes', 'activities', 'participants', 'decisions', 'reviews'));
    }

    public function action(Request $request, Project $project, ProjectWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['action' => 'required|in:transition,review,team', 'status' => 'nullable|in:draft,pending_approval,in_progress,on_hold,completed,cancelled,archived', 'decision' => 'nullable|in:approved,returned,rejected', 'reason' => 'nullable|string|max:6000', 'email' => 'nullable|email|max:254', 'role' => 'nullable|in:member,leader,supervisor']);
        if ($data['action'] === 'transition') {
            $request->validate(['status' => 'required']);
            $workflow->transition($project, $data['status'], $data['reason'] ?? null);
        } elseif ($data['action'] === 'review') {
            $request->validate(['decision' => 'required', 'reason' => 'required']);
            $workflow->review($project, $data['decision'], $data['reason']);
        } else {
            $request->validate(['email' => 'required', 'role' => 'required']);
            $workflow->team($project, $data);
        }

        return back()->with('status', 'Project action recorded.');
    }

    public function remove(Project $project, ProjectMember $member, ProjectWorkflow $workflow): RedirectResponse
    {
        $workflow->removeTeam($project, $member);

        return back()->with('status', 'Direct assignment removed.');
    }
}
