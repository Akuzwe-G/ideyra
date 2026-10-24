<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectGroupRequest;
use App\Models\AcademicClass;
use App\Models\Membership;
use App\Models\ProjectGroup;
use App\Support\GroupWorkflow;
use App\Support\InnovationAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectGroupController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['q' => 'nullable|string|max:180', 'status' => 'nullable|in:draft,active,completed,archived', 'academic_class_id' => 'nullable|integer', 'academic_year_id' => 'nullable|integer', 'programme_id' => 'nullable|integer']);
        $groups = InnovationAccess::groups($request->user())->with('institution', 'academicClass')->withCount('members', 'supervisors')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('academic_class_id'), fn ($q) => $q->where('academic_class_id', $request->integer('academic_class_id')))
            ->when($request->filled('programme_id'), fn ($q) => $q->whereHas('academicClass', fn ($c) => $c->where('programme_id', $request->integer('programme_id'))))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->whereHas('academicClass', fn ($c) => $c->where('academic_year_id', $request->integer('academic_year_id'))))->latest()->paginate(12)->withQueryString();

        $classes = AcademicClass::with('programme', 'academicYear')->whereIn('id', InnovationAccess::groups($request->user())->select('academic_class_id'))->get();

        return view('innovation.groups.index', compact('groups', 'classes'));
    }

    public function create(Request $request): View
    {
        return $this->form($request, new ProjectGroup(['status' => 'draft']));
    }

    public function edit(Request $request, ProjectGroup $group): View
    {
        Gate::authorize('update', $group);

        return $this->form($request, $group);
    }

    private function form(Request $request, ProjectGroup $group): View
    {
        $memberships = InnovationAccess::memberships($request->user())->filter(fn ($m) => InnovationAccess::can($m->institution, 'groups.create', $request->user()));
        $institutionId = $group->institution_id ?: (int) $request->query('institution_id', $memberships->first()?->institution_id);
        abort_unless($memberships->contains('institution_id', $institutionId) || ($group->exists && Gate::allows('update', $group)), 403);
        $classes = AcademicClass::with('programme', 'academicYear', 'intake')->where('institution_id', $institutionId)->get();
        $people = Membership::with('user', 'role')->where('institution_id', $institutionId)->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active'))->get();
        $group->load('members', 'supervisors');

        return view('innovation.groups.form', compact('group', 'memberships', 'institutionId', 'classes', 'people'));
    }

    public function store(ProjectGroupRequest $request, GroupWorkflow $workflow): RedirectResponse
    {
        return redirect()->route('groups.show', $workflow->save($request->validated()))->with('status', 'Group created.');
    }

    public function update(ProjectGroupRequest $request, ProjectGroup $group, GroupWorkflow $workflow): RedirectResponse
    {
        $workflow->save($request->validated(), $group);

        return redirect()->route('groups.show', $group)->with('status', 'Group updated.');
    }

    public function show(ProjectGroup $group): View
    {
        Gate::authorize('view', $group);
        $group->load('institution', 'academicClass.programme', 'academicClass.intake', 'academicClass.academicYear', 'members.membership.user', 'supervisors.user');
        $projects = InnovationAccess::projects(auth()->user())->where('group_id', $group->id)->latest()->paginate(10);

        return view('innovation.groups.show', compact('group', 'projects'));
    }
}
