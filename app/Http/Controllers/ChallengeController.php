<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChallengeRequest;
use App\Models\ChallengeSubmission;
use App\Models\CollaborationWorkspace;
use App\Models\InnovationChallenge;
use App\Models\Institution;
use App\Models\OpportunityApplication;
use App\Models\Project;
use App\Models\User;
use App\Support\ChallengeWorkflow;
use App\Support\InnovationAccess;
use App\Support\NetworkAccess;
use App\Support\NetworkSignals;
use App\Support\OpportunityAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ChallengeController extends Controller
{
    public function index(Request $request): View
    {
        $d = $request->validate(['q' => 'nullable|string|max:180', 'institution_id' => 'nullable|integer', 'kind' => 'nullable|in:hackathon,competition,research,entrepreneurship,problem_solving,class_activity', 'status' => 'nullable|in:draft,submitted,published,closed,evaluation,results_published,archived', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $institutions = Institution::whereIn('id', NetworkAccess::challenges($request->user())->select('institution_id'))->orderBy('name')->get(['id', 'name']);
        $q = NetworkAccess::challenges($request->user())->with('opportunity', 'institution');
        if (! empty($d['q'])) {
            $q->whereHas('opportunity', fn ($o) => $o->where('title', 'like', '%'.$d['q'].'%'));
        }foreach (['institution_id', 'kind', 'status'] as $key) {
            if (! empty($d[$key])) {
                $q->where($key, $d[$key]);
            }
        }
        if (! empty($d['from']) || ! empty($d['to'])) {
            $q->whereHas('opportunity', function ($o) use ($d): void {
                if (! empty($d['from'])) {
                    $o->where('deadline_at', '>=', CarbonImmutable::parse($d['from'], config('intelligence.timezone'))->utc());
                }
                if (! empty($d['to'])) {
                    $o->where('deadline_at', '<', CarbonImmutable::parse($d['to'], config('intelligence.timezone'))->addDay()->utc());
                }
            });
        }
        $challenges = $q->orderByDesc('created_at')->paginate(12)->withQueryString();
        $memberships = $request->user() ? InnovationAccess::memberships($request->user())->filter(fn ($m) => NetworkAccess::can($request->user(), $m->institution, 'challenges.create')) : collect();

        return view('network.challenges', compact('challenges', 'memberships', 'institutions'));
    }

    public function form(Request $request, ?InnovationChallenge $challenge = null): View
    {
        if ($challenge) {
            Gate::authorize('update', $challenge);
            $challenge->load('opportunity', 'criteria');
        }
        $memberships = InnovationAccess::memberships($request->user())->filter(fn ($m) => NetworkAccess::can($request->user(), $m->institution, $challenge ? 'challenges.manage' : 'challenges.create'));

        return view('network.challenge-form', compact('challenge', 'memberships'));
    }

    public function save(ChallengeRequest $request, ?InnovationChallenge $challenge = null): RedirectResponse
    {
        $c = app(ChallengeWorkflow::class)->save($request->user(), $request->validated(), $challenge);

        return redirect()->route('network.challenges.show', $c)->with('status', 'Challenge draft saved using one authoritative Opportunities Hub record.');
    }

    public function show(Request $request, InnovationChallenge $challenge): View
    {
        abort_unless(NetworkAccess::challenges($request->user())->whereKey($challenge->id)->exists(), 404);
        $challenge->load('opportunity', 'institution', 'criteria');
        $user = $request->user();
        $applications = collect();
        $submissions = null;
        if ($user) {
            $active = InnovationAccess::memberships($user)->pluck('institution_id');
            $workspaceIds = NetworkAccess::workspaces($user)->select('collaboration_workspaces.id');
            $applications = OpportunityApplication::where('opportunity_id', $challenge->opportunity_id)->where(function ($q) use ($user, $active, $workspaceIds): void {
                $q->where(fn ($own) => $own->where('applicant_id', $user->id)->whereIn('institution_id', $active));
                $q->orWhere(fn ($cross) => $cross->whereJsonContains('participant_ids', $user->id)->whereIn('collaboration_workspace_id', $workspaceIds));
                $q->orWhere(fn ($group) => $group->whereJsonContains('participant_ids', $user->id)->whereIn('institution_id', $active)->whereHas('group.members', fn ($m) => $m->whereIn('membership_id', InnovationAccess::memberships($user)->pluck('id'))));
            })->orderByDesc('id')->limit(20)->get();
            if (NetworkAccess::challengeManage($user, $challenge)) {
                $submissions = $challenge->submissions()->with('application.applicant')->orderByDesc('submitted_at')->paginate(15);
            }
        }

        return view('network.challenge-detail', compact('challenge', 'applications', 'submissions'));
    }

    public function action(Request $request, InnovationChallenge $challenge): RedirectResponse
    {
        $d = $request->validate(['status' => 'required|in:submitted,approved,returned,rejected,published,closed,evaluation,results_published,unpublished,archived,draft', 'reason' => 'required|string|max:3000', 'confirm' => 'accepted']);
        app(ChallengeWorkflow::class)->transition($request->user(), $challenge, $d['status'], $d['reason']);

        return redirect()->route('network.challenges.show', $challenge)->with('status', 'Authorised challenge transition recorded.');
    }

    public function registration(Request $request, InnovationChallenge $challenge): View
    {
        Gate::authorize('view', $challenge);
        $user = $request->user();
        $memberships = InnovationAccess::memberships($user)->filter(fn ($m) => $m->role->name === 'Student' && OpportunityAccess::eligible($challenge->opportunity, $user, $m->institution_id));
        $groups = InnovationAccess::groups($user)->whereHas('members', fn ($q) => $q->whereIn('membership_id', $memberships->pluck('id'))->where('role', 'leader'))->orderBy('name')->limit(100)->get();
        $workspaces = NetworkAccess::workspaces($user)->where('status', 'active')->whereHas('members', fn ($q) => $q->where('user_id', $user->id)->where('role', 'admin')->where('status', 'active'))->orderBy('title')->limit(100)->get();
        $selected = null;
        $participants = collect();
        $group = null;
        if ($request->filled('workspace_id')) {
            $selected = CollaborationWorkspace::findOrFail($request->integer('workspace_id'));
            abort_unless($challenge->allow_cross_institution && NetworkAccess::member($user, $selected, true), 403);
            $participants = $selected->members()->where('status', 'active')->with('user', 'institution')->get();
        }
        if ($request->filled('group_id')) {
            $group = $groups->firstWhere('id', $request->integer('group_id'));
            abort_unless($group, 403);
            $participants = $group->members()->with('membership.user', 'membership.institution')->get();
        }

        return view('network.challenge-registration', compact('challenge', 'memberships', 'groups', 'workspaces', 'selected', 'participants', 'group'));
    }

    public function register(Request $request, InnovationChallenge $challenge): RedirectResponse
    {
        $d = $request->validate(['institution_id' => 'required|integer', 'group_id' => 'nullable|integer', 'collaboration_workspace_id' => 'nullable|integer', 'participant_ids' => 'nullable|array|max:20', 'participant_ids.*' => 'integer|distinct', 'motivation' => 'required|string|max:6000', 'skills' => 'nullable|string|max:3000', 'confirm' => 'accepted']);
        abort_if(! empty($d['group_id']) && ! empty($d['collaboration_workspace_id']), 422, 'Choose one team representation.');
        app(ChallengeWorkflow::class)->register($request->user(), $challenge, $d);

        return redirect()->route('network.challenges.show', $challenge)->with('status', 'Registration draft saved. Obtain participating membersâ€™ consent before confirmation.');
    }

    public function consent(Request $request, InnovationChallenge $challenge, OpportunityApplication $application): RedirectResponse
    {
        $d = $request->validate(['consent' => 'required|boolean', 'confirm' => 'accepted']);
        abort_unless($application->opportunity_id === $challenge->opportunity_id, 404);
        app(ChallengeWorkflow::class)->teamConsent($request->user(), $application, (bool) $d['consent']);

        return redirect()->route('network.challenges.show', $challenge)->with('status', 'Your current team registration consent was recorded.');
    }

    public function commit(Request $request, InnovationChallenge $challenge, OpportunityApplication $application): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted']);
        app(ChallengeWorkflow::class)->commitRegistration($request->user(), $challenge, $application);

        return redirect()->route('network.challenges.show', $challenge)->with('status', 'Challenge registration confirmed. Project submission remains a separate private step.');
    }

    public function submissionForm(Request $request, InnovationChallenge $challenge, OpportunityApplication $application): View
    {
        abort_unless($application->opportunity_id === $challenge->opportunity_id && $application->applicant_id === $request->user()->id && $application->status === 'submitted', 403);
        if ($application->collaboration_workspace_id) {
            abort_unless(NetworkAccess::member($request->user(), CollaborationWorkspace::findOrFail($application->collaboration_workspace_id), true), 403);
        } else {
            Gate::authorize('update', $application);
        }
        $projects = InnovationAccess::projects($request->user())->when($application->group_id, fn ($q, $id) => $q->where('group_id', $id))->orderBy('title')->limit(100)->get(['projects.id', 'projects.title']);
        if ($application->collaboration_workspace_id) {
            $pub = CollaborationWorkspace::findOrFail($application->collaboration_workspace_id)->publication;
            if (NetworkAccess::publicationVisible($request->user(), $pub) && ! $projects->contains('id', $pub->project_id)) {
                $projects->push(new Project(['id' => $pub->project_id, 'title' => $pub->approved_snapshot['title']]));
            }
        }

        return view('network.challenge-submission', compact('challenge', 'application', 'projects'));
    }

    public function submit(Request $request, InnovationChallenge $challenge, OpportunityApplication $application): RedirectResponse
    {
        $d = $request->validate(['project_id' => 'required|integer', 'title' => 'required|string|max:180', 'summary' => 'required|string|max:600', 'description' => 'required|string|max:12000', 'demo_url' => 'nullable|url:http,https|max:2048', 'file' => 'nullable|file|mimes:pdf,png,jpg,jpeg,txt|max:5120', 'confirm' => 'accepted']);
        $s = app(ChallengeWorkflow::class)->submit($request->user(), $challenge, $application, $d, $d['file'] ?? null);

        return redirect()->route('network.submissions.show', $s)->with('status', 'Private, versioned challenge submission received. This does not publish your project.');
    }

    public function submission(Request $request, ChallengeSubmission $submission): View
    {
        abort_unless(NetworkAccess::submission($request->user(), $submission), 403);
        $submission->load('challenge.opportunity', 'application');

        return view('network.submission-detail', compact('submission'));
    }

    public function file(Request $request, ChallengeSubmission $submission, int $version): BinaryFileResponse
    {
        abort_unless(NetworkAccess::submission($request->user(), $submission), 403);
        $row = DB::table('challenge_submission_versions')->where('challenge_submission_id', $submission->id)->where('version', $version)->first();
        abort_unless($row, 404);
        $snapshot = json_decode(Crypt::decryptString($row->snapshot), true, 512, JSON_THROW_ON_ERROR);
        $path = $snapshot['file_path'] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        NetworkSignals::record('challenge.file_downloaded', $submission, $submission->challenge->institution_id, ['version' => $version]);

        return response()->download(Storage::disk('local')->path($path), basename($snapshot['file_name'] ?? 'supporting-document'), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function withdraw(Request $request, InnovationChallenge $challenge, OpportunityApplication $application): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted']);
        app(ChallengeWorkflow::class)->withdraw($request->user(), $challenge, $application);

        return redirect()->route('network.challenges.show', $challenge)->with('status', 'Challenge registration and current submission withdrawn.');
    }

    public function judges(Request $request): View
    {
        $challenges = NetworkAccess::judgeChallenges($request->user())->with('opportunity')->orderByDesc('created_at')->paginate(15);
        $selected = null;
        $submissions = null;
        if ($request->filled('challenge_id')) {
            $selected = InnovationChallenge::findOrFail($request->integer('challenge_id'));
            Gate::authorize('judge', $selected);
            $submissions = $selected->submissions()->when($selected->status !== 'evaluation', fn ($q) => $q->whereRaw('1=0'))->where('status', 'submitted')->whereHas('application', fn ($q) => $q->where('status', 'submitted'))->with(['evaluations' => fn ($q) => $q->where('user_id', $request->user()->id)], 'project', 'application')->paginate(15, ['*'], 'submission_page');
        }

        return view('network.judges', compact('challenges', 'selected', 'submissions'));
    }

    public function assignJudge(Request $request, InnovationChallenge $challenge): RedirectResponse
    {
        $d = $request->validate(['email' => 'required|email', 'active' => 'required|boolean', 'confirm' => 'accepted']);
        $user = User::where('email', mb_strtolower(trim($d['email'])))->firstOrFail();
        app(ChallengeWorkflow::class)->assignJudge($request->user(), $challenge, $user, (bool) $d['active']);

        return redirect()->route('network.challenges.show', $challenge)->with('status', 'Judge assignment updated; independent conflicts still apply.');
    }

    public function evaluate(Request $request, ChallengeSubmission $submission): RedirectResponse
    {
        $d = $request->validate(['scores' => 'required|array|max:10', 'scores.*.score' => 'required|numeric|min:0|max:10', 'scores.*.comment' => 'nullable|string|max:3000', 'feedback' => 'required|string|max:6000', 'final' => 'required|boolean', 'no_conflict' => 'accepted', 'confirm' => 'accepted']);
        app(ChallengeWorkflow::class)->evaluate($request->user(), $submission, $d);

        return redirect()->route('network.judges', ['challenge_id' => $submission->innovation_challenge_id])->with('status', 'Evaluation recorded. Final evaluations are immutable.');
    }

    public function conflict(Request $request, ChallengeSubmission $submission): RedirectResponse
    {
        $d = $request->validate(['reason' => 'required|string|max:3000', 'confirm' => 'accepted']);
        app(ChallengeWorkflow::class)->declareConflict($request->user(), $submission, $d['reason']);

        return redirect()->route('network.judges', ['challenge_id' => $submission->innovation_challenge_id])->with('status', 'Conflict declared. Your evaluation is excluded.');
    }

    public function finalise(Request $request, InnovationChallenge $challenge): RedirectResponse
    {
        $d = $request->validate(['reason' => 'required|string|max:3000', 'confirm' => 'accepted']);
        app(ChallengeWorkflow::class)->finalise($request->user(), $challenge, $d['reason']);

        return redirect()->route('network.challenges.results', $challenge)->with('status', 'Results finalised privately. Explicit publication is still required.');
    }

    public function results(Request $request, InnovationChallenge $challenge): View
    {
        abort_unless(NetworkAccess::challenges($request->user())->whereKey($challenge->id)->exists(), 404);
        $manager = $request->user() && NetworkAccess::challengeManage($request->user(), $challenge);
        abort_unless(($challenge->status === 'results_published' && $challenge->results_state === 'published') || ($manager && $challenge->results_state === 'final'), 404);
        $rows = $challenge->results_snapshot['entries'] ?? [];
        $projectIds = ChallengeSubmission::whereIn('id', array_column($rows, 'submission_id'))->pluck('project_id', 'id');
        $publications = NetworkAccess::publications($request->user())->whereIn('project_id', $projectIds->values())->get(['id', 'identifier', 'title', 'project_id'])->keyBy('project_id');

        return view('network.challenge-results', compact('challenge', 'rows', 'projectIds', 'publications', 'manager'));
    }
}
