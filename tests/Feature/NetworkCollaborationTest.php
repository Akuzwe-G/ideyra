<?php

namespace Tests\Feature;

use App\Models\CollaborationRequest;
use App\Models\CollaborationWorkspace;
use App\Models\InnovationChallenge;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\NetworkPublication;
use App\Models\NetworkSetting;
use App\Models\Project;
use App\Models\Role;
use App\Support\ChallengeWorkflow;
use App\Support\CollaborationWorkflow;
use App\Support\InnovationAccess;
use App\Support\NetworkAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NetworkCollaborationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function member(Institution $i, string $role = 'Student'): Membership
    {
        return Membership::factory()->create(['institution_id' => $i->id, 'role_id' => Role::where('name', $role)->sole()->id]);
    }

    private function fixture(bool $approval = true): array
    {
        $a = Institution::factory()->create();
        $b = Institution::factory()->create();
        foreach ([$a, $b] as $i) {
            NetworkSetting::factory()->create(['institution_id' => $i->id, 'allow_network' => true, 'require_collaboration_approval' => $approval]);
        }
        $owner = $this->member($a);
        $requester = $this->member($b);
        $reviewA = $this->member($a, 'Institution Administrator');
        $reviewB = $this->member($b, 'Institution Administrator');
        $source = Project::factory()->create(['institution_id' => $a->id, 'owner_id' => $owner->user_id]);
        $publication = NetworkPublication::factory()->create(['project_id' => $source->id, 'institution_id' => $a->id, 'submitted_by' => $owner->user_id, 'approved_by' => $reviewA->user_id, 'status' => 'published', 'visibility' => 'network', 'approved_version' => 1, 'approved_snapshot' => ['title' => 'Approved network project', 'collaboration_open' => true], 'collaboration_open' => true, 'published_at' => now()]);

        return compact('a', 'b', 'owner', 'requester', 'reviewA', 'reviewB', 'source', 'publication');
    }

    private function request(array $f): CollaborationRequest
    {
        $this->actingAs($f['requester']->user);

        return app(CollaborationWorkflow::class)->save($f['requester']->user, $f['publication'], ['institution_id' => $f['b']->id, 'type' => 'technical', 'motivation' => 'Work on a prototype', 'skills' => 'Programming', 'contribution' => 'Explicit shared tasks only']);
    }

    public function test_both_institutions_approve_before_creating_an_isolated_workspace(): void
    {
        $f = $this->fixture();
        $w = app(CollaborationWorkflow::class);
        $r = $this->request($f);
        $w->action($f['requester']->user, $r, 'submit', 'Ready');
        $this->actingAs($f['owner']->user);
        $w->action($f['owner']->user, $r->fresh(), 'accept', 'Accept pending institution approval');
        $this->assertSame(0, CollaborationWorkspace::count());
        $this->actingAs($f['reviewA']->user);
        $w->approve($f['reviewA']->user, $r->fresh(), $f['a']->id, true, 'Approved by source institution');
        $this->actingAs($f['reviewB']->user);
        $w->approve($f['reviewB']->user, $r->fresh(), $f['b']->id, true, 'Approved by requesting institution');
        $workspace = CollaborationWorkspace::sole();
        $this->assertSame('accepted', $r->fresh()->status);
        $this->get(route('network.requests.show', $r))->assertOk()->assertSee('Request decision history')->assertSee('Approved by requesting institution');
        $this->assertCount(2, $workspace->members);
        $this->assertNotNull(NetworkAccess::member($f['requester']->user, $workspace));
        $this->assertFalse(InnovationAccess::projects($f['requester']->user)->whereKey($f['source']->id)->exists());
        $this->assertSame(0, $f['source']->members()->where('user_id', $f['requester']->user_id)->count());
    }

    public function test_duplicate_active_requests_are_prevented_and_declined_requests_are_private(): void
    {
        $f = $this->fixture(false);
        $w = app(CollaborationWorkflow::class);
        $r = $this->request($f);
        try {
            $this->request($f);
            $this->fail('Duplicate active request accepted.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $w->action($f['requester']->user, $r, 'submit', 'Ready');
        $this->actingAs($f['owner']->user);
        $w->action($f['owner']->user, $r->fresh(), 'decline', 'Not a fit');
        $outsider = $this->member($f['b']);
        $this->assertFalse(NetworkAccess::requestView($outsider->user, $r->fresh()));
        $this->assertSame('declined', $r->fresh()->status);
        $this->assertSame(0, CollaborationWorkspace::count());
    }

    public function test_cross_institution_challenge_team_uses_existing_application_with_each_members_consent(): void
    {
        $f = $this->fixture(false);
        $workflow = app(CollaborationWorkflow::class);
        $r = $this->request($f);
        $workflow->action($f['requester']->user, $r, 'submit', 'Ready');
        $this->actingAs($f['owner']->user);
        $workflow->action($f['owner']->user, $r->fresh(), 'accept', 'Accept');
        $workspace = CollaborationWorkspace::sole();
        $challenge = InnovationChallenge::factory()->create(['institution_id' => $f['a']->id, 'status' => 'published', 'visibility' => 'network', 'allow_cross_institution' => true]);
        $challenge->opportunity->update(['institution_id' => $f['a']->id, 'status' => 'published', 'opens_at' => now()->subDay(), 'deadline_at' => now()->addWeek()]);
        $w = app(ChallengeWorkflow::class);
        $this->actingAs($f['requester']->user);
        $application = $w->register($f['requester']->user, $challenge, ['institution_id' => $f['b']->id, 'collaboration_workspace_id' => $workspace->id, 'participant_ids' => [$f['requester']->user_id, $f['owner']->user_id], 'motivation' => 'Shared prototype']);
        try {
            $w->commitRegistration($f['requester']->user, $challenge, $application);
            $this->fail('Missing team consent accepted.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->actingAs($f['owner']->user);
        $w->teamConsent($f['owner']->user, $application, true);
        $this->actingAs($f['requester']->user);
        $w->commitRegistration($f['requester']->user, $challenge, $application);
        $this->assertSame('submitted', $application->fresh()->status);
        $this->assertDatabaseHas('application_participants', ['application_id' => $application->id, 'user_id' => $f['owner']->user_id, 'institution_id' => $f['a']->id]);
        $this->assertDatabaseHas('application_participants', ['application_id' => $application->id, 'user_id' => $f['requester']->user_id, 'institution_id' => $f['b']->id]);
        $submission = $w->submit($f['requester']->user, $challenge, $application->fresh(), ['project_id' => $f['source']->id, 'title' => 'Explicit shared entry', 'summary' => 'Approved linked projection', 'description' => 'Only team-submitted content.']);
        $this->assertSame(1, $submission->version);
        $this->assertFalse(InnovationAccess::projects($f['requester']->user)->whereKey($f['source']->id)->exists());
        $this->get(route('network.challenges.show', $challenge))->assertOk()->assertSee('Your registration');
        $this->get(route('network.challenges.submission', [$challenge, $application]))->assertOk();
        $this->get(route('opportunity-applications.show', $application))->assertNotFound();
    }

    public function test_workspace_invitation_requires_acceptance_and_revocation_removes_access(): void
    {
        $f = $this->fixture(false);
        $w = app(CollaborationWorkflow::class);
        $r = $this->request($f);
        $w->action($f['requester']->user, $r, 'submit', 'Ready');
        $this->actingAs($f['owner']->user);
        $w->action($f['owner']->user, $r->fresh(), 'accept', 'Accept');
        $workspace = CollaborationWorkspace::sole();
        $peer = $this->member($f['b']);
        try {
            $w->invite($f['owner']->user, $workspace, $peer->user->email, $f['b']->id, 'admin');
            $this->fail('Institutional admin delegation permission was bypassed.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $w->invite($f['owner']->user, $workspace, $peer->user->email, $f['b']->id, 'member');
        $this->assertNull(NetworkAccess::member($peer->user, $workspace));
        $this->actingAs($peer->user);
        $w->acceptInvitation($peer->user, $workspace, true);
        $this->assertNotNull(NetworkAccess::member($peer->user, $workspace));
        $entry = $w->entry($peer->user, $workspace, ['kind' => 'thread', 'title' => 'Shared research', 'body' => 'Explicitly shared discussion only']);
        $this->assertSame($workspace->id, $entry->collaboration_workspace_id);
        $this->actingAs($f['owner']->user);
        $w->membership($f['owner']->user, $workspace, $workspace->members()->where('user_id', $peer->user_id)->sole(), 'remove');
        $this->assertNull(NetworkAccess::member($peer->user, $workspace));
    }
}
