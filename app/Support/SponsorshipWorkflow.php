<?php

namespace App\Support;

use App\Models\Project;
use App\Models\SponsorContact;
use App\Models\SponsorshipCommitment;
use App\Models\SponsorshipRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SponsorshipWorkflow
{
    public function save(array $data, ?SponsorshipRequest $request = null): SponsorshipRequest
    {
        $project = Project::findOrFail($data['project_id']);
        Gate::authorize('update', $project);
        ProjectWorkflow::writable($project);
        abort_unless(! $project->institution_id || InnovationAccess::can($project->institution, 'sponsorships.request', auth()->user()), 403);
        if ($request) {
            Gate::authorize('update', $request);
            abort_unless($request->project_id === $project->id && in_array($request->status, ['draft', 'returned'], true), 422, 'Only drafts or returned requests may be edited.');
        }
        $request ??= new SponsorshipRequest(['identifier' => (string) Str::ulid(), 'requested_by' => auth()->id(), 'project_id' => $project->id, 'status' => 'draft']);
        $request->fill(collect($data)->except('project_id')->all());
        $request->save();

        return $request;
    }

    public function transition(SponsorshipRequest $request, string $status, string $reason): void
    {
        if (in_array($status, ['approved_outreach', 'returned'], true)) {
            Gate::authorize('review', $request);
        } else {
            Gate::authorize('update', $request);
        }
        DB::transaction(function () use ($request, $status, $reason): void {
            $request = SponsorshipRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $allowed = ['draft' => ['pending_review', 'cancelled'], 'returned' => ['pending_review', 'cancelled'], 'pending_review' => ['approved_outreach', 'returned', 'cancelled'], 'approved_outreach' => ['closed', 'cancelled'], 'closed' => [], 'cancelled' => []];
            abort_unless(in_array($status, $allowed[$request->status] ?? [], true), 422);
            $request->update(['status' => $status]);
            DB::table('sponsorship_decisions')->insert(['sponsorship_request_id' => $request->id, 'actor_id' => auth()->id(), 'decision' => $status, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('sponsorship.decision', $request, $request->project->institution_id, ['status' => $status]);
            OpportunitySignals::sponsorship($request);
        });
    }

    public function contact(SponsorshipRequest $request, array $data): void
    {
        Gate::authorize('manage', $request);
        abort_unless($request->status === 'approved_outreach', 422);
        $request->contacts()->create(collect($data)->only(['name', 'email', 'phone', 'type'])->all() + ['created_by' => auth()->id()]);
        Audit::record('sponsorship.contact_recorded', $request, $request->project->institution_id);
    }

    public function verifyContact(SponsorshipRequest $request, SponsorContact $contact, string $evidence): void
    {
        Gate::authorize('manage', $request);
        abort_unless($contact->sponsorship_request_id === $request->id, 404);
        $contact->update(['verified_by' => auth()->id(), 'verified_at' => now(), 'verification_evidence' => $evidence]);
        Audit::record('sponsorship.contact_verified', $request, $request->project->institution_id);
    }

    public function commitment(SponsorshipRequest $request, array $data): void
    {
        Gate::authorize('manage', $request);
        abort_unless(OpportunityAccess::staff($request->project, auth()->user(), 'sponsorships.record_commitment') && $request->status === 'approved_outreach', 403);
        $contact = $request->contacts()->findOrFail($data['sponsor_contact_id']);
        DB::transaction(function () use ($request, $data): void {
            $commitment = $request->commitments()->create($data + ['created_by' => auth()->id(), 'status' => 'proposed']);
            DB::table('sponsorship_commitment_events')->insert(['sponsorship_commitment_id' => $commitment->id, 'actor_id' => auth()->id(), 'status' => 'proposed', 'reason' => 'Initial non-payment support proposal.', 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('sponsorship.commitment_recorded', $request, $request->project->institution_id, ['commitment_id' => $commitment->id]);
            OpportunitySignals::sponsorship($request);
        });
    }

    public function commitmentStatus(SponsorshipRequest $request, SponsorshipCommitment $commitment, string $status, string $reason, ?string $evidence): void
    {
        Gate::authorize('manage', $request);
        abort_unless($commitment->sponsorship_request_id === $request->id, 404);
        abort_unless(OpportunityAccess::staff($request->project, auth()->user(), $status === 'fulfilled' ? 'sponsorships.verify_fulfilment' : 'sponsorships.record_commitment'), 403);
        DB::transaction(function () use ($request, $commitment, $status, $reason, $evidence): void {
            $commitment = SponsorshipCommitment::whereKey($commitment->id)->lockForUpdate()->firstOrFail();
            $allowed = ['proposed' => ['under_discussion', 'cancelled'], 'under_discussion' => ['committed', 'cancelled'], 'committed' => ['fulfilled', 'cancelled'], 'fulfilled' => [], 'cancelled' => []];
            abort_unless(in_array($status, $allowed[$commitment->status] ?? [], true), 422);
            if ($status === 'fulfilled') {
                abort_unless($evidence && $commitment->contact->verified_at, 422, 'Verify the sponsor contact and record fulfilment evidence first.');
            }
            $commitment->update(['status' => $status, 'fulfilled_at' => $status === 'fulfilled' ? now() : null, 'verified_by' => $status === 'fulfilled' ? auth()->id() : null, 'fulfilment_evidence' => $status === 'fulfilled' ? $evidence : null]);
            DB::table('sponsorship_commitment_events')->insert(['sponsorship_commitment_id' => $commitment->id, 'actor_id' => auth()->id(), 'status' => $status, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('sponsorship.commitment_status_changed', $request, $request->project->institution_id, ['commitment_id' => $commitment->id, 'status' => $status]);
            OpportunitySignals::sponsorship($request);
        });
    }
}
