<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpportunityApplicationRequest;
use App\Models\ApplicationDocument;
use App\Models\Opportunity;
use App\Models\OpportunityApplication;
use App\Support\InnovationAccess;
use App\Support\InstitutionAccess;
use App\Support\OpportunityAccess;
use App\Support\OpportunityApplicationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OpportunityApplicationController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['status' => 'nullable|string|max:40', 'q' => 'nullable|string|max:180', 'opportunity_id' => 'nullable|integer', 'institution_id' => 'nullable|integer', 'project_id' => 'nullable|integer', 'review' => 'nullable|boolean']);
        $query = OpportunityAccess::applications($request->user())->with('opportunity.category', 'applicant', 'group', 'institution');
        if ($request->boolean('review')) {
            $query->whereIn('opportunity_id', OpportunityAccess::managed($request->user(), 'opportunities.review_applications')->select('opportunities.id'))->whereNotNull('submitted_at');
        } else {
            $query->where(fn ($q) => $q->where('applicant_id', $request->user()->id)->orWhereExists(fn ($c) => $c->selectRaw('1')->from('opportunity_consents')->whereColumn('application_id', 'opportunity_applications.id')->where('user_id', $request->user()->id)));
        }
        foreach (['status', 'opportunity_id', 'institution_id', 'project_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        $query->when($request->filled('q'), fn ($q) => $q->whereHas('opportunity', fn ($o) => $o->where('title', 'like', '%'.$request->string('q').'%')));
        $applications = $query->latest()->paginate(15)->withQueryString();

        return view('opportunities.applications.index', compact('applications'));
    }

    public function create(Request $request, Opportunity $opportunity): View
    {
        Gate::authorize('view', $opportunity);
        abort_unless($opportunity->application_method === 'internal' && $opportunity->isOpen(), 422);

        return $this->form($opportunity, new OpportunityApplication(['institution_id' => $request->integer('institution_id') ?: null]));
    }

    public function edit(OpportunityApplication $application): View
    {
        Gate::authorize('update', $application);
        abort_unless(in_array($application->status, ['draft', 'more_information_requested'], true), 422);

        return $this->form($application->opportunity, $application);
    }

    private function form(Opportunity $opportunity, OpportunityApplication $application): View
    {
        $memberships = InnovationAccess::memberships(auth()->user())->filter(fn ($m) => OpportunityAccess::eligible($opportunity, auth()->user(), $m->institution_id));
        $questions = DB::table('opportunity_questions')->where('opportunity_id', $opportunity->id)->orderBy('sort_order')->get();
        $answers = $application->exists ? DB::table('opportunity_responses')->where('application_id', $application->id)->pluck('answer', 'question_id')->all() : [];
        $groups = InnovationAccess::groups(auth()->user())->with('members.membership.user', 'institution')->where('status', 'active')->get()->filter(function ($g): bool {
            $m = InstitutionAccess::membership($g->institution);

            return $g->members->contains(fn ($member) => $member->membership_id === $m?->id && $member->role === 'leader');
        });
        $projects = InnovationAccess::projects(auth()->user())->whereNotIn('status', ['cancelled', 'archived'])->get();

        return view('opportunities.applications.form', compact('opportunity', 'application', 'memberships', 'questions', 'answers', 'groups', 'projects'));
    }

    public function store(OpportunityApplicationRequest $request, Opportunity $opportunity, OpportunityApplicationWorkflow $workflow): RedirectResponse
    {
        return redirect()->route('opportunity-applications.show', $workflow->save($opportunity, $request->validated()))->with('status', 'Application draft saved. Upload required documents, obtain group consent, then submit.');
    }

    public function update(OpportunityApplicationRequest $request, OpportunityApplication $application, OpportunityApplicationWorkflow $workflow): RedirectResponse
    {
        $workflow->save($application->opportunity, $request->validated(), $application);

        return redirect()->route('opportunity-applications.show', $application)->with('status', 'Draft updated. Changes require fresh group consent.');
    }

    public function show(OpportunityApplication $application): View
    {
        Gate::authorize('view', $application);
        $application->load('opportunity.category', 'applicant', 'institution', 'group', 'documents', 'reviews.reviewer');
        $questions = DB::table('opportunity_questions')->where('opportunity_id', $application->opportunity_id)->orderBy('sort_order')->get();
        $answers = DB::table('opportunity_responses')->where('application_id', $application->id)->pluck('answer', 'question_id');
        $consents = DB::table('opportunity_consents')->join('users', 'users.id', '=', 'user_id')->where('application_id', $application->id)->get(['users.id', 'users.name', 'consented_at']);
        $snapshots = DB::table('application_snapshots')->where('application_id', $application->id)->orderByDesc('version')->get()->map(fn ($snapshot) => ['version' => $snapshot->version, 'created_at' => $snapshot->created_at, 'payload' => json_decode(Crypt::decryptString($snapshot->payload), true)]);

        return view('opportunities.applications.show', compact('application', 'questions', 'answers', 'consents', 'snapshots'));
    }

    public function action(Request $request, OpportunityApplication $application, OpportunityApplicationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['action' => 'required|in:submit,withdraw,consent,review', 'consent' => 'nullable|boolean', 'confirm_share' => 'sometimes|accepted', 'status' => 'nullable|in:under_review,more_information_requested,shortlisted,accepted,rejected', 'feedback' => 'nullable|string|max:6000', 'internal_note' => 'nullable|string|max:6000']);
        switch ($data['action']) {
            case 'submit':$request->validate(['confirm_share' => 'accepted']);
                $workflow->submit($application);
                break;
            case 'withdraw':$workflow->withdraw($application);
                break;
            case 'consent':$request->validate(['consent' => 'required|boolean']);
                $workflow->consent($application, $request->boolean('consent'));
                break;
            case 'review':$request->validate(['status' => 'required', 'feedback' => 'required']);
                $workflow->review($application, $data['status'], $data['feedback'], $data['internal_note'] ?? null);
                break;
        }

        return back()->with('status', 'Application action recorded.');
    }

    public function upload(Request $request, OpportunityApplication $application, OpportunityApplicationWorkflow $workflow): RedirectResponse
    {
        $workflow->document($application, $request->validate(['file' => 'required|file|mimes:pdf,txt,csv,png,jpg,jpeg,webp,docx,xlsx,pptx|max:10240', 'question_id' => 'nullable|integer', 'document_basis' => 'accepted']));

        return back()->with('status', 'Private supporting document uploaded.');
    }

    public function download(OpportunityApplication $application, ApplicationDocument $document): StreamedResponse
    {
        Gate::authorize('view', $application);
        abort_unless($document->application_id === $application->id, 404);
        abort_unless(Storage::disk('local')->exists($document->path), 404);
        abort_if($document->version === 0 && $application->applicant_id !== auth()->id() && OpportunityAccess::reviews($application, auth()->user()), 403, 'Unsubmitted document revisions are private.');

        return Storage::disk('local')->download($document->path, $document->name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function external(Request $request, Opportunity $opportunity, OpportunityApplicationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['institution_id' => 'required|integer', 'confirm_external' => 'accepted']);

        return redirect()->route('opportunity-applications.show', $workflow->external($opportunity, $data['institution_id']))->with('status', 'Saved as self-reported external application. Ideyra has not submitted or verified an external application.');
    }

    public function export(Request $request, Opportunity $opportunity): StreamedResponse
    {
        abort_unless(OpportunityAccess::manages($opportunity, $request->user(), 'opportunities.review_applications'), 403);
        $request->validate(['institution_id' => 'nullable|integer']);
        $query = OpportunityAccess::applications($request->user())->where('opportunity_id', $opportunity->id)->where('status', '!=', 'draft')->with('applicant', 'group')->when($request->filled('institution_id'), fn ($q) => $q->where('institution_id', $request->integer('institution_id')));

        return response()->streamDownload(function () use ($query): void {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Reference', 'Applicant', 'Group', 'Institution ID', 'Status', 'Method', 'Submitted'], ',', '"', '');
            foreach ($query->cursor() as $a) {
                $row = [$a->identifier, $a->applicant->name, $a->group?->name ?? '', $a->institution_id, $a->status, $a->external_self_reported ? 'Self-reported external' : 'Internal', $a->submitted_at?->toIso8601String() ?? ''];
                fputcsv($file, array_map(fn ($v) => is_string($v) && preg_match('/^[\\s]*[=+@-]/u', $v) ? "'".$v : $v, $row), ',', '"', '');
            }fclose($file);
        }, 'ideyra-opportunity-applications.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
