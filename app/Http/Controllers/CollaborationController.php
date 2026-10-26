<?php

namespace App\Http\Controllers;

use App\Http\Requests\CollaborationRequestForm;
use App\Models\CollaborationEntry;
use App\Models\CollaborationFile;
use App\Models\CollaborationMember;
use App\Models\CollaborationRequest;
use App\Models\CollaborationWorkspace;
use App\Models\NetworkPublication;
use App\Support\CollaborationWorkflow;
use App\Support\InnovationAccess;
use App\Support\InstitutionAccess;
use App\Support\NetworkAccess;
use App\Support\NetworkSignals;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CollaborationController extends Controller
{
    public function requests(Request $request): View
    {
        $user = $request->user();
        $request->validate(['status' => 'nullable|in:draft,submitted,under_review,accepted,declined,withdrawn,expired']);
        $memberships = InnovationAccess::memberships($user);
        $review = $memberships->filter(fn ($m) => NetworkAccess::can($user, $m->institution, 'collaborations.review'))->pluck('institution_id');
        $ownInstitutions = $memberships->pluck('institution_id');
        $representativeInstitutions = $memberships->filter(fn ($m) => NetworkAccess::can($user, $m->institution, 'showcase.submit'))->pluck('institution_id');
        $sources = InnovationAccess::projects($user)->where(fn ($q) => $q->where('owner_id', $user->id)->orWhereHas('group.members', fn ($g) => $g->whereIn('membership_id', $memberships->pluck('id'))->where('role', 'leader')))->select('projects.id');
        $represented = NetworkPublication::whereIn('institution_id', $representativeInstitutions)->whereIn('project_id', $sources)->select('network_publications.id');
        $requests = CollaborationRequest::where(function ($q) use ($user, $review, $ownInstitutions, $represented): void {
            $q->where(fn ($own) => $own->where('user_id', $user->id)->whereIn('institution_id', $ownInstitutions));
            $q->orWhere(fn ($received) => $received->where('status', '!=', 'draft')->where(function ($r) use ($review, $represented): void {
                $r->whereIn('institution_id', $review)->orWhereIn('network_publication_id', $represented)->orWhereHas('publication', fn ($p) => $p->whereIn('institution_id', $review));
            }));
        })->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))->with('publication', 'institution')->orderByDesc('created_at')->paginate(15)->withQueryString();

        return view('network.requests', compact('requests'));
    }

    public function requestForm(Request $request, NetworkPublication $publication): View
    {
        abort_unless(NetworkAccess::publicationVisible($request->user(), $publication) && $publication->collaboration_open, 403);
        $memberships = InnovationAccess::memberships($request->user())->filter(fn ($m) => NetworkAccess::can($request->user(), $m->institution, 'collaborations.request') && $m->institution_id !== $publication->institution_id && NetworkAccess::setting($m->institution_id)->allow_network);
        $projects = InnovationAccess::projects($request->user())->where('owner_id', $request->user()->id)->orderBy('title')->limit(100)->get(['projects.id', 'projects.title']);

        return view('network.request-form', compact('publication', 'memberships', 'projects'));
    }

    public function saveRequest(CollaborationRequestForm $request, NetworkPublication $publication): RedirectResponse
    {
        $r = app(CollaborationWorkflow::class)->save($request->user(), $publication, $request->validated());

        return redirect()->route('network.requests.show', $r)->with('status', 'Private request draft saved. Submit it when ready to share with the project representative.');
    }

    public function showRequest(Request $request, CollaborationRequest $collaborationRequest): View
    {
        Gate::authorize('view', $collaborationRequest);
        $collaborationRequest->load('publication', 'institution', 'requester', 'approvals.institution', 'workspace');
        $history = DB::table('network_actions as action')->join('users as actor', 'actor.id', '=', 'action.user_id')->where('action.subject_type', 'CollaborationRequest')->where('action.subject_id', $collaborationRequest->id)->orderByDesc('action.id')->select('action.action', 'action.details', 'action.created_at', 'actor.name')->paginate(15);
        $history->through(function ($entry): object {
            $details = json_decode(Crypt::decryptString($entry->details), true, 512, JSON_THROW_ON_ERROR);
            $entry->reason = $details['reason'] ?? null;
            unset($entry->details);

            return $entry;
        });

        return view('network.request-detail', ['item' => $collaborationRequest, 'history' => $history]);
    }

    public function requestAction(Request $request, CollaborationRequest $collaborationRequest): RedirectResponse
    {
        $d = $request->validate(['action' => 'required|in:submit,under_review,accept,decline,withdraw', 'reason' => 'required|string|max:3000', 'confirm' => 'accepted']);
        app(CollaborationWorkflow::class)->action($request->user(), $collaborationRequest, $d['action'], $d['reason']);

        return redirect()->route('network.requests.show', $collaborationRequest)->with('status', 'Collaboration request decision recorded. Required institutional approvals remain independent.');
    }

    public function approve(Request $request, CollaborationRequest $collaborationRequest): RedirectResponse
    {
        $d = $request->validate(['institution_id' => 'required|integer', 'approve' => 'required|boolean', 'reason' => 'required|string|max:3000', 'confirm' => 'accepted']);
        app(CollaborationWorkflow::class)->approve($request->user(), $collaborationRequest, (int) $d['institution_id'], (bool) $d['approve'], $d['reason']);

        return redirect()->route('network.requests.show', $collaborationRequest)->with('status', 'Institutional decision recorded.');
    }

    public function workspaces(Request $request): View
    {
        $ids = InnovationAccess::memberships($request->user())->pluck('institution_id');
        $workspaces = NetworkAccess::workspaces($request->user(), true)->with(['members' => fn ($q) => $q->where('user_id', $request->user()->id)])->orderByDesc('created_at')->paginate(15);

        return view('network.workspaces', compact('workspaces'));
    }

    public function workspace(Request $request, CollaborationWorkspace $workspace): View
    {
        $own = $workspace->members()->where('user_id', $request->user()->id)->first();
        abort_unless($own, 403);
        if ($own->status === 'invited') {
            abort_unless(NetworkAccess::workspaces($request->user(), true)->whereKey($workspace->id)->exists(), 403);
            abort_unless($workspace->status === 'active' && InstitutionAccess::membership($own->institution, $request->user()) && NetworkAccess::setting($own->institution_id)->allow_network, 403);

            return view('network.workspace-invitation', compact('workspace', 'own'));
        }
        Gate::authorize('view', $workspace);
        $d = $request->validate(['tab' => 'nullable|in:overview,members,discussions,tasks,milestones,files,updates,activity', 'thread_id' => 'nullable|integer', 'edit_entry_id' => 'nullable|integer']);
        $tab = $d['tab'] ?? 'overview';
        $members = $workspace->members()->with('user', 'institution')->orderBy('created_at')->get();
        $kind = match ($tab) {
            'discussions' => 'thread','tasks' => 'task','milestones' => 'milestone',default => 'update'
        };
        $query = $workspace->entries()->whereNull('removed_at')->where('kind', $kind)->with('author', 'assignee')->withCount('replies')->orderByDesc('created_at');
        if (! empty($d['thread_id'])) {
            $query->whereKey($d['thread_id']);
        }
        $entries = $query->paginate(10)->withQueryString();
        $thread = null;
        $replies = null;
        if (! empty($d['thread_id'])) {
            $thread = $workspace->entries()->whereKey($d['thread_id'])->where('kind', 'thread')->whereNull('removed_at')->firstOrFail();
            $replies = $thread->replies()->with('author')->paginate(15, ['*'], 'reply_page')->withQueryString();
        }
        $files = $workspace->files()->whereNull('removed_at')->where(fn ($q) => $q->whereNull('collaboration_entry_id')->orWhereIn('collaboration_entry_id', $workspace->entries()->whereNull('removed_at')->select('id')))->with('uploader')->orderByDesc('created_at')->paginate(15, ['*'], 'file_page')->withQueryString();
        $activity = DB::table('network_actions as na')->join('users as u', 'u.id', '=', 'na.user_id')->where('na.subject_type', 'CollaborationWorkspace')->where('na.subject_id', $workspace->id)->orderByDesc('na.id')->select('na.action', 'na.created_at', 'u.name')->paginate(20, ['*'], 'activity_page')->withQueryString();
        $editing = null;
        if (! empty($d['edit_entry_id'])) {
            $editing = $workspace->entries()->whereKey($d['edit_entry_id'])->whereNull('removed_at')->firstOrFail();
            abort_unless($editing->user_id === $request->user()->id || NetworkAccess::member($request->user(), $workspace, true), 403);
        }

        return view('network.workspace', compact('workspace', 'own', 'tab', 'members', 'entries', 'thread', 'replies', 'files', 'activity', 'editing'));
    }

    public function acceptInvitation(Request $request, CollaborationWorkspace $workspace): RedirectResponse
    {
        $d = $request->validate(['accept' => 'required|boolean', 'confirm' => 'accepted']);
        app(CollaborationWorkflow::class)->acceptInvitation($request->user(), $workspace, (bool) $d['accept']);

        return redirect()->route('network.workspaces')->with('status', 'Invitation decision recorded.');
    }

    public function invite(Request $request, CollaborationWorkspace $workspace): RedirectResponse
    {
        $d = $request->validate(['email' => 'required|email|max:255', 'institution_id' => 'required|integer', 'role' => 'required|in:member,mentor,admin', 'confirm' => 'accepted']);
        app(CollaborationWorkflow::class)->invite($request->user(), $workspace, $d['email'], (int) $d['institution_id'], $d['role']);

        return redirect()->route('network.workspaces.show', [$workspace, 'tab' => 'members'])->with('status', 'Private invitation sent in-app. Access starts only after acceptance.');
    }

    public function member(Request $request, CollaborationWorkspace $workspace, CollaborationMember $member): RedirectResponse
    {
        $d = $request->validate(['action' => 'required|in:remove,role', 'role' => 'nullable|in:member,mentor,admin', 'confirm' => 'accepted']);
        app(CollaborationWorkflow::class)->membership($request->user(), $workspace, $member, $d['action'], $d['role'] ?? null);

        return redirect()->route('network.workspaces.show', [$workspace, 'tab' => 'members'])->with('status', 'Membership updated. Revoked members immediately lose access.');
    }

    public function entry(Request $request, CollaborationWorkspace $workspace, ?CollaborationEntry $entry = null): RedirectResponse
    {
        $d = $request->validate(['kind' => 'required|in:thread,reply,task,milestone,update', 'title' => 'required|string|max:180', 'body' => 'required|string|max:10000', 'parent_id' => 'nullable|integer', 'assigned_to' => 'nullable|integer', 'due_at' => 'nullable|date_format:Y-m-d', 'mention_ids' => 'nullable|array|max:20', 'mention_ids.*' => 'integer|distinct', 'confirm' => 'accepted']);
        $e = app(CollaborationWorkflow::class)->entry($request->user(), $workspace, $d, $entry);
        $tab = match ($e->kind) {
            'thread','reply' => 'discussions','task' => 'tasks','milestone' => 'milestones',default => 'updates'
        };

        return redirect()->route('network.workspaces.show', [$workspace, 'tab' => $tab])->with('status', 'Explicitly shared collaboration entry saved.');
    }

    public function entryAction(Request $request, CollaborationEntry $entry): RedirectResponse
    {
        $d = $request->validate(['action' => 'required|in:remove,open,completed', 'confirm' => 'accepted']);
        app(CollaborationWorkflow::class)->entryAction($request->user(), $entry, $d['action']);

        return redirect()->route('network.workspaces.show', $entry->workspace)->with('status', 'Collaboration entry updated.');
    }

    public function upload(Request $request, CollaborationWorkspace $workspace): RedirectResponse
    {
        $d = $request->validate(['file' => 'required|file|mimes:pdf,png,jpg,jpeg,txt|max:5120', 'collaboration_entry_id' => 'nullable|integer', 'confirm' => 'accepted']);
        app(CollaborationWorkflow::class)->file($request->user(), $workspace, $d['file'], empty($d['collaboration_entry_id']) ? null : (int) $d['collaboration_entry_id']);

        return redirect()->route('network.workspaces.show', [$workspace, 'tab' => 'files'])->with('status', 'File explicitly shared inside this workspace only.');
    }

    public function file(Request $request, CollaborationFile $file): BinaryFileResponse
    {
        Gate::authorize('view', $file->workspace);
        abort_if($file->removed_at, 404);
        if ($file->collaboration_entry_id) {
            abort_unless($file->workspace->entries()->whereKey($file->collaboration_entry_id)->whereNull('removed_at')->exists(), 404);
        }abort_unless(Storage::disk('local')->exists($file->path), 404);
        NetworkSignals::record('workspace.file_downloaded', $file->workspace, $file->workspace->institution_id, ['file_id' => $file->id]);

        return response()->download(Storage::disk('local')->path($file->path), $file->name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function removeFile(Request $request, CollaborationFile $file): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted']);
        Gate::authorize('view', $file->workspace);
        abort_unless($file->workspace->status === 'active' && ($file->user_id === $request->user()->id || NetworkAccess::member($request->user(), $file->workspace, true)), 403);
        $file->update(['removed_at' => now()]);
        Storage::disk('local')->delete($file->path);
        NetworkSignals::record('workspace.file_removed', $file->workspace, $file->workspace->institution_id, ['file_id' => $file->id]);

        return redirect()->route('network.workspaces.show', [$file->workspace, 'tab' => 'files'])->with('status', 'Shared file removed.');
    }

    public function update(Request $request, CollaborationWorkspace $workspace): RedirectResponse
    {
        Gate::authorize('update', $workspace);
        $d = $request->validate(['title' => 'required|string|max:180', 'purpose' => 'required|string|max:6000', 'ends_at' => 'nullable|date_format:Y-m-d|after_or_equal:'.$workspace->starts_at->toDateString(), 'confirm' => 'accepted']);
        $workspace->update(collect($d)->except('confirm')->all());
        NetworkSignals::record('workspace.details_updated', $workspace, $workspace->institution_id);

        return redirect()->route('network.workspaces.show', $workspace)->with('status', 'Shared workspace details updated.');
    }

    public function close(Request $request, CollaborationWorkspace $workspace): RedirectResponse
    {
        $d = $request->validate(['reason' => 'required|string|max:3000', 'confirm' => 'accepted']);
        app(CollaborationWorkflow::class)->close($request->user(), $workspace, $d['reason']);

        return redirect()->route('network.workspaces.show', $workspace)->with('status','Workspace closed. Current active members retain read-only access.');
    }
}
