<?php

namespace Tests\Feature;

use App\Models\AcademicClass;
use App\Models\AiUsage;
use App\Models\CvDocument;
use App\Models\Deliverable;
use App\Models\Idea;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\OpportunityApplication;
use App\Models\ProfessionalProfile;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\User;
use App\Support\IdeaWorkflow;
use App\Support\IntelligenceAccess;
use App\Support\IntelligenceFilters;
use App\Support\IntelligenceMetrics;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InstitutionalIntelligenceTest extends TestCase
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

    private function data(User $u, Institution $i, array $extra = []): array
    {
        $f = app(IntelligenceFilters::class)->normalise($u, $extra + ['institution_id' => $i->id, 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()]);

        return app(IntelligenceMetrics::class)->data(app(IntelligenceAccess::class)->context($u, $f), $f);
    }

    public function test_empty_institution_metrics_are_real_empty_values_and_safe_ratios(): void
    {
        $i = Institution::factory()->create();
        $admin = $this->member($i, 'Institution Administrator');
        $d = $this->data($admin->user, $i);
        $this->assertSame(0, $d['metrics']['projects']['value']);
        $this->assertNull($d['metrics']['task_rate']['value']);
        $this->assertNull($d['metrics']['idea_conversion']['value']);
        $this->assertSame(0, array_sum($d['charts'][0]['values']));
    }

    public function test_project_participants_are_deduplicated_and_personal_and_other_tenants_excluded(): void
    {
        config(['intelligence.small_group' => 1]);
        $i = Institution::factory()->create();
        $admin = $this->member($i, 'Institution Administrator');
        $student = $this->member($i);
        $p = Project::factory()->create(['institution_id' => $i->id, 'owner_id' => $student->user_id]);
        $p->members()->create(['user_id' => $student->user_id, 'institution_id' => $i->id, 'membership_id' => $student->id, 'role' => 'member']);
        Project::factory()->create(['institution_id' => $i->id, 'owner_id' => $student->user_id]);
        Project::factory()->create(['institution_id' => null, 'owner_id' => $student->user_id]);
        Project::factory()->create(['institution_id' => Institution::factory()]);
        $d = $this->data($admin->user, $i);
        $this->assertSame(2, $d['metrics']['projects']['value']);
        $this->assertSame(1, $d['metrics']['participating_students']['value']);
        $this->assertSame(100.0, $d['metrics']['student_participation_rate']['value']);
    }

    public function test_small_student_counts_are_suppressed_in_metrics_and_exports_payloads(): void
    {
        $i = Institution::factory()->create();
        $admin = $this->member($i, 'Institution Administrator');
        $s = $this->member($i);
        Project::factory()->create(['institution_id' => $i->id, 'owner_id' => $s->user_id]);
        $d = $this->data($admin->user, $i);
        $this->assertNull($d['metrics']['students']['value']);
        $this->assertNull($d['metrics']['participating_students']['value']);
        $this->assertNull($d['metrics']['student_participation_rate']['value']);
        $this->assertStringNotContainsString($s->user->email, json_encode($d));
    }

    public function test_foreign_institution_and_unassigned_staff_scopes_are_rejected(): void
    {
        $i = Institution::factory()->create();
        $admin = $this->member($i, 'Institution Administrator');
        $other = Institution::factory()->create();
        $this->expectException(HttpException::class);
        $this->data($admin->user, $other);
    }

    public function test_lecturer_is_limited_to_assigned_classes_and_academic_filters(): void
    {
        config(['intelligence.small_group' => 1]);
        $i = Institution::factory()->create();
        $lecturer = $this->member($i, 'Lecturer / Teacher');
        $s = $this->member($i);
        $a = AcademicClass::factory()->create(['institution_id' => $i->id]);
        $b = AcademicClass::factory()->create(['institution_id' => $i->id]);
        DB::table('class_assignments')->insert(['institution_id' => $i->id, 'academic_class_id' => $a->id, 'membership_id' => $lecturer->id, 'assignment' => 'lecturer', 'created_at' => now(), 'updated_at' => now()]);
        Project::factory()->create(['institution_id' => $i->id, 'academic_class_id' => $a->id, 'owner_id' => $s->user_id]);
        Project::factory()->create(['institution_id' => $i->id, 'academic_class_id' => $b->id, 'owner_id' => $s->user_id]);
        $this->assertSame(1, $this->data($lecturer->user, $i)['metrics']['projects']['value']);
    }

    public function test_mature_idea_conversion_excludes_recent_ideas_and_previous_periods_are_equal(): void
    {
        $i = Institution::factory()->create();
        $a = $this->member($i, 'Institution Administrator');
        $old = Idea::factory()->create(['institution_id' => $i->id, 'created_at' => '2026-08-01 00:00:00']);
        Idea::factory()->create(['institution_id' => $i->id, 'created_at' => '2026-09-29 00:00:00']);
        Project::factory()->create(['institution_id' => $i->id, 'idea_id' => $old->id, 'created_at' => '2026-09-10 00:00:00']);
        $d = $this->data($a->user, $i, ['from' => '2026-08-01', 'to' => '2026-09-30', 'cohort_days' => 30]);
        $this->assertSame(100.0, $d['metrics']['idea_conversion']['value']);
        $this->assertSame(1, $d['coverage']['mature_ideas']);
        $previous = app(IntelligenceFilters::class)->previous(['from' => '2026-10-01', 'to' => '2026-10-07']);
        $this->assertSame('2026-09-24', $previous['from']);
        $this->assertSame('2026-09-30', $previous['to']);
    }

    public function test_project_task_milestone_deliverable_rates_and_missing_completion_quality(): void
    {
        $i = Institution::factory()->create();
        $a = $this->member($i, 'Institution Administrator');
        $p = Project::factory()->create(['institution_id' => $i->id, 'status' => 'completed', 'completed_at' => null]);
        ProjectTask::factory()->create(['project_id' => $p->id, 'status' => 'completed']);
        ProjectTask::factory()->create(['project_id' => $p->id, 'status' => 'to_do']);
        Milestone::factory()->create(['project_id' => $p->id, 'status' => 'completed']);
        Milestone::factory()->create(['project_id' => $p->id, 'status' => 'not_started', 'due_at' => today()->subDay()]);
        Deliverable::factory()->create(['project_id' => $p->id, 'status' => 'approved']);
        Deliverable::factory()->create(['project_id' => $p->id, 'status' => 'submitted']);
        $d = $this->data($a->user, $i);
        foreach (['task_rate', 'milestone_rate', 'deliverable_rate'] as $key) {
            $this->assertSame(50.0, $d['metrics'][$key]['value']);
        }
        $this->assertSame(1, $d['metrics']['overdue_milestones']['value']);
        $this->assertSame(1, $d['metrics']['awaiting_review']['value']);
        $this->assertNull($d['metrics']['completion_days']['value']);
        $this->assertSame(1, collect($d['quality'])->firstWhere('key', 'completion_time')['count']);
    }

    public function test_internal_outcomes_external_reports_and_private_career_ai_metadata_are_separate(): void
    {
        config(['intelligence.small_group' => 1]);
        $i = Institution::factory()->create();
        $a = $this->member($i, 'Institution Administrator');
        $s = $this->member($i);
        OpportunityApplication::factory()->create(['institution_id' => $i->id, 'applicant_id' => $s->user_id, 'status' => 'accepted', 'submitted_at' => now(), 'external_self_reported' => false]);
        OpportunityApplication::factory()->create(['institution_id' => $i->id, 'applicant_id' => $s->user_id, 'status' => 'accepted', 'submitted_at' => now(), 'external_self_reported' => true]);
        $profile = ProfessionalProfile::factory()->create(['user_id' => $s->user_id, 'biography' => 'PRIVATE BIOGRAPHY MUST NEVER APPEAR']);
        CvDocument::factory()->create(['user_id' => $s->user_id]);
        AiUsage::factory()->create(['user_id' => $s->user_id, 'institution_id' => $i->id, 'status' => 'success']);
        AiUsage::factory()->create(['user_id' => $s->user_id, 'institution_id' => null, 'status' => 'success']);
        $d = $this->data($a->user, $i, ['section' => 'opportunities']);
        $this->assertSame(1, $d['metrics']['internal_applications']['value']);
        $this->assertSame(1, $d['metrics']['accepted_applications']['value']);
        $this->assertSame(1, $d['metrics']['external_reports']['value']);
        $this->assertSame(1, $d['metrics']['profiles']['value']);
        $this->assertSame(1, $d['metrics']['cvs']['value']);
        $this->assertSame(1, $d['metrics']['ai_requests']['value']);
        $this->assertSame(150, $d['metrics']['ai_tokens']['value']);
        $this->assertStringNotContainsString('PRIVATE BIOGRAPHY', json_encode($d));
        $this->assertSame(1, array_sum(collect($d['charts'])->firstWhere('key', 'applications-category')['values']));
    }

    public function test_dashboard_csv_and_academic_tenant_filters_are_enforced_server_side(): void
    {
        $i = Institution::factory()->create();
        $a = $this->member($i, 'Institution Administrator');
        $other = Institution::factory()->create();
        $foreign = AcademicClass::factory()->create(['institution_id' => $other->id]);
        $f = ['institution_id' => $i->id, 'from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString()];
        $this->actingAs($a->user)->get(route('intelligence.index', $f))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get(route('intelligence.csv', $f + ['chart' => 'project-creation']))->assertOk()->assertDownload();
        $this->getJson(route('intelligence.index', $f + ['academic_class_id' => $foreign->id]))->assertUnprocessable();
        $s = $this->member($i);
        $this->actingAs($s->user)->get(route('intelligence.csv', $f + ['chart' => 'project-creation']))->assertForbidden();
    }

    public function test_new_idea_submissions_capture_first_event_without_backdating_legacy_records(): void
    {
        $i = Institution::factory()->create();
        $admin = $this->member($i, 'Institution Administrator');
        $student = $this->member($i);
        $idea = Idea::factory()->create(['institution_id' => $i->id, 'owner_id' => $student->user_id]);
        $this->actingAs($student->user);
        app(IdeaWorkflow::class)->submit($idea, null);
        $first = $idea->fresh()->first_submitted_at->toIso8601String();
        $this->travel(1)->days();
        $idea->update(['status' => 'returned']);
        app(IdeaWorkflow::class)->submit($idea, null);
        $this->assertSame($first, $idea->fresh()->first_submitted_at->toIso8601String());
        $this->travelBack();
        $legacy = Idea::factory()->create(['institution_id' => $i->id, 'status' => 'submitted']);
        $d = $this->data($admin->user, $i);
        $this->assertSame(1, $d['metrics']['submitted_ideas']['value']);
        $this->assertSame(1, collect($d['quality'])->firstWhere('key', 'idea_submission')['count']);
        $this->assertNull($legacy->fresh()->first_submitted_at);
    }

    public function test_actual_completion_events_include_older_projects_and_timezone_boundaries(): void
    {
        $i = Institution::factory()->create();
        $admin = $this->member($i, 'Institution Administrator');
        Project::factory()->create(['institution_id' => $i->id, 'created_at' => '2026-08-01 00:00:00', 'starts_at' => '2026-08-01', 'status' => 'completed', 'completed_at' => '2026-09-30 20:30:00', 'target_at' => '2026-10-02']);
        $d = $this->data($admin->user, $i, ['from' => '2026-10-01', 'to' => '2026-10-01']);
        $this->assertSame(0, $d['metrics']['projects']['value']);
        $this->assertSame(1, $d['metrics']['completed_projects']['value']);
        $this->assertSame(100.0, $d['metrics']['on_time_completion_rate']['value']);
        $chart = collect($d['charts'])->firstWhere('key', 'project-completion');
        $this->assertSame(1, array_sum($chart['values']));
    }
}
