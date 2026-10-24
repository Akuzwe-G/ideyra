<?php

namespace Tests\Feature;

use App\Models\AcademicClass;
use App\Models\ApplicationDocument;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\OpportunityApplication as Application;
use App\Models\OpportunityCategory;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\Role;
use App\Models\SponsorContact;
use App\Models\SponsorshipCommitment;
use App\Models\SponsorshipRequest;
use App\Models\User;
use App\Support\OpportunityAccess;
use App\Support\OpportunityAnalytics;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpportunityWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function system(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'System Administrator')->sole());

        return $user;
    }

    private function member(Institution $institution, string $role = 'Student'): Membership
    {
        return Membership::factory()->create(['institution_id' => $institution->id, 'role_id' => Role::where('name', $role)->sole()->id]);
    }

    private function grant(Membership $member, string $key, bool $allowed = true): void
    {
        DB::table('membership_permissions')->updateOrInsert(['membership_id' => $member->id, 'permission_id' => Permission::where('name', $key)->sole()->id], ['allowed' => $allowed]);
    }

    private function listing(array $extra = []): array
    {
        return $extra + ['title' => 'Research opportunity fixture', 'category_id' => OpportunityCategory::first()->id, 'summary' => 'A test research programme', 'description' => 'Test fixture description', 'organiser' => 'Test institution', 'source_attribution' => 'Test organiser announcement', 'instructions' => 'Submit a motivation statement.', 'eligibility_text' => 'Active student membership.', 'application_method' => 'internal', 'delivery_mode' => 'online', 'visibility' => 'platform'];
    }

    private function institutional(Institution $institution, User $owner, array $extra = []): Opportunity
    {
        return Opportunity::factory()->create($extra + ['institution_id' => $institution->id, 'created_by' => $owner->id, 'visibility' => 'institution']);
    }

    private function draft(Opportunity $opportunity, Membership $member, array $extra = []): Application
    {
        $this->actingAs($member->user)->post(route('opportunity-applications.store', $opportunity), $extra + ['institution_id' => $member->institution_id, 'motivation' => 'I would like to participate.', 'skills' => 'Research'])->assertRedirect();

        return Application::latest('id')->firstOrFail();
    }

    private function submit(Application $application): void
    {
        $this->actingAs($application->applicant)->post(route('opportunity-applications.action', $application), ['action' => 'submit', 'confirm_share' => 1])->assertRedirect();
    }

    public function test_platform_publication_is_audited_and_creation_does_not_bypass_approval(): void
    {
        $this->actingAs($this->system())->post(route('opportunities.store'), $this->listing())->assertRedirect();
        $o = Opportunity::sole();
        $this->assertSame('draft', $o->status);
        $this->post(route('opportunities.action', $o), ['action' => 'transition', 'status' => 'published', 'reason' => 'Bypass'])->assertUnprocessable();
        foreach (['submitted', 'approved', 'published'] as $status) {
            $this->post(route('opportunities.action', $o), ['action' => 'transition', 'status' => $status, 'reason' => 'Reviewed test fixture.'])->assertRedirect();
        }
        $this->assertSame('published', $o->fresh()->status);
        $this->assertDatabaseCount('opportunity_decisions', 3);
        $this->post(route('opportunities.action', $o), ['action' => 'transition', 'status' => 'approved', 'reason' => 'Unpublish'])->assertRedirect();
        $this->assertSame('approved', $o->fresh()->status);
    }

    public function test_institutional_creator_needs_explicit_permissions_and_cannot_publish_or_switch_institutions(): void
    {
        $institution = Institution::factory()->create();
        $student = $this->member($institution);
        $data = $this->listing(['institution_id' => $institution->id, 'visibility' => 'institution']);
        $this->actingAs($student->user)->post(route('opportunities.store'), $data)->assertForbidden();
        $this->grant($student, 'opportunities.create');
        $this->grant($student, 'opportunities.edit_own');
        $this->post(route('opportunities.store'), $data)->assertRedirect();
        $o = Opportunity::sole();
        $this->get(route('opportunities.show', $o))->assertOk();
        $this->post(route('opportunities.action', $o), ['action' => 'transition', 'status' => 'submitted', 'reason' => 'Ready for review'])->assertRedirect();
        $this->post(route('opportunities.action', $o), ['action' => 'transition', 'status' => 'approved', 'reason' => 'Self elevate'])->assertForbidden();
        $this->post(route('opportunities.store'), $this->listing(['institution_id' => Institution::factory()->create()->id, 'visibility' => 'institution']))->assertForbidden();
        $this->grant($student, 'opportunities.view', false);
        $this->post(route('opportunities.store'), $data)->assertForbidden();
    }

    public function test_visibility_and_eligibility_are_separate_and_restricted_audiences_are_scoped(): void
    {
        $a = Institution::factory()->create();
        $b = Institution::factory()->create();
        $admin = $this->member($a, 'Institution Administrator');
        $student = $this->member($a);
        $outsider = $this->member($b);
        $class = AcademicClass::factory()->create(['institution_id' => $a->id]);
        $o = $this->institutional($a, $admin->user, ['eligibility' => ['programme' => [$class->programme_id]]]);
        $this->actingAs($student->user)->get(route('opportunities.show', $o))->assertOk();
        $this->assertFalse(OpportunityAccess::eligible($o, $student->user, $a->id));
        $this->post(route('opportunity-applications.store', $o), ['institution_id' => $a->id, 'motivation' => 'Try'])->assertForbidden();
        $this->actingAs($outsider->user)->get(route('opportunities.show', $o))->assertNotFound();
        $o->update(['visibility' => 'restricted', 'eligibility' => []]);
        DB::table('opportunity_audiences')->insert(['opportunity_id' => $o->id, 'kind' => 'class', 'reference_id' => $class->id]);
        $this->actingAs($student->user)->get(route('opportunities.show', $o))->assertNotFound();
        DB::table('class_assignments')->insert(['institution_id' => $a->id, 'membership_id' => $student->id, 'academic_class_id' => $class->id, 'assignment' => 'student']);
        $this->get(route('opportunities.show', $o))->assertOk();
        $this->assertTrue(OpportunityAccess::eligible($o->fresh(), $student->user, $a->id));
    }

    public function test_deadline_and_event_dates_validate_and_unannounced_deadlines_render_truthfully(): void
    {
        $this->actingAs($this->system())->post(route('opportunities.store'), $this->listing(['opens_at' => '2026-12-20 10:00', 'deadline_at' => '2026-12-01 10:00']))->assertSessionHasErrors('deadline_at');
        $this->post(route('opportunities.store'), $this->listing(['event_starts_at' => '2026-12-20', 'event_ends_at' => '2026-12-01']))->assertSessionHasErrors('event_ends_at');
        $this->post(route('opportunities.store'), $this->listing())->assertRedirect();
        $o = Opportunity::sole();
        $this->get(route('opportunities.show', $o))->assertSee('Not announced');
    }

    public function test_discovery_search_filters_sorting_and_bookmarks_are_real(): void
    {
        $member = $this->member(Institution::factory()->create());
        $a = Opportunity::factory()->create(['title' => 'Alpha research call', 'country' => 'Mauritius', 'delivery_mode' => 'online']);
        Opportunity::factory()->create(['title' => 'Excluded internship', 'country' => 'France']);
        $this->actingAs($member->user)->get(route('opportunities.index', ['q' => 'research', 'country' => 'Mauritius', 'delivery_mode' => 'online', 'sort' => 'deadline', 'eligible' => 1]))->assertOk()->assertSee('Alpha research call')->assertDontSee('Excluded internship');
        foreach ([1, 1] as $saved) {
            $this->post(route('opportunities.action', $a), ['action' => 'bookmark', 'saved' => $saved])->assertRedirect();
        }
        $this->assertDatabaseCount('opportunity_bookmarks', 1);
        $this->get(route('opportunities.saved'))->assertSee('Alpha research call');
        $this->post(route('opportunities.action', $a), ['action' => 'bookmark', 'saved' => 0])->assertRedirect();
        $this->assertDatabaseCount('opportunity_bookmarks', 0);
    }

    public function test_individual_submission_snapshots_duplicate_prevention_and_edit_freeze(): void
    {
        $student = $this->member(Institution::factory()->create());
        $o = Opportunity::factory()->create();
        $a = $this->draft($o, $student);
        $this->actingAs($this->system())->get(route('opportunity-applications.show', $a))->assertNotFound();
        $this->actingAs($student->user)->post(route('opportunity-applications.store', $o), ['institution_id' => $student->institution_id, 'motivation' => 'Duplicate'])->assertSessionHasErrors('motivation');
        $this->submit($a);
        $this->assertSame('submitted', $a->fresh()->status);
        $raw = DB::table('application_snapshots')->sole()->payload;
        $this->assertStringNotContainsString('I would like', $raw);
        $this->assertStringContainsString('I would like', Crypt::decryptString($raw));
        $this->put(route('opportunity-applications.update', $a), ['institution_id' => $student->institution_id, 'motivation' => 'Overwrite'])->assertUnprocessable();
        $this->assertDatabaseCount('application_snapshots', 1);
    }

    public function test_question_validation_documents_and_private_downloads(): void
    {
        Storage::fake('local');
        $student = $this->member(Institution::factory()->create());
        $o = Opportunity::factory()->create();
        $text = DB::table('opportunity_questions')->insertGetId(['opportunity_id' => $o->id, 'label' => 'Explain interest', 'type' => 'text', 'required' => true]);
        $doc = DB::table('opportunity_questions')->insertGetId(['opportunity_id' => $o->id, 'label' => 'Relevant work sample', 'type' => 'document', 'required' => true]);
        $a = $this->draft($o, $student);
        $this->post(route('opportunity-applications.action', $a), ['action' => 'submit', 'confirm_share' => 1])->assertSessionHasErrors('answers');
        $this->put(route('opportunity-applications.update', $a), ['institution_id' => $student->institution_id, 'motivation' => 'Updated', 'answers' => [$text => 'My research interests']])->assertRedirect();
        $this->post(route('opportunity-applications.upload', $a), ['question_id' => $doc, 'file' => UploadedFile::fake()->create('work.pdf', 30, 'application/pdf'), 'document_basis' => 1])->assertRedirect();
        $document = ApplicationDocument::sole();
        Storage::disk('local')->assertExists($document->path);
        $this->submit($a);
        $this->get(route('opportunity-applications.download', [$a, $document]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->create())->get(route('opportunity-applications.download', [$a, $document]))->assertNotFound();
    }

    public function test_unsafe_documents_and_cross_opportunity_question_references_are_rejected(): void
    {
        Storage::fake('local');
        $student = $this->member(Institution::factory()->create());
        $a = $this->draft(Opportunity::factory()->create(), $student);
        $this->post(route('opportunity-applications.upload', $a), ['file' => UploadedFile::fake()->create('payload.html', 1, 'text/html'), 'document_basis' => 1])->assertSessionHasErrors('file');
        $q = DB::table('opportunity_questions')->insertGetId(['opportunity_id' => Opportunity::factory()->create()->id, 'label' => 'Other question', 'type' => 'document', 'required' => true]);
        $this->post(route('opportunity-applications.upload', $a), ['question_id' => $q, 'file' => UploadedFile::fake()->create('sample.pdf', 1, 'application/pdf'), 'document_basis' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('application_documents', 0);
    }

    public function test_group_submission_requires_current_leader_eligibility_and_each_members_consent(): void
    {
        $institution = Institution::factory()->create();
        $leader = $this->member($institution);
        $other = $this->member($institution);
        $group = ProjectGroup::factory()->create(['institution_id' => $institution->id, 'status' => 'active']);
        foreach ([$leader, $other] as $m) {
            $group->members()->create(['institution_id' => $institution->id, 'membership_id' => $m->id, 'role' => $m->id === $leader->id ? 'leader' : 'member', 'delegated_permissions' => []]);
        }
        $o = Opportunity::factory()->create(['allow_groups' => true]);
        $a = $this->draft($o, $leader, ['group_id' => $group->id, 'participant_ids' => [$leader->user_id, $other->user_id]]);
        $this->post(route('opportunity-applications.action', $a), ['action' => 'submit', 'confirm_share' => 1])->assertSessionHasErrors('consent');
        $this->actingAs($other->user)->post(route('opportunity-applications.action', $a), ['action' => 'consent', 'consent' => 1])->assertRedirect();
        $this->submit($a);
        $this->assertCount(2, $a->fresh()->participant_snapshot);
        $this->assertDatabaseCount('application_participants', 2);
        foreach ($a->fresh()->participant_snapshot as $participant) {
            $this->assertNotNull($participant['consented_at']);
        }
        $group->members()->where('membership_id', $other->id)->delete();
        $this->assertCount(2, $a->fresh()->participant_snapshot);
        $this->actingAs($other->user)->get(route('opportunity-applications.show', $a))->assertOk();
    }

    public function test_unassigned_group_representative_and_cross_institution_project_are_denied(): void
    {
        $institution = Institution::factory()->create();
        $student = $this->member($institution);
        $group = ProjectGroup::factory()->create(['institution_id' => $institution->id, 'status' => 'active']);
        $o = Opportunity::factory()->create(['allow_groups' => true]);
        $this->actingAs($student->user)->post(route('opportunity-applications.store', $o), ['institution_id' => $institution->id, 'motivation' => 'Use another group', 'group_id' => $group->id, 'participant_ids' => [$student->user_id]])->assertForbidden();
        $p = Project::factory()->create();
        $this->post(route('opportunity-applications.store', $o), ['institution_id' => $institution->id, 'motivation' => 'Use private project', 'project_id' => $p->id])->assertNotFound();
    }

    public function test_review_feedback_internal_notes_revision_snapshots_and_withdrawal(): void
    {
        $institution = Institution::factory()->create();
        $student = $this->member($institution);
        $admin = $this->member($institution, 'Institution Administrator');
        $o = $this->institutional($institution, $admin->user);
        $a = $this->draft($o, $student);
        $this->submit($a);
        $this->actingAs($student->user)->post(route('opportunity-applications.action', $a), ['action' => 'review', 'status' => 'accepted', 'feedback' => 'Self approve'])->assertForbidden();
        $this->actingAs($admin->user)->post(route('opportunity-applications.action', $a), ['action' => 'review', 'status' => 'more_information_requested', 'feedback' => 'Explain experience.', 'internal_note' => 'Confidential reviewer assessment'])->assertRedirect();
        $this->assertStringNotContainsString('Confidential', DB::table('application_reviews')->sole()->internal_note);
        $this->actingAs($student->user)->get(route('opportunity-applications.show', $a))->assertSee('Explain experience.')->assertDontSee('Confidential reviewer assessment');
        $this->put(route('opportunity-applications.update', $a), ['institution_id' => $institution->id, 'motivation' => 'New unsubmitted revision'])->assertRedirect();
        $this->actingAs($admin->user)->get(route('opportunity-applications.show', $a))->assertDontSee('New unsubmitted revision');
        $this->submit($a);
        $this->assertDatabaseCount('application_snapshots', 2);
        $this->actingAs($admin->user)->post(route('opportunity-applications.action', $a), ['action' => 'review', 'status' => 'shortlisted', 'feedback' => 'Promising.'])->assertRedirect();
        $this->actingAs($student->user)->post(route('opportunity-applications.action', $a), ['action' => 'withdraw'])->assertRedirect();
        $this->assertSame('withdrawn', $a->fresh()->status);
        $this->actingAs($admin->user)->post(route('opportunity-applications.action', $a), ['action' => 'review', 'status' => 'accepted', 'feedback' => 'Too late'])->assertUnprocessable();
    }

    public function test_own_application_cannot_be_reviewed_even_by_system_administrator(): void
    {
        $institution = Institution::factory()->create();
        $user = $this->system();
        $student = Membership::factory()->create(['institution_id' => $institution->id, 'user_id' => $user->id, 'role_id' => Role::where('name', 'Student')->sole()->id]);
        $o = Opportunity::factory()->create(['created_by' => $user->id]);
        $a = $this->draft($o, $student);
        $this->submit($a);
        $this->post(route('opportunity-applications.action', $a), ['action' => 'review', 'status' => 'accepted', 'feedback' => 'Conflict'])->assertForbidden();
    }

    public function test_external_link_does_not_submit_and_self_report_remains_unverified(): void
    {
        $student = $this->member(Institution::factory()->create());
        $o = Opportunity::factory()->create(['application_method' => 'external', 'external_url' => 'https://organiser.example.test/official-apply']);
        $this->actingAs($student->user)->get(route('opportunities.show', $o))->assertOk()->assertSee('https://organiser.example.test/official-apply')->assertSee('Opening the link does not submit');
        $this->assertDatabaseCount('opportunity_applications', 0);
        foreach ([1, 2] as $attempt) {
            $this->post(route('opportunity-applications.external', $o), ['institution_id' => $student->institution_id, 'confirm_external' => 1])->assertRedirect();
        }
        $a = Application::sole();
        $this->assertSame('external_reported', $a->status);
        $this->assertTrue($a->external_self_reported);
        $this->assertNull($a->submitted_at);
        $this->post(route('opportunity-applications.store', $o), ['institution_id' => $student->institution_id, 'motivation' => 'False internal submission'])->assertUnprocessable();
    }

    public function test_publication_deadline_notifications_are_deduplicated_and_skip_revoked_audiences(): void
    {
        $student = $this->member(Institution::factory()->create());
        $o = Opportunity::factory()->create(['deadline_at' => now()->addHours(40)]);
        DB::table('opportunity_bookmarks')->insert(['opportunity_id' => $o->id, 'user_id' => $student->user_id]);
        $this->artisan('ideyra:opportunities:remind')->assertSuccessful();
        $this->artisan('ideyra:opportunities:remind')->assertSuccessful();
        $this->assertDatabaseCount('opportunity_notices', 1);
        $this->assertDatabaseCount('notifications', 1);
        $o->update(['status' => 'closed']);
        $this->artisan('ideyra:opportunities:remind')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_sponsorship_request_review_contacts_commitments_and_verified_fulfilment(): void
    {
        $institution = Institution::factory()->create();
        $student = $this->member($institution);
        $admin = $this->member($institution, 'Institution Administrator');
        $project = Project::factory()->create(['owner_id' => $student->user_id, 'institution_id' => $institution->id]);
        $data = ['project_id' => $project->id, 'support_type' => 'equipment', 'description' => 'Sensors required', 'justification' => 'Research prototype', 'intended_use' => 'Build measurement rig', 'estimated_value' => '500', 'currency' => 'MUR'];
        $this->actingAs($student->user)->post(route('sponsorships.store'), $data)->assertRedirect();
        $r = SponsorshipRequest::sole();
        $this->post(route('sponsorships.action', $r), ['status' => 'pending_review', 'reason' => 'Please review.'])->assertRedirect();
        $this->post(route('sponsorships.action', $r), ['status' => 'approved_outreach', 'reason' => 'Self approve'])->assertForbidden();
        $this->actingAs($admin->user)->post(route('sponsorships.action', $r), ['status' => 'approved_outreach', 'reason' => 'Approved for non-payment outreach.'])->assertRedirect();
        $this->post(route('sponsorships.contact', $r), ['name' => 'Private sponsor fixture', 'email' => 'private@example.test', 'type' => 'organisation', 'contact_basis' => 1])->assertRedirect();
        $contact = SponsorContact::sole();
        $this->assertStringNotContainsString('Private sponsor', DB::table('sponsor_contacts')->sole()->name);
        $this->post(route('sponsorships.commitment', $r), ['sponsor_contact_id' => $contact->id, 'offered_support' => 'Two sensors', 'pledged_value' => '500', 'currency' => 'MUR'])->assertRedirect();
        $commitment = SponsorshipCommitment::sole();
        foreach (['under_discussion', 'committed'] as $status) {
            $this->post(route('sponsorships.commitment-status', [$r, $commitment]), ['status' => $status, 'reason' => 'Confirmed discussion.'])->assertRedirect();
        }
        $this->post(route('sponsorships.commitment-status', [$r, $commitment]), ['status' => 'fulfilled', 'reason' => 'Try early', 'evidence' => 'Delivered'])->assertUnprocessable();
        $this->post(route('sponsorships.verify', [$r, $contact]), ['evidence' => 'Institution verified the named contact.'])->assertRedirect();
        $this->grant($admin, 'sponsorships.verify_fulfilment', false);
        $this->post(route('sponsorships.commitment-status', [$r, $commitment]), ['status' => 'fulfilled', 'reason' => 'Explicit denial must win', 'evidence' => 'Receipt'])->assertForbidden();
        $this->assertNull($commitment->fresh()->fulfilled_at);
        $this->grant($admin, 'sponsorships.verify_fulfilment', true);
        $this->post(route('sponsorships.commitment-status', [$r, $commitment]), ['status' => 'fulfilled', 'reason' => 'Support received', 'evidence' => 'Equipment receipt checked by staff.'])->assertRedirect();
        $this->assertNotNull($commitment->fresh()->fulfilled_at);
        $this->actingAs($student->user)->get(route('sponsorships.show', $r))->assertOk()->assertDontSee('private@example.test')->assertDontSee('Private sponsor fixture')->assertSee('Two sensors');
        $this->post(route('sponsorships.contact', $r), ['name' => 'Bypass', 'type' => 'organisation', 'contact_basis' => 1])->assertForbidden();
    }

    public function test_sponsorship_permission_denials_and_platform_role_do_not_expose_confidential_data(): void
    {
        $institution = Institution::factory()->create();
        $student = $this->member($institution);
        $p = Project::factory()->create(['owner_id' => $student->user_id, 'institution_id' => $institution->id]);
        $r = SponsorshipRequest::factory()->create(['project_id' => $p->id]);
        $this->actingAs($this->system())->get(route('sponsorships.show', $r))->assertNotFound();
        $mentor = $this->member($institution, 'Mentor / Supervisor');
        $p->members()->create(['user_id' => $mentor->user_id, 'institution_id' => $institution->id, 'membership_id' => $mentor->id, 'role' => 'supervisor']);
        $this->actingAs($mentor->user)->post(route('sponsorships.contact', $r), ['name' => 'No financial rights', 'type' => 'organisation', 'contact_basis' => 1])->assertForbidden();
        $this->grant($student, 'sponsorships.request', false);
        $this->actingAs($student->user)->post(route('sponsorships.store'), ['project_id' => $p->id, 'support_type' => 'equipment', 'description' => 'Try', 'justification' => 'Try', 'intended_use' => 'Try'])->assertForbidden();
    }

    public function test_authorised_csv_excludes_private_notes_and_neutralises_formula_cells(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution, 'Institution Administrator');
        $student = $this->member($institution);
        $student->user->update(['name' => '=SUM(1,1)']);
        $o = $this->institutional($institution, $admin->user);
        $a = $this->draft($o, $student);
        $this->submit($a);
        $this->actingAs($admin->user)->post(route('opportunity-applications.action', $a), ['action' => 'review', 'status' => 'under_review', 'feedback' => 'Checking.', 'internal_note' => 'Do not export this note'])->assertRedirect();
        $response = $this->get(route('opportunity-applications.export', $o))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=SUM", $csv);
        $this->assertStringNotContainsString('Do not export', $csv);
        $this->actingAs($student->user)->get(route('opportunity-applications.export', $o))->assertForbidden();
    }

    public function test_analytics_deduplicate_student_participation_and_scope_institution_data(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution, 'Institution Administrator');
        $student = $this->member($institution);
        $class = AcademicClass::factory()->create(['institution_id' => $institution->id]);
        DB::table('class_assignments')->insert(['institution_id' => $institution->id, 'membership_id' => $student->id, 'academic_class_id' => $class->id, 'assignment' => 'student']);
        foreach ([1, 2] as $i) {
            $o = $this->institutional($institution, $admin->user);
            $a = $this->draft($o, $student);
            $this->submit($a);
        }
        $other = Institution::factory()->create();
        Application::factory()->create(['institution_id' => $other->id, 'submitted_at' => now(), 'status' => 'accepted']);
        $this->actingAs($admin->user);
        $filters = ['from' => today()->subDay()->toDateString(), 'to' => today()->toDateString(), 'group' => 'monthly'];
        $data = app(OpportunityAnalytics::class)->data($filters, 'institution', $institution);
        $this->assertSame(2, $data['kpis']['Internal applications']);
        $this->assertSame(1, $data['kpis']['Unique student participants']);
        $chart = collect($data['charts'])->firstWhere('key', 'opportunity-programme-applications');
        $this->assertSame([2], $chart['values']);
        $this->get(route('opportunity-analytics.csv', ['chart' => 'opportunity-programme-applications', 'scope' => 'institution', 'institution_id' => $institution->id] + $filters))->assertOk();
        $this->actingAs($student->user)->get(route('opportunity-analytics.index', ['scope' => 'institution', 'institution_id' => $institution->id]))->assertForbidden();
    }

    public function test_categories_require_system_authority_and_deactivation_preserves_history(): void
    {
        $category = OpportunityCategory::first();
        $this->actingAs(User::factory()->create())->get(route('opportunities.categories'))->assertForbidden();
        $this->actingAs($this->system())->post(route('opportunities.categories'), ['id' => $category->id, 'name' => $category->name, 'active' => 0])->assertRedirect();
        $this->assertFalse($category->fresh()->active);
        $this->post(route('opportunities.store'), $this->listing(['category_id' => $category->id]))->assertUnprocessable();
    }

    public function test_empty_pages_render_and_exports_and_notification_links_recheck_current_scope(): void
    {
        $student = $this->member(Institution::factory()->create());
        $this->actingAs($student->user);
        foreach (['opportunities.index', 'opportunities.saved', 'opportunity-applications.index', 'sponsorships.index', 'sponsorships.create', 'opportunity-analytics.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('opportunity-analytics.index', ['from' => '2026-12-01', 'to' => '2026-01-01']))->assertSessionHasErrors('from');
        $this->get(route('opportunity-analytics.csv', ['chart' => 'opportunity-application-status']))->assertOk();
    }

    public function test_unchanged_group_drafts_preserve_consent_and_deduplicate_invites_and_stale_links(): void
    {
        $institution = Institution::factory()->create();
        $leader = $this->member($institution);
        $other = $this->member($institution);
        $group = ProjectGroup::factory()->create(['institution_id' => $institution->id, 'status' => 'active']);
        foreach ([$leader, $other] as $member) {
            $group->members()->create(['institution_id' => $institution->id, 'membership_id' => $member->id, 'role' => $member->id === $leader->id ? 'leader' : 'member', 'delegated_permissions' => []]);
        }
        $opportunity = Opportunity::factory()->create(['allow_groups' => true]);
        $data = ['institution_id' => $institution->id, 'group_id' => $group->id, 'participant_ids' => [$leader->user_id, $other->user_id], 'motivation' => 'Shared research proposal', 'skills' => 'Research'];
        $application = $this->draft($opportunity, $leader, $data);
        $this->actingAs($other->user)->post(route('opportunity-applications.action', $application), ['action' => 'consent', 'consent' => 1])->assertRedirect();
        $notification = $other->user->notifications()->sole();
        $this->actingAs($leader->user)->put(route('opportunity-applications.update', $application), $data)->assertRedirect();
        $this->assertNotNull(DB::table('opportunity_consents')->where('application_id', $application->id)->where('user_id', $other->user_id)->value('consented_at'));
        $this->assertSame(1, $other->user->notifications()->count());
        $this->put(route('opportunity-applications.update', $application), array_replace($data, ['motivation' => 'Changed shared research proposal']))->assertRedirect();
        $this->assertNull(DB::table('opportunity_consents')->where('application_id', $application->id)->where('user_id', $other->user_id)->value('consented_at'));
        $this->assertSame(2, $other->user->notifications()->count());
        $group->members()->where('membership_id', $other->id)->delete();
        $this->actingAs($other->user)->get(route('notifications.open', $notification->id))->assertRedirect(route('notifications.index'));
        $this->get(route('opportunity-applications.show', $application))->assertNotFound();
    }

    public function test_unsubmitted_revision_documents_stay_private_until_resubmission(): void
    {
        Storage::fake('local');
        $institution = Institution::factory()->create();
        $student = $this->member($institution);
        $admin = $this->member($institution, 'Institution Administrator');
        $opportunity = $this->institutional($institution, $admin->user);
        $application = $this->draft($opportunity, $student);
        $this->submit($application);
        $this->actingAs($admin->user)->post(route('opportunity-applications.action', $application), ['action' => 'review', 'status' => 'more_information_requested', 'feedback' => 'Provide work evidence.'])->assertRedirect();
        $this->actingAs($student->user)->post(route('opportunity-applications.upload', $application), ['file' => UploadedFile::fake()->create('unsubmitted-evidence.pdf', 10, 'application/pdf'), 'document_basis' => 1])->assertRedirect();
        $document = ApplicationDocument::sole();
        $this->actingAs($admin->user)->get(route('opportunity-applications.show', $application))->assertOk()->assertDontSee('unsubmitted-evidence.pdf');
        $this->get(route('opportunity-applications.download', [$application, $document]))->assertForbidden();
        $this->submit($application);
        $this->actingAs($admin->user)->get(route('opportunity-applications.download', [$application, $document]))->assertOk();
    }

    public function test_publication_notifications_are_opt_in_and_repeat_publication_does_not_spam(): void
    {
        $institution = Institution::factory()->create();
        $eligible = $this->member($institution);
        $ineligible = $this->member($institution);
        $this->grant($ineligible, 'opportunities.apply', false);
        $system = $this->system();
        $opportunity = Opportunity::factory()->create(['created_by' => $system->id, 'status' => 'approved', 'notify_publication' => true]);
        $this->actingAs($system)->post(route('opportunities.action', $opportunity), ['action' => 'transition', 'status' => 'published', 'reason' => 'Audience publication'])->assertRedirect();
        $this->assertSame(1, $eligible->user->notifications()->count());
        $this->assertSame(0, $ineligible->user->notifications()->count());
        foreach (['approved', 'published'] as $status) {
            $this->post(route('opportunities.action', $opportunity), ['action' => 'transition', 'status' => $status, 'reason' => 'Republish reviewed listing'])->assertRedirect();
        }
        $this->assertSame(1, $eligible->user->notifications()->count());
    }

    public function test_institution_availability_counts_real_audiences_and_programme_chart_counts_applications(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution, 'Institution Administrator');
        $student = $this->member($institution);
        $opportunity = $this->institutional($institution, $admin->user, ['visibility' => 'restricted']);
        DB::table('opportunity_audiences')->insert(['opportunity_id' => $opportunity->id, 'kind' => 'role', 'reference_id' => $student->role_id]);
        $filters = ['from' => today()->subDay()->toDateString(), 'to' => today()->toDateString(), 'group' => 'monthly'];
        $this->actingAs($admin->user);
        $data = app(OpportunityAnalytics::class)->data($filters, 'institution', $institution);
        $this->assertSame(1, $data['kpis']['Available to institution members']);
        $this->assertCount(4, $data['charts']);
        $this->grant($student, 'opportunities.view', false);
        $data = app(OpportunityAnalytics::class)->data($filters, 'institution', $institution);
        $this->assertSame(0, $data['kpis']['Available to institution members']);
    }

    public function test_sponsor_contact_cannot_be_reused_across_requests_even_without_controller_checks(): void
    {
        $a = SponsorshipRequest::factory()->create();
        $b = SponsorshipRequest::factory()->create();
        $contact = SponsorContact::factory()->create(['sponsorship_request_id' => $b->id]);
        $this->expectException(QueryException::class);
        SponsorshipCommitment::factory()->create(['sponsorship_request_id' => $a->id, 'sponsor_contact_id' => $contact->id]);
    }

    public function test_listing_managers_are_explicitly_scoped_and_removable_without_publishing_privileges(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution, 'Institution Administrator');
        $lecturer = $this->member($institution, 'Lecturer / Teacher');
        $this->grant($lecturer, 'opportunities.manage');
        $opportunity = $this->institutional($institution, $admin->user, ['status' => 'draft']);
        $this->actingAs($lecturer->user)->get(route('opportunities.show', $opportunity))->assertNotFound();
        $this->actingAs($admin->user)->post(route('opportunities.action', $opportunity), ['action' => 'manager', 'membership_id' => $lecturer->id])->assertRedirect();
        $this->actingAs($lecturer->user)->get(route('opportunities.show', $opportunity))->assertOk();
        $this->post(route('opportunities.action', $opportunity), ['action' => 'transition', 'status' => 'published', 'reason' => 'No publishing grant'])->assertForbidden();
        $this->actingAs($admin->user)->post(route('opportunities.action', $opportunity), ['action' => 'unassign', 'user_id' => $lecturer->user_id])->assertRedirect();
        $this->actingAs($lecturer->user)->get(route('opportunities.show', $opportunity))->assertNotFound();
    }

    public function test_malformed_questions_and_unsafe_external_urls_fail_validation(): void
    {
        $this->actingAs($this->system())->post(route('opportunities.store'), $this->listing(['questions' => 'malformed']))->assertSessionHasErrors('questions');
        $this->post(route('opportunities.store'), $this->listing(['application_method' => 'external', 'external_url' => 'javascript:alert(1)']))->assertSessionHasErrors('external_url');
        $this->assertDatabaseCount('opportunities', 0);
    }
}
