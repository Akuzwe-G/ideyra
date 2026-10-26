<?php

namespace App\Http\Controllers;

use App\Models\ContentReport;
use App\Models\InnovationChallenge;
use App\Models\InnovationEvent;
use App\Models\NetworkRecognition;
use App\Models\User;
use App\Support\InnovationAccess;
use App\Support\ModerationWorkflow;
use App\Support\NetworkAccess;
use App\Support\RecognitionWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NetworkModerationController extends Controller
{
    public function index(Request $request): View
    {
        $ids = InnovationAccess::memberships($request->user())->filter(fn ($m) => NetworkAccess::can($request->user(), $m->institution, 'moderation.review'))->pluck('institution_id');
        abort_unless(NetworkAccess::platform($request->user()) || $ids->isNotEmpty(), 403);
        $request->validate(['status' => 'nullable|in:open,actioned,dismissed']);
        $reports = ContentReport::when(! NetworkAccess::platform($request->user()), fn ($q) => $q->whereIn('institution_id', $ids))->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))->with('institution')->orderByDesc('id')->paginate(20)->withQueryString();
        $reportedContent = [];
        foreach ($reports as $report) {
            $target = app(ModerationWorkflow::class)->target($report->target_type, $report->target_id);
            $reportedContent[$report->id] = match ($report->target_type) {
                'publication' => ['title' => $target->title, 'body' => $target->draft['description'] ?? $target->summary],
                'request' => ['title' => 'Collaboration proposal', 'body' => $target->motivation."\n".$target->contribution],
                'entry' => ['title' => $target->title, 'body' => $target->body],
            };
        }

        return view('network.moderation', compact('reports', 'reportedContent'));
    }

    public function report(Request $request): RedirectResponse
    {
        $d = $request->validate(['target_type' => 'required|in:publication,request,entry', 'target_id' => 'required|integer', 'reason' => 'required|string|max:3000', 'confirm' => 'accepted']);
        app(ModerationWorkflow::class)->report($request->user(), $d['target_type'], (int) $d['target_id'], $d['reason']);

        return back()->with('status', 'Your private report was submitted to authorised moderators.');
    }

    public function decide(Request $request, ContentReport $report): RedirectResponse
    {
        $d = $request->validate(['action' => 'required|in:remove,dismiss', 'reason' => 'required|string|max:3000', 'block_days' => 'nullable|integer|min:0|max:365', 'confirm' => 'accepted']);
        app(ModerationWorkflow::class)->decide($request->user(), $report, $d['action'], $d['reason'], (int) ($d['block_days'] ?? 0));

        return redirect()->route('network.moderation')->with('status', 'Moderation decision recorded.');
    }

    public function recognitions(Request $request): View
    {
        $memberships = InnovationAccess::memberships($request->user())->filter(fn ($m) => NetworkAccess::can($request->user(), $m->institution, 'network.achievements_confirm'));
        $recognitions = NetworkRecognition::where(fn ($q) => $q->where('user_id', $request->user()->id)->orWhereIn('institution_id', $memberships->pluck('institution_id')))->with('user', 'institution', 'verification')->orderByDesc('id')->paginate(20);
        $request->validate(['source' => ['nullable', 'string', 'regex:/^(challenge|event|collaboration):[0-9]+$/'], 'source_q' => 'nullable|string|max:120', 'participant_q' => 'nullable|string|max:120']);
        $sources = collect();
        $challengeIds = $memberships->filter(fn ($m) => NetworkAccess::can($request->user(), $m->institution, 'challenges.manage'))->pluck('institution_id');
        foreach (InnovationChallenge::whereIn('institution_id', $challengeIds)->where('status', 'results_published')->when($request->filled('source_q'), fn ($q) => $q->whereHas('opportunity', fn ($o) => $o->where('title', 'like', '%'.$request->input('source_q').'%')))->with('opportunity')->latest()->limit(100)->get() as $challenge) {
            $sources->push(['key' => 'challenge:'.$challenge->id, 'label' => 'Challenge · '.$challenge->opportunity->title, 'record' => $challenge, 'type' => 'challenge']);
        }
        $eventIds = $memberships->filter(fn ($m) => NetworkAccess::can($request->user(), $m->institution, 'events.manage'))->pluck('institution_id');
        foreach (InnovationEvent::whereIn('institution_id', $eventIds)->where('status', 'completed')->when($request->filled('source_q'), fn ($q) => $q->where('title', 'like', '%'.$request->input('source_q').'%'))->latest()->limit(100)->get() as $event) {
            $sources->push(['key' => 'event:'.$event->id, 'label' => 'Event · '.$event->title, 'record' => $event, 'type' => 'event']);
        }
        foreach (NetworkAccess::workspaces($request->user())->where('status', 'closed')->when($request->filled('source_q'), fn ($q) => $q->where('title', 'like', '%'.$request->input('source_q').'%'))->whereHas('members', fn ($q) => $q->where('user_id', $request->user()->id)->where('role', 'admin')->where('status', 'active'))->latest()->limit(100)->get() as $workspace) {
            if (array_intersect($workspace->institution_ids, $memberships->pluck('institution_id')->all())) {
                $sources->push(['key' => 'collaboration:'.$workspace->id, 'label' => 'Collaboration · '.$workspace->title, 'record' => $workspace, 'type' => 'collaboration']);
            }
        }
        $selectedSource = $request->filled('source') ? $sources->firstWhere('key', $request->input('source')) : null;
        abort_if($request->filled('source') && ! $selectedSource, 403);
        $eligiblePeople = collect();
        if ($selectedSource) {
            $source = $selectedSource['record'];
            $ids = match ($selectedSource['type']) {
                'event' => $source->registrations()->where('status', 'attended')->select('user_id'),
                'challenge' => DB::table('application_participants')->whereIn('application_id', $source->submissions()->whereIn('id', array_column($source->results_snapshot['entries'] ?? [], 'submission_id'))->select('opportunity_application_id'))->select('user_id'),
                'collaboration' => $source->entries()->whereIn('kind', ['task', 'milestone'])->where('status', 'completed')->whereNull('removed_at')->whereIn('assigned_to', $source->members()->where('status', 'active')->select('user_id'))->select('assigned_to'),
            };
            $eligiblePeople = User::whereIn('id', $ids)->where('id', '!=', $request->user()->id)->where('status', 'active')->when($request->filled('participant_q'), fn ($q) => $q->where('name', 'like', '%'.$request->input('participant_q').'%'))->orderBy('name')->limit(200)->get(['id', 'name']);
        }

        return view('network.recognitions', compact('recognitions', 'memberships', 'sources', 'selectedSource', 'eligiblePeople'));
    }

    public function confirmRecognition(Request $request): RedirectResponse
    {
        $d = $request->validate(['institution_id' => 'required|integer', 'user_id' => 'required|integer', 'source_type' => 'required|in:challenge,event,collaboration', 'source_id' => 'required|integer', 'title' => 'required|string|max:180', 'evidence' => 'required|string|max:6000', 'confirm' => 'accepted']);
        app(RecognitionWorkflow::class)->confirm($request->user(), $d);

        return redirect()->route('network.recognitions')->with('status', 'Achievement independently confirmed. Public portfolio use remains opt-in.');
    }

    public function recognitionAction(Request $request, NetworkRecognition $recognition): RedirectResponse
    {
        $d = $request->validate(['action' => 'required|in:visibility,revoke', 'public_allowed' => 'nullable|boolean', 'reason' => 'required_if:action,revoke|nullable|string|max:3000', 'confirm' => 'accepted']);
        $w = app(RecognitionWorkflow::class);
        if ($d['action'] === 'visibility') {
            $w->visibility($request->user(), $recognition, (bool) ($d['public_allowed'] ?? false));
        } else {
            $w->revoke($request->user(), $recognition, $d['reason']);
        }

        return redirect()->route('network.recognitions')->with('status', 'Recognition preference or decision recorded.');
    }
}
