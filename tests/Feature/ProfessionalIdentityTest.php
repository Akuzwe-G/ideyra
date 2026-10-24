<?php

namespace Tests\Feature;

use App\Models\AchievementVerification;
use App\Models\CvDocument;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\OpportunityApplication;
use App\Models\Permission;
use App\Models\Portfolio;
use App\Models\PortfolioMedia;
use App\Models\PortfolioMessage;
use App\Models\ProfessionalEntry;
use App\Models\ProfessionalProfile;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\ProjectShowcase;
use App\Models\Role;
use App\Models\User;
use App\Support\CareerAccess;
use App\Support\CareerAnalytics;
use App\Support\CareerWorkflow;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfessionalIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function member(Institution $institution, string $role = 'Student', ?User $user = null): Membership
    {
        return Membership::factory()->create(['institution_id' => $institution->id, 'user_id' => $user?->id ?? User::factory(), 'role_id' => Role::where('name', $role)->sole()->id]);
    }

    private function profile(?User $user = null): ProfessionalProfile
    {
        return ProfessionalProfile::factory()->create(['user_id' => $user?->id ?? User::factory(), 'professional_email' => 'chosen@professional.example', 'phone' => '555-0100']);
    }

    private function cvData(array $extra = []): array
    {
        return $extra + ['title' => 'Research internship CV', 'template' => 'classic', 'sections' => ['summary', 'skill'], 'content' => ['contact' => "Professional identity\nhttps://github.com/example", 'summary' => 'Original professional summary', 'skill' => 'Research']];
    }

    private function portfolio(ProfessionalProfile $profile, array $extra = []): Portfolio
    {
        return Portfolio::factory()->create($extra + ['professional_profile_id' => $profile->id]);
    }

    private function publish(Portfolio $portfolio): void
    {
        $this->actingAs($portfolio->profile->user)->post(route('career.portfolio.publish'), ['action' => 'publish', 'confirm' => 1])->assertRedirect(route('career.portfolio'));
    }

    private function verifyEntry(ProfessionalEntry $entry, Membership $student, Membership $admin, array $extra = []): AchievementVerification
    {
        $category = match ($entry->type) {
            'skill' => 'skill','certification' => 'certificate',default => 'achievement'
        };
        $this->actingAs($student->user)->post(route('career.verifications.request'), $extra + ['institution_id' => $student->institution_id, 'entry_id' => $entry->id, 'category' => $category, 'evidence' => 'Private reviewed evidence', 'public_allowed' => 1, 'confirm' => 1])->assertRedirect();
        $v = AchievementVerification::latest('id')->firstOrFail();
        $this->actingAs($admin->user)->post(route('career.verifications.decision', $v), ['status' => 'verified', 'reason' => 'Confirmed by institutional evidence', 'evidence_reviewed' => 'Private decision evidence details', 'public_allowed' => 1])->assertRedirect();

        return $v->fresh();
    }

    public function test_profile_and_all_record_types_are_owned_and_do_not_change_account_identity(): void
    {
        $u = User::factory()->create();
        $original = $u->email;
        $this->actingAs($u)->post(route('career.profile'), ['display_name' => 'Professional Student', 'headline' => 'Research and design', 'biography' => 'Real biography', 'professional_email' => 'professional@example.test', 'links' => ['github' => 'https://github.com/example']])->assertRedirect();
        $profile = ProfessionalProfile::sole();
        $this->assertSame($original, $u->fresh()->email);
        foreach (config('career.entry_types') as $type => $label) {
            $content = match ($type) {
                'education' => ['organisation' => 'My college', 'qualification' => 'BSc', 'start' => '2024-01-01', 'end' => '2027-01-01', 'current' => '1'],'experience' => ['organisation' => 'Lab', 'position' => 'Assistant', 'description' => 'Real responsibilities'],'language' => ['proficiency' => 'Fluent'],default => ['description' => 'Real evidence description']
            };
            $this->post(route('career.entries.save'), ['type' => $type, 'title' => $label.' fixture', 'content' => $content])->assertRedirect();
        }
        $this->assertSame(6, $profile->entries()->count());
        $entry = $profile->entries()->first();
        $this->actingAs(User::factory()->create())->post(route('career.entries.save', $entry), ['type' => $entry->type, 'title' => 'Forged', 'content' => ['description' => 'Unauthorised']])->assertForbidden();
        $this->delete(route('career.entries.delete', $entry))->assertForbidden();
        $this->assertNotSame('Forged', $entry->fresh()->title);
    }

    public function test_career_screens_render_for_empty_and_populated_profiles(): void
    {
        $profile = $this->profile();
        $this->actingAs($profile->user);
        $initialPortfolio = app(CareerWorkflow::class)->portfolio();
        $this->assertSame('minimal', $initialPortfolio->theme);
        $this->assertFalse($initialPortfolio->published);
        foreach (['overview', 'profile', ...array_keys(config('career.entry_types')), 'project'] as $tab) {
            $this->get(route('career.index', ['tab' => $tab]))->assertOk();
        }
        foreach (['career.verifications', 'career.cvs.index', 'career.cvs.create', 'career.portfolio', 'career.portfolio.preview'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_completeness_is_deterministic_optional_and_does_not_require_contacts(): void
    {
        $profile = $this->profile();
        $workflow = app(CareerWorkflow::class);
        $this->assertSame(38, $workflow->completeness($profile)['percent']);
        ProfessionalEntry::factory()->create(['professional_profile_id' => $profile->id, 'type' => 'education']);
        ProfessionalEntry::factory()->create(['professional_profile_id' => $profile->id, 'type' => 'skill']);
        $this->assertSame(63, $workflow->completeness($profile)['percent']);
        $this->actingAs($profile->user)->get(route('opportunities.index'))->assertOk();
    }

    public function test_evidence_upload_is_private_and_unsafe_files_and_links_are_rejected(): void
    {
        Storage::fake('local');
        $p = $this->profile();
        $this->actingAs($p->user)->post(route('career.entries.save'), ['type' => 'certification', 'title' => 'Real certificate', 'content' => ['organisation' => 'Issuer'], 'evidence' => UploadedFile::fake()->create('evidence.pdf', 10, 'application/pdf')])->assertRedirect();
        $entry = ProfessionalEntry::sole();
        Storage::disk('local')->assertExists($entry->evidence_path);
        $this->get(route('career.entries.evidence', $entry))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('career.entries.evidence', $entry))->assertForbidden();
        $this->actingAs($p->user)->post(route('career.entries.save'), ['type' => 'skill', 'title' => 'Unsafe', 'content' => ['link' => 'javascript:alert(1)'], 'evidence' => UploadedFile::fake()->create('code.php', 1, 'application/x-php')])->assertSessionHasErrors(['content.link', 'evidence']);
    }

    public function test_showcase_preserves_actual_role_and_rejects_unrelated_projects(): void
    {
        $p = $this->profile();
        $project = Project::factory()->create();
        $project->members()->create(['user_id' => $p->user_id, 'role' => 'contributor']);
        $this->actingAs($p->user)->post(route('career.showcases.save'), ['project_id' => $project->id, 'reflection' => 'I contributed research', 'technologies' => 'PHP'])->assertRedirect();
        $s = ProjectShowcase::sole();
        $this->assertSame('Contributor', CareerAccess::projectContent($s)['role']);
        $this->assertNull(CareerAccess::publicProject($s));
        $project->members()->delete();
        $this->assertNull(CareerAccess::projectContent($s->fresh()));
        $this->post(route('career.showcases.save'), ['project_id' => Project::factory()->create()->id])->assertForbidden();
    }

    public function test_cv_versions_are_private_ordered_independent_and_export_real_pdfs_for_three_templates(): void
    {
        $p = $this->profile();
        $this->actingAs($p->user);
        foreach (config('career.templates') as $template => $label) {
            $this->post(route('career.cvs.store'), $this->cvData(['template' => $template, 'sections' => ['summary', 'skill'], 'section_order' => ['summary' => 2, 'skill' => 1]]))->assertRedirect();
            $cv = CvDocument::latest('id')->first();
            $this->assertSame(['skill', 'summary'], $cv->sections);
            $this->get(route('career.cvs.preview', $cv))->assertOk()->assertSee('Original professional summary');
            $response = $this->get(route('career.cvs.preview', [$cv, 'pdf' => 1]))->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            preg_match('~/Type /Pages\b.*?/Count (\d+)~s', $response->getContent(), $pages);
            $this->assertSame('1', $pages[1] ?? null, 'A short CV should export as one A4 page without trailing blank pages.');
            $this->assertStringContainsString('/URI', $response->getContent());
            $this->assertGreaterThan(5000, strlen($response->getContent()));
        }
        $cv = CvDocument::latest('id')->first();
        $this->post(route('career.cvs.save', $cv), $this->cvData(['content' => ['summary' => 'Edited only in CV']]))->assertRedirect();
        $this->assertSame(2, $cv->snapshots()->count());
        $this->assertSame('Original professional summary', $cv->snapshots()->where('version', 1)->sole()->payload['content']['summary']);
        $this->assertNotSame('Edited only in CV', $p->fresh()->biography);
        $this->actingAs(User::factory()->create())->get(route('career.cvs.preview', $cv))->assertForbidden();
        $this->post(route('career.cvs.save', $cv), $this->cvData())->assertForbidden();
        $this->assertStringNotContainsString('Original professional summary', DB::table('cv_documents')->first()->content);
    }

    public function test_cv_duplicate_import_search_delete_and_preview_do_not_mutate_main_profile(): void
    {
        $p = $this->profile();
        $cv = CvDocument::factory()->create(['user_id' => $p->user_id]);
        $this->actingAs($p->user)->post(route('career.cvs.action', $cv), ['action' => 'duplicate', 'confirm' => 1])->assertRedirect();
        $this->assertSame(2, CvDocument::count());
        $copy = CvDocument::latest('id')->first();
        $this->post(route('career.cvs.action', $copy), ['action' => 'import', 'confirm' => 1])->assertRedirect();
        $this->assertStringContainsString($p->biography, $copy->fresh()->content['summary']);
        $this->get(route('career.cvs.index', ['q' => '(copy)']))->assertOk()->assertSee($copy->title)->assertDontSee('No CV');
        $this->post(route('career.cvs.draft-preview'), $this->cvData())->assertOk()->assertSee('Original professional summary');
        $this->post(route('career.cvs.action', $copy), ['action' => 'delete'])->assertSessionHasErrors('confirm');
        $this->post(route('career.cvs.action', $copy), ['action' => 'delete', 'confirm' => 1])->assertRedirect();
        $this->assertSame(1, CvDocument::count());
    }

    public function test_portfolio_is_opt_in_and_contact_fields_and_records_require_explicit_selection(): void
    {
        $p = $this->profile();
        $skill = ProfessionalEntry::factory()->create(['professional_profile_id' => $p->id, 'title' => 'Selected skill']);
        $private = ProfessionalEntry::factory()->create(['professional_profile_id' => $p->id, 'title' => 'Private secret skill']);
        $portfolio = $this->portfolio($p, ['sections' => ['biography', 'skill'], 'entry_ids' => [$skill->id]]);
        $this->get(route('portfolio.public', $portfolio->slug))->assertNotFound();
        $this->publish($portfolio);
        $response = $this->get(route('portfolio.public', $portfolio->slug))->assertOk()->assertSee('Selected skill')->assertDontSee('Private secret skill')->assertDontSee($p->professional_email)->assertDontSee($p->user->email)->assertDontSee($p->phone)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->actingAs($p->user)->post(route('career.portfolio.save'), ['slug' => $portfolio->slug, 'theme' => 'creative', 'sections' => ['skill'], 'entry_ids' => [$skill->id], 'contact_fields' => ['professional_email'], 'search_visible' => 1])->assertRedirect();
        $this->get(route('portfolio.public', $portfolio->slug))->assertNotFound();
        $this->publish($portfolio->fresh());
        $this->get(route('portfolio.public', $portfolio->slug))->assertOk()->assertSee($p->professional_email)->assertDontSee($p->biography)->assertHeader('X-Robots-Tag', 'index, follow');
        $this->post(route('career.portfolio.publish'), ['action' => 'unpublish', 'confirm' => 1])->assertRedirect();
        $this->get(route('portfolio.public', $portfolio->slug))->assertNotFound();
    }

    public function test_portfolio_slug_uniqueness_other_users_records_and_bad_themes_are_rejected(): void
    {
        $p = $this->profile();
        $other = $this->profile();
        $this->portfolio($other, ['slug' => 'existing-name']);
        $entry = ProfessionalEntry::factory()->create(['professional_profile_id' => $other->id]);
        $this->actingAs($p->user)->post(route('career.portfolio.save'), ['slug' => 'existing-name', 'theme' => 'minimal'])->assertSessionHasErrors('slug');
        $this->post(route('career.portfolio.save'), ['slug' => 'available-name', 'theme' => 'minimal', 'entry_ids' => [$entry->id]])->assertUnprocessable();
        $this->post(route('career.portfolio.save'), ['slug' => 'available-name', 'theme' => 'untrusted'])->assertSessionHasErrors('theme');
        $this->post(route('career.portfolio.publish'), ['action' => 'publish'])->assertSessionHasErrors('confirm');
    }

    public function test_verification_requires_independent_institutional_authority_and_keeps_private_history(): void
    {
        $institution = Institution::factory()->create();
        $student = $this->member($institution);
        $admin = $this->member($institution, 'Institution Administrator');
        $p = $this->profile($student->user);
        $entry = ProfessionalEntry::factory()->create(['professional_profile_id' => $p->id, 'type' => 'achievement', 'title' => 'Research award']);
        $this->actingAs($student->user)->post(route('career.verifications.request'), ['institution_id' => $institution->id, 'entry_id' => $entry->id, 'category' => 'achievement', 'evidence' => 'Private evidence', 'public_allowed' => 1, 'confirm' => 1])->assertRedirect();
        $v = AchievementVerification::sole();
        $decision = ['status' => 'verified', 'reason' => 'Reviewed independently', 'evidence_reviewed' => 'Confidential academic evidence', 'public_allowed' => 1];
        $this->post(route('career.verifications.decision', $v), $decision)->assertForbidden();
        $unrelated = $this->member(Institution::factory()->create(), 'Institution Administrator');
        $this->actingAs($unrelated->user)->post(route('career.verifications.decision', $v), $decision)->assertForbidden();
        $this->actingAs($admin->user)->post(route('career.verifications.decision', $v), $decision)->assertRedirect();
        $this->assertSame($admin->user_id, $v->fresh()->verified_by);
        $this->assertDatabaseCount('verification_decisions', 1);
        $this->assertStringNotContainsString('Confidential academic evidence', DB::table('verification_decisions')->first()->evidence_reviewed);
        $portfolio = $this->portfolio($p, ['sections' => ['achievement'], 'entry_ids' => [$entry->id]]);
        $this->get(route('verification.public', $v->reference))->assertNotFound();
        $this->publish($portfolio);
        $this->get(route('verification.public', $v->reference))->assertOk()->assertSee('Research award')->assertDontSee('Confidential academic evidence')->assertDontSee('Private evidence')->assertDontSee($student->user->email);
        $this->actingAs($admin->user)->post(route('career.verifications.decision', $v), ['status' => 'revoked', 'reason' => 'Evidence withdrawn', 'evidence_reviewed' => 'Revocation record'])->assertRedirect();
        $this->get(route('verification.public', $v->reference))->assertOk()->assertSee('Revoked');
        $this->actingAs($student->user)->post(route('career.verifications.visibility', $v), ['hide' => 1])->assertRedirect();
        $this->get(route('verification.public', $v->reference))->assertNotFound();
    }

    public function test_verification_rechecks_permission_denials_and_subject_changes(): void
    {
        $i = Institution::factory()->create();
        $s = $this->member($i);
        $a = $this->member($i, 'Institution Administrator');
        $p = $this->profile($s->user);
        $entry = ProfessionalEntry::factory()->create(['professional_profile_id' => $p->id, 'type' => 'certification']);
        $this->actingAs($s->user)->post(route('career.verifications.request'), ['institution_id' => $i->id, 'entry_id' => $entry->id, 'category' => 'certificate', 'evidence' => 'Certificate evidence', 'confirm' => 1])->assertRedirect();
        $v = AchievementVerification::sole();
        $entry->update(['title' => 'Changed certificate']);
        $decision = ['status' => 'verified', 'reason' => 'Review', 'evidence_reviewed' => 'Evidence'];
        $this->actingAs($a->user)->post(route('career.verifications.decision', $v), $decision)->assertUnprocessable();
        DB::table('membership_permissions')->insert(['membership_id' => $a->id, 'permission_id' => Permission::where('name', 'career.verify')->sole()->id, 'allowed' => false]);
        $this->post(route('career.verifications.decision', $v), $decision)->assertForbidden();
        $this->assertSame('requested', $v->fresh()->status);
    }

    public function test_institutional_showcase_requires_exact_sharing_approval_and_disappears_when_revoked(): void
    {
        $i = Institution::factory()->create();
        $s = $this->member($i);
        $a = $this->member($i, 'Institution Administrator');
        $p = $this->profile($s->user);
        $project = Project::factory()->create(['owner_id' => $s->user_id, 'institution_id' => $i->id, 'title' => 'Restricted academic prototype']);
        $showcase = ProjectShowcase::factory()->create(['professional_profile_id' => $p->id, 'project_id' => $project->id]);
        $portfolio = $this->portfolio($p, ['sections' => ['project'], 'showcase_ids' => [$showcase->id]]);
        $this->publish($portfolio);
        $this->get(route('portfolio.public', $portfolio->slug))->assertDontSee($project->title);
        $this->actingAs($s->user)->post(route('career.verifications.request'), ['institution_id' => $i->id, 'showcase_id' => $showcase->id, 'category' => 'academic_project', 'evidence' => 'Approved academic work', 'public_allowed' => 1, 'confirm' => 1])->assertRedirect();
        $v = AchievementVerification::sole();
        $this->actingAs($a->user)->post(route('career.verifications.decision', $v), ['status' => 'verified', 'reason' => 'Approved exact publication', 'evidence_reviewed' => 'Project reviewed', 'public_allowed' => 1])->assertRedirect();
        $this->publish($portfolio->fresh());
        $this->get(route('portfolio.public', $portfolio->slug))->assertSee($project->title)->assertSee('Project creator');
        $project->update(['description' => 'New confidential material']);
        $this->get(route('portfolio.public', $portfolio->slug))->assertDontSee($project->title)->assertDontSee('New confidential material');
        $project->update(['description' => $v->fresh()->approved_content['description']]);
        $this->actingAs($a->user)->post(route('career.verifications.decision', $v), ['status' => 'revoked', 'reason' => 'Sharing revoked', 'evidence_reviewed' => 'Revocation evidence'])->assertRedirect();
        $this->get(route('portfolio.public', $portfolio->slug))->assertDontSee($project->title);
        $this->assertNull(CareerAccess::publicProject($showcase->fresh()));
    }

    public function test_personal_showcase_media_is_private_until_explicit_approval_and_publication(): void
    {
        Storage::fake('local');
        $p = $this->profile();
        $project = Project::factory()->create(['owner_id' => $p->user_id]);
        $showcase = ProjectShowcase::factory()->create(['professional_profile_id' => $p->id, 'project_id' => $project->id]);
        $this->actingAs($p->user)->post(route('career.media.store', $showcase), ['image' => UploadedFile::fake()->image('screen.png'), 'alt' => 'Accessible project screenshot'])->assertRedirect();
        $media = PortfolioMedia::sole();
        $portfolio = $this->portfolio($p, ['sections' => ['project'], 'showcase_ids' => [$showcase->id]]);
        $this->publish($portfolio);
        $this->get(route('portfolio.media', [$portfolio->slug, $media]))->assertNotFound();
        $this->post(route('career.media.action', $media), ['action' => 'approve'])->assertRedirect();
        $this->get(route('portfolio.media', [$portfolio->slug, $media]))->assertNotFound();
        $this->publish($portfolio->fresh());
        $this->get(route('portfolio.media', [$portfolio->slug, $media]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post(route('career.media.action', $media), ['action' => 'revoke'])->assertRedirect();
        $this->get(route('portfolio.media', [$portfolio->slug, $media]))->assertNotFound();
    }

    public function test_application_career_materials_are_explicit_owned_encrypted_and_immutable(): void
    {
        $i = Institution::factory()->create();
        $s = $this->member($i);
        $p = $this->profile($s->user);
        $cv = CvDocument::factory()->create(['user_id' => $s->user_id, 'sections' => ['summary'], 'content' => ['summary' => 'Immutable CV original', 'experience' => 'Excluded private CV experience']]);
        $opportunity = Opportunity::factory()->create();
        $data = ['institution_id' => $i->id, 'motivation' => 'Real application', 'career' => ['cv_id' => $cv->id, 'profile_fields' => ['headline']]];
        $this->actingAs($s->user)->post(route('opportunity-applications.store', $opportunity), $data)->assertRedirect();
        $app = OpportunityApplication::sole();
        $this->assertArrayNotHasKey('professional_email', $app->career_selection['profile']);
        $this->post(route('opportunity-applications.action', $app), ['action' => 'submit', 'confirm_share' => 1])->assertRedirect();
        $cv->update(['content' => ['summary' => 'Later private changes']]);
        $payload = json_decode(Crypt::decryptString(DB::table('application_snapshots')->sole()->payload), true);
        $this->assertSame('Immutable CV original', $payload['career_materials']['cv']['content']['summary']);
        $this->assertArrayNotHasKey('experience', $payload['career_materials']['cv']['content']);
        $this->assertArrayNotHasKey('experience', $app->career_selection['cv']['content']);
        $this->assertStringNotContainsString('Immutable CV original', DB::table('opportunity_applications')->sole()->career_selection);
        $this->get(route('career.application-cv', [$app, 1]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs(User::factory()->create())->get(route('career.application-cv', [$app, 1]))->assertForbidden();
        $otherCv = CvDocument::factory()->create();
        $otherOpportunity = Opportunity::factory()->create();
        $this->actingAs($s->user)->post(route('opportunity-applications.store', $otherOpportunity), ['career' => ['cv_id' => $otherCv->id]] + $data)->assertNotFound();
    }

    public function test_no_career_information_is_shared_when_not_selected_and_restricted_showcases_are_rejected(): void
    {
        $i = Institution::factory()->create();
        $s = $this->member($i);
        $p = $this->profile($s->user);
        CvDocument::factory()->create(['user_id' => $s->user_id]);
        $project = Project::factory()->create(['institution_id' => $i->id, 'owner_id' => $s->user_id]);
        $showcase = ProjectShowcase::factory()->create(['professional_profile_id' => $p->id, 'project_id' => $project->id]);
        $o = Opportunity::factory()->create();
        $this->actingAs($s->user)->post(route('opportunity-applications.store', $o), ['institution_id' => $i->id, 'motivation' => 'Only this text'])->assertRedirect();
        $app = OpportunityApplication::sole();
        $this->assertNull($app->career_selection);
        $this->post(route('opportunity-applications.update', $app), ['institution_id' => $i->id, 'motivation' => 'Only this text', 'career' => ['showcase_ids' => [$showcase->id]]])->assertStatus(405);
        $this->put(route('opportunity-applications.update', $app), ['institution_id' => $i->id, 'motivation' => 'Only this text', 'career' => ['showcase_ids' => [$showcase->id]]])->assertUnprocessable();
    }

    public function test_real_analytics_are_scoped_and_institution_reports_never_disclose_private_content(): void
    {
        $i = Institution::factory()->create();
        $s = $this->member($i);
        $a = $this->member($i, 'Institution Administrator');
        $otherInstitution = Institution::factory()->create();
        $other = $this->member($otherInstitution);
        $p = $this->profile($s->user);
        $op = $this->profile($other->user);
        ProfessionalEntry::factory()->count(2)->create(['professional_profile_id' => $p->id, 'type' => 'skill', 'category' => 'Technical']);
        ProfessionalEntry::factory()->create(['professional_profile_id' => $op->id, 'type' => 'skill', 'category' => 'Other']);
        CvDocument::factory()->create(['user_id' => $s->user_id, 'content' => ['summary' => 'CONFIDENTIAL CV BODY']]);
        $filters = ['from' => today()->subDay()->toDateString(), 'to' => today()->toDateString(), 'group' => 'daily'];
        $this->actingAs($s->user);
        $data = app(CareerAnalytics::class)->data($p, $filters);
        $this->assertSame(2, array_sum($data['charts'][0]['values']));
        $this->get(route('career.index', ['institution_id' => $i->id]))->assertForbidden();
        $this->actingAs($a->user);
        $data = app(CareerAnalytics::class)->data($p, $filters, $i);
        $this->assertSame(1, $data['kpis']['Student profiles']);
        $this->assertSame(1, $data['kpis']['Students with CVs']);
        $this->get(route('career.index', ['institution_id' => $i->id]))->assertOk()->assertDontSee('CONFIDENTIAL CV BODY')->assertDontSee($p->professional_email);
        $this->get(route('career.analytics.csv', ['chart' => 'profile-adoption', 'institution_id' => $i->id] + $filters))->assertOk();
        $this->get(route('career.analytics.csv', ['chart' => 'profile-adoption', 'institution_id' => $otherInstitution->id] + $filters))->assertForbidden();
        $this->get(route('career.cvs.preview', CvDocument::sole()))->assertForbidden();
    }

    public function test_filters_empty_data_malformed_requests_and_search_do_not_invent_information(): void
    {
        $p = $this->profile();
        $this->actingAs($p->user)->get(route('career.index', ['from' => '2030-01-01', 'to' => '2020-01-01']))->assertSessionHasErrors('from');
        $this->get(route('career.index'))->assertOk()->assertSee('No matching data in this period.');
        $this->post(route('career.cvs.store'), $this->cvData(['sections' => [['malformed']], 'section_order' => ['summary' => []]]))->assertSessionHasErrors();
        $this->post(route('career.entries.save'), ['type' => ['bad'], 'title' => 'Bad', 'content' => ['description' => 'test']])->assertSessionHasErrors();
        $this->get(route('career.index', ['tab' => 'skill', 'q' => 'No invented skill']))->assertOk()->assertDontSee('data-chart="fake');
        $this->assertSame(0, ProfessionalEntry::count());
    }

    public function test_verification_evidence_is_frozen_and_renewal_requires_a_new_public_reference(): void
    {
        Storage::fake('local');
        $i = Institution::factory()->create();
        $s = $this->member($i);
        $a = $this->member($i, 'Institution Administrator');
        $p = $this->profile($s->user);
        $this->actingAs($s->user)->post(route('career.entries.save'), ['type' => 'certification', 'title' => 'Evidence-backed certificate', 'content' => ['organisation' => 'Issuer'], 'evidence' => UploadedFile::fake()->create('certificate.pdf', 10, 'application/pdf')])->assertRedirect();
        $entry = ProfessionalEntry::sole();
        $v = $this->verifyEntry($entry, $s, $a);
        $originalReference = $v->reference;
        $frozenPath = $v->evidence['file_path'];
        $portfolio = $this->portfolio($p, ['sections' => ['certification'], 'entry_ids' => [$entry->id]]);
        $this->publish($portfolio);
        $this->get(route('verification.public', $originalReference))->assertOk();
        $this->actingAs($s->user)->post(route('career.entries.save', $entry), ['type' => 'certification', 'title' => 'New private certificate title', 'content' => ['organisation' => 'Issuer'], 'evidence' => UploadedFile::fake()->create('replacement.pdf', 12, 'application/pdf')])->assertRedirect();
        Storage::disk('local')->assertExists($frozenPath);
        $this->post(route('career.verifications.request'), ['institution_id' => $i->id, 'entry_id' => $entry->id, 'category' => 'certificate', 'evidence' => 'New private evidence', 'public_allowed' => 1, 'confirm' => 1])->assertRedirect();
        $this->assertSame(1, AchievementVerification::count());
        $this->assertNotSame($originalReference, $v->fresh()->reference);
        $this->assertSame('requested', $v->fresh()->status);
        $this->get(route('verification.public', $originalReference))->assertNotFound();
        $this->get(route('verification.public', $v->fresh()->reference))->assertNotFound();
        $count = $a->user->notifications()->count();
        $this->post(route('career.verifications.request'), ['institution_id' => $i->id, 'entry_id' => $entry->id, 'category' => 'certificate', 'evidence' => 'Duplicate', 'confirm' => 1])->assertUnprocessable();
        $this->assertSame($count, $a->user->notifications()->count());
        $this->assertSame(2, DB::table('verification_decisions')->count());
    }

    public function test_delegated_lecturer_reviews_only_currently_assigned_projects_and_cannot_self_verify(): void
    {
        $i = Institution::factory()->create();
        $s = $this->member($i);
        $lecturer = $this->member($i, 'Lecturer / Teacher');
        $p = $this->profile($s->user);
        foreach (['career.verify', 'projects.supervise'] as $permission) {
            DB::table('membership_permissions')->insert(['membership_id' => $lecturer->id, 'permission_id' => Permission::where('name', $permission)->sole()->id, 'allowed' => true]);
        }
        $project = Project::factory()->create(['owner_id' => $s->user_id, 'institution_id' => $i->id]);
        $showcase = ProjectShowcase::factory()->create(['professional_profile_id' => $p->id, 'project_id' => $project->id]);
        $this->actingAs($s->user)->post(route('career.verifications.request'), ['institution_id' => $i->id, 'showcase_id' => $showcase->id, 'category' => 'participation', 'evidence' => 'Project participation evidence', 'confirm' => 1])->assertRedirect();
        $v = AchievementVerification::sole();
        $decision = ['status' => 'verified', 'reason' => 'Confirmed participation', 'evidence_reviewed' => 'Assigned supervision records'];
        $this->actingAs($lecturer->user)->post(route('career.verifications.decision', $v), $decision)->assertForbidden();
        $project->members()->create(['user_id' => $lecturer->user_id, 'institution_id' => $i->id, 'membership_id' => $lecturer->id, 'role' => 'supervisor']);
        $this->get(route('career.verifications'))->assertOk()->assertSee($v->title);
        $this->post(route('career.verifications.decision', $v), $decision)->assertRedirect();
        $project->members()->delete();
        $this->post(route('career.verifications.decision', $v), ['status' => 'revoked'] + $decision)->assertForbidden();
        $this->assertSame('verified', $v->fresh()->status);
    }

    public function test_changing_selected_cv_content_resets_group_consent_and_preserves_original_submissions(): void
    {
        $i = Institution::factory()->create();
        $leader = $this->member($i);
        $other = $this->member($i);
        $this->profile($leader->user);
        $group = ProjectGroup::factory()->create(['institution_id' => $i->id, 'status' => 'active']);
        foreach ([$leader, $other] as $m) {
            $group->members()->create(['institution_id' => $i->id, 'membership_id' => $m->id, 'role' => $m->id === $leader->id ? 'leader' : 'member', 'delegated_permissions' => []]);
        }
        $cv = CvDocument::factory()->create(['user_id' => $leader->user_id, 'content' => ['summary' => 'Consented version']]);
        $o = Opportunity::factory()->create(['allow_groups' => true]);
        $data = ['institution_id' => $i->id, 'group_id' => $group->id, 'participant_ids' => [$leader->user_id, $other->user_id], 'motivation' => 'Group application', 'career' => ['cv_id' => $cv->id]];
        $this->actingAs($leader->user)->post(route('opportunity-applications.store', $o), $data)->assertRedirect();
        $app = OpportunityApplication::sole();
        $this->actingAs($other->user)->post(route('opportunity-applications.action', $app), ['action' => 'consent', 'consent' => 1])->assertRedirect();
        $this->actingAs($leader->user)->put(route('opportunity-applications.update', $app), $data)->assertRedirect();
        $this->assertNotNull(DB::table('opportunity_consents')->where('application_id', $app->id)->where('user_id', $other->user_id)->value('consented_at'));
        $cv->update(['content' => ['summary' => 'Changed shared content']]);
        $this->put(route('opportunity-applications.update', $app), $data)->assertRedirect();
        $this->assertNull(DB::table('opportunity_consents')->where('application_id', $app->id)->where('user_id', $other->user_id)->value('consented_at'));
        $this->post(route('opportunity-applications.action', $app), ['action' => 'submit', 'confirm_share' => 1])->assertSessionHasErrors('consent');
        $this->actingAs($other->user)->get(route('opportunity-applications.show', $app))->assertOk()->assertSee('Changed shared content');
        $this->post(route('opportunity-applications.action', $app), ['action' => 'consent', 'consent' => 1])->assertRedirect();
        $this->actingAs($leader->user)->post(route('opportunity-applications.action', $app), ['action' => 'submit', 'confirm_share' => 1])->assertRedirect();
        $cv->delete();
        $this->get(route('career.application-cv', [$app, 1]))->assertOk();
    }

    public function test_revoked_achievement_cannot_be_submitted_as_currently_verified_material(): void
    {
        $i = Institution::factory()->create();
        $s = $this->member($i);
        $a = $this->member($i, 'Institution Administrator');
        $p = $this->profile($s->user);
        $entry = ProfessionalEntry::factory()->create(['professional_profile_id' => $p->id, 'type' => 'achievement']);
        $v = $this->verifyEntry($entry, $s, $a);
        $o = Opportunity::factory()->create();
        $this->actingAs($s->user)->post(route('opportunity-applications.store', $o), ['institution_id' => $i->id, 'motivation' => 'Evidence-based application', 'career' => ['achievement_ids' => [$v->id]]])->assertRedirect();
        $app = OpportunityApplication::sole();
        $v->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->post(route('opportunity-applications.action', $app), ['action' => 'submit', 'confirm_share' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('application_snapshots', 0);
    }

    public function test_public_assets_and_projects_disappear_when_institution_membership_is_suspended(): void
    {
        Storage::fake('local');
        $i = Institution::factory()->create();
        $s = $this->member($i);
        $a = $this->member($i, 'Institution Administrator');
        $p = $this->profile($s->user);
        $project = Project::factory()->create(['owner_id' => $s->user_id, 'institution_id' => $i->id, 'title' => 'Controlled institutional project']);
        $showcase = ProjectShowcase::factory()->create(['professional_profile_id' => $p->id, 'project_id' => $project->id]);
        $this->actingAs($s->user)->post(route('career.verifications.request'), ['institution_id' => $i->id, 'showcase_id' => $showcase->id, 'category' => 'academic_project', 'evidence' => 'Authorised publication content', 'public_allowed' => 1, 'confirm' => 1])->assertRedirect();
        $v = AchievementVerification::sole();
        $this->actingAs($a->user)->post(route('career.verifications.decision', $v), ['status' => 'verified', 'reason' => 'Approved public content', 'evidence_reviewed' => 'Review record', 'public_allowed' => 1])->assertRedirect();
        $image = UploadedFile::fake()->image('approved.png')->store('portfolio-media', 'local');
        $media = PortfolioMedia::factory()->create(['project_showcase_id' => $showcase->id, 'path' => $image, 'public_approved' => true, 'approved_by' => $a->user_id]);
        $portfolio = $this->portfolio($p, ['sections' => ['project'], 'showcase_ids' => [$showcase->id]]);
        $this->publish($portfolio);
        $this->get(route('portfolio.public', $portfolio->slug))->assertSee($project->title);
        $this->get(route('career.media.private', $media))->assertOk();
        $this->get(route('portfolio.media', [$portfolio->slug, $media]))->assertOk();
        $this->get(route('verification.public', $v->reference))->assertOk();
        $s->update(['status' => 'suspended']);
        $this->get(route('portfolio.public', $portfolio->slug))->assertDontSee($project->title);
        $this->get(route('career.media.private', $media))->assertForbidden();
        $this->get(route('portfolio.media', [$portfolio->slug, $media]))->assertNotFound();
        $this->get(route('verification.public', $v->reference))->assertNotFound();
        $p->user->forceFill(['status' => 'suspended'])->save();
        auth()->logout();
        $this->get(route('portfolio.public', $portfolio->slug))->assertNotFound();
    }

    public function test_long_cv_pdf_has_multiple_a4_pages_and_private_routes_are_noindex(): void
    {
        $p = $this->profile();
        $cv = CvDocument::factory()->create(['user_id' => $p->user_id, 'content' => ['contact' => "Professional name\nhttps://github.com/example", 'summary' => str_repeat("A substantial professional achievement with useful detail.\n", 140)]]);
        $this->actingAs($p->user);
        $response = $this->get(route('career.cvs.preview', [$cv, 'pdf' => 1]))->assertOk();
        $pdf = $response->getContent();
        $this->assertSame(1, preg_match('~/Count [2-9][0-9]*\s~', $pdf), 'Long CVs must paginate across multiple pages.');
        $this->assertStringContainsString('595.280 841.890', $pdf);
        $this->get(route('career.cvs.edit', $cv))->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function contactPayload(Portfolio $portfolio, array $extra = []): array
    {
        return $extra + ['sender_name' => 'Professional visitor', 'sender_email' => 'visitor@example.test', 'message' => 'A relevant professional project enquiry.', 'consent' => 1,
            'form_token' => Crypt::encryptString(json_encode(['portfolio_id' => $portfolio->id, 'issued_at' => now()->subSeconds(3)->timestamp, 'nonce' => Str::random(32)]))];
    }

    public function test_opt_in_contact_messages_are_encrypted_private_deduplicated_and_owner_managed(): void
    {
        $profile = $this->profile();
        $portfolio = $this->portfolio($profile, ['contact_form_enabled' => true]);
        $this->publish($portfolio);
        auth()->logout();
        $this->get(route('portfolio.public', $portfolio->slug))->assertOk()->assertSee('Send professional enquiry')->assertDontSee($profile->user->email);
        $payload = $this->contactPayload($portfolio);
        $this->post(route('portfolio.contact', $portfolio->slug), $payload)->assertRedirect(route('portfolio.public', $portfolio->slug));
        $this->post(route('portfolio.contact', $portfolio->slug), $payload)->assertRedirect();
        $this->assertSame(1, PortfolioMessage::count());
        $message = PortfolioMessage::sole();
        $this->assertStringNotContainsString('visitor@example.test', DB::table('portfolio_messages')->sole()->sender_email);
        $this->assertStringNotContainsString('professional project enquiry', DB::table('portfolio_messages')->sole()->message);
        $this->assertSame(2, $profile->user->notifications()->count());
        $this->actingAs(User::factory()->create())->post(route('career.portfolio.message', $message), ['action' => 'read', 'confirm' => 1])->assertForbidden();
        $this->get(route('career.portfolio'))->assertOk()->assertDontSee('A relevant professional project enquiry.');
        $this->actingAs($profile->user)->get(route('career.portfolio'))->assertOk()->assertSee('A relevant professional project enquiry.');
        $this->post(route('career.portfolio.message', $message), ['action' => 'read', 'confirm' => 1])->assertRedirect(route('career.portfolio'));
        $this->assertNotNull($message->fresh()->read_at);
        $this->post(route('career.portfolio.message', $message), ['action' => 'delete', 'confirm' => 1])->assertRedirect();
        $this->assertDatabaseCount('portfolio_messages', 0);
    }

    public function test_contact_form_rejects_disabled_spam_tampered_expired_and_rate_limited_requests(): void
    {
        $profile = $this->profile();
        $portfolio = $this->portfolio($profile);
        $this->publish($portfolio);
        auth()->logout();
        $this->post(route('portfolio.contact', $portfolio->slug), $this->contactPayload($portfolio))->assertNotFound();
        $portfolio->update(['contact_form_enabled' => true]);
        $this->post(route('portfolio.contact', $portfolio->slug), $this->contactPayload($portfolio, ['company' => 'spam']))->assertSessionHasErrors('company');
        $this->post(route('portfolio.contact', $portfolio->slug), $this->contactPayload($portfolio, ['form_token' => 'tampered']))->assertSessionHasErrors('form_token');
        $expired = Crypt::encryptString(json_encode(['portfolio_id' => $portfolio->id, 'issued_at' => now()->subHours(3)->timestamp, 'nonce' => Str::random(32)]));
        $this->post(route('portfolio.contact', $portfolio->slug), $this->contactPayload($portfolio, ['form_token' => $expired]))->assertSessionHasErrors('form_token');
        $foreign = Crypt::encryptString(json_encode(['portfolio_id' => $portfolio->id + 1, 'issued_at' => now()->subSeconds(3)->timestamp, 'nonce' => Str::random(32)]));
        $this->post(route('portfolio.contact', $portfolio->slug), $this->contactPayload($portfolio, ['form_token' => $foreign]))->assertSessionHasErrors('form_token');
        $this->post(route('portfolio.contact', $portfolio->slug), $this->contactPayload($portfolio))->assertTooManyRequests();
        $this->assertDatabaseCount('portfolio_messages', 0);
    }

    public function test_contact_form_is_not_available_for_unpublished_or_suspended_owners(): void
    {
        $profile = $this->profile();
        $portfolio = $this->portfolio($profile, ['contact_form_enabled' => true]);
        $this->post(route('portfolio.contact', $portfolio->slug), $this->contactPayload($portfolio))->assertNotFound();
        $this->publish($portfolio);
        $profile->user->forceFill(['status' => 'suspended'])->save();
        auth()->logout();
        $this->post(route('portfolio.contact', $portfolio->slug), $this->contactPayload($portfolio))->assertNotFound();
        $this->assertDatabaseCount('portfolio_messages', 0);
    }
}
