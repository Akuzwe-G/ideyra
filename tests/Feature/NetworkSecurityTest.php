<?php

namespace Tests\Feature;

use App\Models\CollaborationFile;
use App\Models\CollaborationMember;
use App\Models\CollaborationRequest;
use App\Models\CollaborationWorkspace;
use App\Models\EventRegistration;
use App\Models\InnovationChallenge;
use App\Models\InnovationEvent;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\NetworkPublication;
use App\Models\NetworkSetting;
use App\Models\Project;
use App\Models\PublicationMedia;
use App\Models\Role;
use App\Support\EventWorkflow;
use App\Support\ModerationWorkflow;
use App\Support\NetworkAccess;
use App\Support\NetworkSignals;
use App\Support\RecognitionWorkflow;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NetworkSecurityTest extends TestCase
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

    private function publication(Membership $m, string $visibility = 'public'): NetworkPublication
    {
        NetworkSetting::updateOrCreate(['institution_id' => $m->institution_id], ['allow_public' => true, 'allow_network' => true]);
        $project = Project::factory()->create(['institution_id' => $m->institution_id, 'owner_id' => $m->user_id, 'description' => 'SOURCE SECRET MUST NOT ESCAPE']);
        $snapshot = ['title' => 'Approved prototype', 'summary' => 'Approved summary', 'description' => 'Approved overview', 'problem' => 'Problem', 'solution' => 'Solution', 'objectives' => 'Objectives', 'category' => 'Research', 'technologies' => ['PHP'], 'demo_links' => [], 'interests' => ['technical'], 'declared_owner' => 'Declared student owner', 'team' => [], 'media_ids' => [], 'institution' => null, 'collaboration_open' => true, 'visibility' => $visibility, 'project_status' => 'in_progress'];

        return NetworkPublication::factory()->create(['institution_id' => $m->institution_id, 'project_id' => $project->id, 'submitted_by' => $m->user_id, 'status' => 'published', 'visibility' => $visibility, 'title' => $snapshot['title'], 'summary' => $snapshot['summary'], 'draft' => $snapshot, 'approved_snapshot' => $snapshot, 'approved_version' => 1, 'published_at' => now(), 'collaboration_open' => true]);
    }

    public function test_guest_routes_render_approved_projections_only_and_revoked_media_stop_immediately(): void
    {
        Storage::fake('local');
        $m = $this->member(Institution::factory()->create());
        $p = $this->publication($m);
        $media = PublicationMedia::factory()->create(['network_publication_id' => $p->id, 'approved' => true]);
        Storage::disk('local')->put($media->path, 'image-test');
        $snapshot = $p->approved_snapshot;
        $snapshot['media_ids'] = [$media->id];
        $p->update(['approved_snapshot' => $snapshot]);
        $this->get(route('network.showcase'))->assertOk()->assertSee('Approved prototype')->assertDontSee('SOURCE SECRET');
        $this->get(route('network.showcase.show', $p->identifier))->assertOk()->assertDontSee('SOURCE SECRET')->assertDontSee($m->user->email);
        $this->get(route('network.showcase.media', [$p->identifier, $media]))->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $p->update(['status' => 'unpublished']);
        $this->get(route('network.showcase.show', $p->identifier))->assertNotFound();
        $this->get(route('network.showcase.media', [$p->identifier, $media]))->assertNotFound();
        $this->get(route('network.challenges'))->assertOk();
        $this->get(route('network.events'))->assertOk();
        $this->post(route('network.publications.action', $p), ['status' => 'published'])->assertRedirect(route('login'));
    }

    public function test_all_authorised_editors_and_empty_lists_render(): void
    {
        $i = Institution::factory()->create();
        $admin = $this->member($i, 'Institution Administrator');
        $student = $this->member($i);
        $p = $this->publication($student, 'institution');
        $p->update(['status' => 'draft']);
        $this->actingAs($student->user);
        foreach (['network.publications.mine', 'network.publications.create', 'network.requests', 'network.workspaces', 'network.challenges', 'network.events', 'network.judges', 'network.recognitions'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('network.publications.edit', $p))->assertOk();
        $this->actingAs($admin->user);
        foreach (['network.publications.reviews', 'network.challenges.create', 'network.events.create', 'network.recognitions', 'network.moderation'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('network.publications.review', $p))->assertOk();
        $this->get(route('network.policy', $i))->assertOk();
    }

    public function test_institutional_challenge_event_pages_and_registration_are_scoped(): void
    {
        $i = Institution::factory()->create();
        $student = $this->member($i);
        $admin = $this->member($i, 'Institution Administrator');
        $stranger = $this->member(Institution::factory()->create());
        $c = InnovationChallenge::factory()->create(['institution_id' => $i->id]);
        $c->opportunity->update(['institution_id' => $i->id, 'status' => 'published', 'opens_at' => now()->subDay(), 'deadline_at' => now()->addWeek()]);
        $c->update(['status' => 'published']);
        $e = InnovationEvent::factory()->create(['institution_id' => $i->id, 'status' => 'published']);
        $e->opportunity->update(['institution_id' => $i->id, 'status' => 'published']);
        $this->actingAs($student->user);
        $this->get(route('network.challenges.show', $c))->assertOk();
        $this->get(route('network.challenges.registration', $c))->assertOk();
        $this->get(route('network.events.show', $e))->assertOk();
        $this->get(route('opportunities.show', $c->opportunity))->assertRedirect(route('network.challenges.show', $c));
        $this->get(route('opportunities.show', $e->opportunity))->assertRedirect(route('network.events.show', $e));
        $this->actingAs($admin->user);
        $this->get(route('network.challenges.edit', $c))->assertOk();
        $this->get(route('network.events.edit', $e))->assertOk();
        $this->get(route('network.events.show', $e))->assertOk();
        $this->actingAs($stranger->user);
        $this->get(route('network.challenges.show', $c))->assertNotFound();
        $this->get(route('network.events.show', $e))->assertNotFound();
        $this->get(route('network.publications.reviews'))->assertOk()->assertDontSee('Approved prototype');
    }

    public function test_workspace_access_and_downloads_revoke_with_membership_or_partner_policy(): void
    {
        Storage::fake('local');
        $a = $this->member(Institution::factory()->create());
        $b = $this->member(Institution::factory()->create());
        $p = $this->publication($a, 'network');
        NetworkSetting::factory()->create(['institution_id' => $b->institution_id, 'allow_network' => true]);
        $r = CollaborationRequest::factory()->create(['network_publication_id' => $p->id, 'user_id' => $b->user_id, 'institution_id' => $b->institution_id, 'representative_id' => $a->user_id, 'status' => 'accepted']);
        $w = CollaborationWorkspace::factory()->create(['collaboration_request_id' => $r->id, 'network_publication_id' => $p->id, 'institution_id' => $a->institution_id, 'institution_ids' => [$a->institution_id, $b->institution_id]]);
        $member = CollaborationMember::factory()->create(['collaboration_workspace_id' => $w->id, 'user_id' => $b->user_id, 'institution_id' => $b->institution_id, 'status' => 'active', 'role' => 'admin']);
        $file = CollaborationFile::factory()->create(['collaboration_workspace_id' => $w->id, 'user_id' => $a->user_id]);
        Storage::disk('local')->put($file->path, 'Shared only');
        $this->actingAs($b->user);
        foreach (['overview', 'members', 'discussions', 'tasks', 'milestones', 'files', 'updates', 'activity'] as $tab) {
            $this->get(route('network.workspaces.show', [$w, 'tab' => $tab]))->assertOk()->assertDontSee('SOURCE SECRET');
        }
        $this->get(route('network.workspaces.file', $file))->assertOk();
        $this->get(route('projects.show', $p->project))->assertNotFound();
        $a->institution->update(['status' => 'suspended']);
        $this->get(route('network.workspaces'))->assertOk()->assertDontSee($w->title);
        $this->get(route('network.workspaces.show', $w))->assertForbidden();
        $this->get(route('network.workspaces.file', $file))->assertForbidden();
        $a->institution->update(['status' => 'active']);
        $member->update(['status' => 'revoked']);
        $this->get(route('network.workspaces.file', $file))->assertForbidden();
    }

    public function test_moderation_is_independent_and_blocks_new_requests_without_exposing_reporter(): void
    {
        $i = Institution::factory()->create();
        $author = $this->member($i);
        $reporter = $this->member($i);
        $admin = $this->member($i, 'Institution Administrator');
        $p = $this->publication($author);
        $this->actingAs($reporter->user);
        $report = app(ModerationWorkflow::class)->report($reporter->user, 'publication', $p->id, 'CONFIDENTIAL REPORT REASON');
        $this->get(route('network.moderation'))->assertForbidden();
        $this->actingAs($admin->user);
        $this->get(route('network.moderation'))->assertOk()->assertSee('CONFIDENTIAL REPORT REASON')->assertDontSee($reporter->user->email);
        app(ModerationWorkflow::class)->decide($admin->user, $report, 'remove', 'Evidence reviewed', 7);
        $this->assertFalse(NetworkAccess::publicationVisible(null, $p->fresh()));
        $this->assertTrue(NetworkAccess::blocked($author->user, $i->id));
        $this->assertDatabaseHas('audit_entries', ['action' => 'network.moderation.remove']);
        $this->assertStringNotContainsString('Evidence reviewed', DB::table('network_blocks')->value('reason'));
    }

    public function test_notification_links_recheck_revoked_access_and_notifications_deduplicate(): void
    {
        $m = $this->member(Institution::factory()->create());
        $p = $this->publication($m);
        $this->actingAs($m->user);
        NetworkSignals::send([$m->user_id], 'network_publication', $p, 'test-event', 'Showcase update');
        NetworkSignals::send([$m->user_id], 'network_publication', $p, 'test-event', 'Showcase update');
        $this->assertSame(1, $m->user->notifications()->count());
        $notice = $m->user->notifications()->first();
        $m->update(['status' => 'suspended']);
        $p->update(['status' => 'unpublished']);
        $this->get(route('notifications.open', $notice->id))->assertRedirect(route('notifications.index'));
    }

    public function test_expiry_command_is_idempotent_and_creates_no_workspace(): void
    {
        $r = CollaborationRequest::factory()->create(['status' => 'submitted', 'active_key' => hash('sha256', 'test-expired'), 'expires_at' => now()->subMinute()]);
        $this->artisan('ideyra:network:expire')->assertSuccessful();
        $this->assertSame('expired', $r->fresh()->status);
        $this->assertNull($r->fresh()->active_key);
        $this->artisan('ideyra:network:expire')->assertSuccessful();
        $this->assertDatabaseCount('collaboration_workspaces', 0);
    }

    public function test_public_exhibition_features_only_approved_projections_and_removes_revoked_content(): void
    {
        $i = Institution::factory()->create();
        $owner = $this->member($i);
        $admin = $this->member($i, 'Institution Administrator');
        $p = $this->publication($owner);
        $event = InnovationEvent::factory()->create(['institution_id' => $i->id, 'status' => 'published', 'visibility' => 'public']);
        $event->opportunity->update(['institution_id' => $i->id, 'status' => 'published']);
        $this->actingAs($admin->user);
        app(EventWorkflow::class)->feature($admin->user, $event, ['network_publication_id' => $p->id, 'representative_id' => $owner->user_id, 'category' => 'Prototypes']);
        $this->get(route('network.events.show', $event))->assertOk()->assertSee('Approved prototype');
        $p->update(['status' => 'unpublished']);
        $this->get(route('network.events.show', $event))->assertOk()->assertDontSee('Approved prototype')->assertDontSee('SOURCE SECRET');
        $this->expectException(HttpException::class);
        app(EventWorkflow::class)->feature($admin->user, $event, ['network_publication_id' => $p->id, 'representative_id' => $owner->user_id, 'category' => 'Private']);
    }

    public function test_event_recognition_requires_recorded_attendance_and_owner_public_opt_in(): void
    {
        $i = Institution::factory()->create();
        $student = $this->member($i);
        $admin = $this->member($i, 'Institution Administrator');
        $event = InnovationEvent::factory()->create(['institution_id' => $i->id, 'status' => 'completed']);
        EventRegistration::factory()->create(['innovation_event_id' => $event->id, 'user_id' => $student->user_id, 'institution_id' => $i->id, 'status' => 'attended', 'attended_at' => now()]);
        $this->actingAs($admin->user);
        $w = app(RecognitionWorkflow::class);
        $this->get(route('network.recognitions', ['source' => 'event:'.$event->id]))->assertOk()->assertSee($student->user->name)->assertSee('Recorded eligible participant');
        $r = $w->confirm($admin->user, ['institution_id' => $i->id, 'user_id' => $student->user_id, 'source_type' => 'event', 'source_id' => $event->id, 'title' => 'Exhibition presentation', 'evidence' => 'Attendance checked by independent organiser']);
        $this->assertSame('verified', $r->verification->status);
        $this->assertFalse($r->verification->public_allowed);
        $this->actingAs($student->user);
        $this->get(route('network.recognitions'))->assertOk();
        $w->visibility($student->user, $r, true);
        $this->assertTrue($r->verification->fresh()->public_allowed);
        $this->actingAs($admin->user);
        $w->revoke($admin->user, $r, 'Evidence withdrawn');
        $this->assertSame('revoked', $r->verification->fresh()->status);
        $this->assertFalse($r->verification->fresh()->public_allowed);
    }
}
