<?php

namespace App\Http\Controllers;

use App\Http\Requests\IdeaRequest;
use App\Models\Idea;
use App\Support\IdeaWorkflow;
use App\Support\InnovationAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class IdeaController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['q' => 'nullable|string|max:180', 'status' => 'nullable|string|max:40', 'category' => 'nullable|string|max:100', 'tag' => 'nullable|string|max:32']);
        $ideas = InnovationAccess::ideas($request->user())->with('institution', 'owner')
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('tag'), fn ($q) => $q->whereJsonContains('tags', (string) $request->string('tag')))
            ->latest()->paginate(12)->withQueryString();

        return view('innovation.ideas.index', compact('ideas'));
    }

    public function create(): View
    {
        return $this->form(new Idea(['visibility' => 'private', 'status' => 'draft']));
    }

    public function edit(Idea $idea): View
    {
        Gate::authorize('update', $idea);

        return $this->form($idea);
    }

    private function form(Idea $idea): View
    {
        $memberships = InnovationAccess::memberships(auth()->user())->filter(fn ($m) => InnovationAccess::can($m->institution, 'ideas.create', auth()->user()));

        return view('innovation.ideas.form', compact('idea', 'memberships'));
    }

    public function store(IdeaRequest $request, IdeaWorkflow $workflow): RedirectResponse
    {
        return redirect()->route('ideas.show', $workflow->save($request->validated()))->with('status', 'Idea saved.');
    }

    public function update(IdeaRequest $request, Idea $idea, IdeaWorkflow $workflow): RedirectResponse
    {
        $workflow->save($request->validated(), $idea);

        return redirect()->route('ideas.show', $idea)->with('status', 'Idea updated.');
    }

    public function show(Idea $idea): View
    {
        Gate::authorize('view', $idea);
        $idea->load('owner', 'institution', 'reviews.reviewer', 'project');
        $memberships = InnovationAccess::memberships(auth()->user());
        $reviewers = $idea->institution_id ? $idea->institution->memberships()->with('user', 'role')->where('status', 'active')->get()->filter(fn ($m) => $m->user_id !== $idea->owner_id && InnovationAccess::can($idea->institution, 'ideas.review', $m->user)) : collect();

        return view('innovation.ideas.show', compact('idea', 'memberships', 'reviewers'));
    }

    public function action(Request $request, Idea $idea, IdeaWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['action' => 'required|in:publish,submit,review,archive', 'institution_id' => 'nullable|integer|exists:institutions,id', 'reviewer_id' => 'nullable|integer|exists:users,id', 'decision' => 'nullable|in:under_review,approved,returned', 'feedback' => 'nullable|string|max:6000', 'confirm_share' => 'sometimes|nullable|accepted']);
        switch ($data['action']) {
            case 'publish':
                $request->validate(['institution_id' => 'required|integer', 'confirm_share' => 'accepted']);
                $idea = $workflow->publish($idea, (int) $data['institution_id']);
                break;
            case 'submit':
                $request->validate(['confirm_share' => 'accepted']);
                $workflow->submit($idea, $data['reviewer_id'] ?? null);
                break;
            case 'review':
                $request->validate(['decision' => 'required', 'feedback' => 'required|string']);
                $workflow->review($idea, $data['decision'], $data['feedback']);
                break;
            case 'archive':
                Gate::authorize('update', $idea);
                $idea->update(['status' => 'archived']);
                break;
        }

        return redirect()->route('ideas.show', $idea)->with('status', 'Idea action recorded.');
    }
}
