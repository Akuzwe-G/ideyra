<?php

namespace App\Http\Controllers;

use App\Http\Requests\SponsorshipRequestForm;
use App\Models\SponsorContact;
use App\Models\SponsorshipCommitment;
use App\Models\SponsorshipRequest;
use App\Support\InnovationAccess;
use App\Support\OpportunityAccess;
use App\Support\SponsorshipWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SponsorshipController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['q' => 'nullable|string|max:180', 'status' => 'nullable|string|max:40', 'project_id' => 'nullable|integer']);
        $sponsorships = OpportunityAccess::sponsorships($request->user())->with('project.institution', 'requester')->when($request->filled('q'), fn ($q) => $q->whereHas('project', fn ($p) => $p->where('title', 'like', '%'.$request->string('q').'%')))->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))->latest()->paginate(15)->withQueryString();

        return view('sponsorships.index', compact('sponsorships'));
    }

    public function create(Request $request): View
    {
        return $this->form(new SponsorshipRequest(['project_id' => $request->integer('project_id') ?: null]));
    }

    public function edit(SponsorshipRequest $sponsorship): View
    {
        Gate::authorize('update', $sponsorship);

        return $this->form($sponsorship);
    }

    private function form(SponsorshipRequest $sponsorship): View
    {
        $projects = InnovationAccess::projects(auth()->user())->with('institution')->whereNotIn('status', ['completed', 'cancelled', 'archived'])->get()->filter(fn ($p) => InnovationAccess::projectManage($p, auth()->user()) && (! $p->institution_id || InnovationAccess::can($p->institution, 'sponsorships.request', auth()->user())));

        return view('sponsorships.form', compact('sponsorship', 'projects'));
    }

    public function store(SponsorshipRequestForm $request, SponsorshipWorkflow $workflow): RedirectResponse
    {
        return redirect()->route('sponsorships.show', $workflow->save($request->validated()))->with('status', 'Support request draft saved.');
    }

    public function update(SponsorshipRequestForm $request, SponsorshipRequest $sponsorship, SponsorshipWorkflow $workflow): RedirectResponse
    {
        $workflow->save($request->validated(), $sponsorship);

        return redirect()->route('sponsorships.show', $sponsorship)->with('status', 'Support request updated.');
    }

    public function show(SponsorshipRequest $sponsorship): View
    {
        Gate::authorize('view', $sponsorship);
        $sponsorship->load('project.institution', 'requester');
        $manage = Gate::allows('manage', $sponsorship);
        $contacts = $manage ? $sponsorship->contacts()->latest()->paginate(10, ['*'], 'contacts_page') : collect();
        $commitments = $sponsorship->commitments()->when($manage, fn ($q) => $q->with('contact'))->latest()->paginate(10);
        $decisions = DB::table('sponsorship_decisions')->where('sponsorship_request_id', $sponsorship->id)->latest('id')->get();
        $events = $manage ? DB::table('sponsorship_commitment_events')->whereIn('sponsorship_commitment_id', $sponsorship->commitments()->select('id'))->latest('id')->get() : collect();

        return view('sponsorships.show', compact('sponsorship', 'manage', 'contacts', 'commitments', 'decisions', 'events'));
    }

    public function action(Request $request, SponsorshipRequest $sponsorship, SponsorshipWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:pending_review,approved_outreach,returned,closed,cancelled', 'reason' => 'required|string|max:6000']);
        $workflow->transition($sponsorship, $data['status'], $data['reason']);

        return back()->with('status', 'Sponsorship decision recorded.');
    }

    public function contact(Request $request, SponsorshipRequest $sponsorship, SponsorshipWorkflow $workflow): RedirectResponse
    {
        $workflow->contact($sponsorship, $request->validate(['name' => 'required|string|max:180', 'email' => 'nullable|email|max:254', 'phone' => 'nullable|string|max:40', 'type' => 'required|in:organisation,individual', 'contact_basis' => 'accepted']));

        return back()->with('status', 'Private sponsor contact recorded.');
    }

    public function verify(Request $request, SponsorshipRequest $sponsorship, SponsorContact $contact, SponsorshipWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['evidence' => 'required|string|max:6000']);
        $workflow->verifyContact($sponsorship, $contact, $data['evidence']);

        return back()->with('status', 'Sponsor verification recorded.');
    }

    public function commitment(Request $request, SponsorshipRequest $sponsorship, SponsorshipWorkflow $workflow): RedirectResponse
    {
        $workflow->commitment($sponsorship, $request->validate(['sponsor_contact_id' => 'required|integer', 'offered_support' => 'required|string|max:6000', 'pledged_value' => 'nullable|numeric|min:0|max:999999999999.99', 'currency' => 'required_with:pledged_value|nullable|string|size:3|regex:/^[A-Z]{3}$/']));

        return back()->with('status', 'Non-payment commitment proposal recorded.');
    }

    public function commitmentStatus(Request $request, SponsorshipRequest $sponsorship, SponsorshipCommitment $commitment, SponsorshipWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:under_discussion,committed,fulfilled,cancelled', 'reason' => 'required|string|max:6000', 'evidence' => 'nullable|string|max:6000']);
        $workflow->commitmentStatus($sponsorship, $commitment, $data['status'], $data['reason'], $data['evidence'] ?? null);

        return back()->with('status', 'Commitment status updated. A pledge is not money received.');
    }
}
