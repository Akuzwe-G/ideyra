<?php

namespace Tests\Feature;

use App\Models\AcademicClass;
use App\Models\Deliverable;
use App\Models\Idea;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\Submission;
use App\Models\User;
use App\Support\ProjectAnalytics;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InnovationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function member(Institution $institution, string $role = 'Student'): Membership
    {
        return Membership::factory()->create(['institution_id' => $institution->id, 'role_id' => Role::where('name', $role)->sole()->id]);
    }

    private function projectData(array $extra = []): array
    {
        return $extra + ['title' => 'Water monitoring studio', 'description' => 'A research prototype', 'problem' => 'Unsafe water', 'objectives' => 'Measure quality', 'outcomes' => 'A validated prototype', 'category' => 'Engineering', 'confirm_owner' => 1];
    }

    private function ideaData(array $extra = []): array
    {
        return $extra + ['title' => 'Water monitoring idea', 'description' => 'Research concept', 'problem' => 'Unsafe water', 'solution' => 'Sensors', 'category' => 'Engineering', 'visibility' => 'private', 'status' => 'draft'];
    }

    private function taskData(array $extra = []): array
    {
        return $extra + ['title' => 'Research sensors', 'priority' => 'high', 'status' => 'to_do', 'due_at' => today()->addWeek()->toDateString()];
    }

    public function test_personal_ideas_are_private_even_from_system_and_institution_administrators(): void
    {
        $idea = Idea::factory()->create();
        $admin = $this->member(Institution::factory()->create(), 'Institution Administrator')->user;
        $system = User::factory()->create();
        $system->roles()->attach(Role::where('name', 'System Administrator')->sole());
        foreach ([$admin, $system, User::factory()->create()] as $outsider) {
            $this->actingAs($outsider)->get(route('ideas.show', $idea))->assertNotFound();
            $this->get(route('ideas.index'))->assertOk()->assertDontSee($idea->title);
        }
        $this->actingAs($idea->owner)->get(route('ideas.show', $idea))->assertOk()->assertSee($idea->title);
    }

    public function test_idea_capture_edit_search_and_selected_sharing_require_consent(): void
    {
        $owner = User::factory()->create();
        $collaborator = User::factory()->create();
        $this->actingAs($owner)->post(route('ideas.store'), $this->ideaData())->assertRedirect();
        $idea = Idea::sole();
        $this->post(route('ideas.store'), $this->ideaData(['visibility' => 'shared', 'collaborator_emails' => $collaborator->email]))->assertUnprocessable();
        $this->put(route('ideas.update', $idea), $this->ideaData(['visibility' => 'shared', 'collaborator_emails' => $collaborator->email, 'confirm_share' => 1, 'tags_text' => 'water, sensors']))->assertRedirect();
        $this->actingAs($collaborator)->get(route('ideas.show', $idea))->assertOk();
        $this->put(route('ideas.update', $idea), $this->ideaData())->assertForbidden();
        $this->get(route('ideas.index', ['tag' => 'water', 'q' => 'monitoring']))->assertSee($idea->title);
    }

    public function test_review_copy_preserves_private_original_and_is_duplicate_proof(): void
    {
        $institution = Institution::factory()->create();
        $owner = $this->member($institution)->user;
        $idea = Idea::factory()->create(['owner_id' => $owner->id]);
        $data = ['action' => 'publish', 'institution_id' => $institution->id, 'confirm_share' => 1];
        $this->actingAs($owner)->post(route('ideas.action', $idea), $data)->assertRedirect();
        $this->post(route('ideas.action', $idea), $data)->assertRedirect();
        $this->assertDatabaseCount('ideas', 2);
        $this->assertSame('private', $idea->fresh()->visibility);
        $this->assertNull($idea->fresh()->institution_id);
        $copy = Idea::whereNotNull('source_idea_id')->sole();
        $this->assertSame($idea->description, $copy->description);
    }

    public function test_institutional_idea_review_requires_assignment_or_explicit_wider_scope_and_no_self_review(): void
    {
        $institution = Institution::factory()->create();
        $owner = $this->member($institution)->user;
        $reviewer = $this->member($institution, 'Lecturer / Teacher')->user;
        $other = $this->member($institution, 'Lecturer / Teacher')->user;
        $idea = Idea::factory()->create(['owner_id' => $owner->id, 'institution_id' => $institution->id]);
        $this->actingAs($owner)->post(route('ideas.action', $idea), ['action' => 'submit', 'reviewer_id' => $reviewer->id, 'confirm_share' => 1])->assertRedirect();
        $decision = ['action' => 'review', 'decision' => 'approved', 'feedback' => 'Ready for a prototype.'];
        $this->actingAs($other)->post(route('ideas.action', $idea), $decision)->assertForbidden();
        $this->actingAs($owner)->post(route('ideas.action', $idea), $decision)->assertForbidden();
        $this->actingAs($reviewer)->post(route('ideas.action', $idea), $decision)->assertRedirect();
        $this->assertSame('approved', $idea->fresh()->status);
        $this->assertDatabaseCount('idea_reviews', 1);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $owner->id]);
    }

    public function test_personal_conversion_is_duplicate_proof_and_ownership_cannot_be_transferred(): void
    {
        $idea = Idea::factory()->create();
        $this->actingAs($idea->owner)->post(route('projects.store'), $this->projectData(['idea_id' => $idea->id]))->assertRedirect();
        $project = Project::sole();
        $this->post(route('projects.store'), $this->projectData(['idea_id' => $idea->id]))->assertSessionHasErrors('idea_id');
        $this->assertDatabaseCount('projects', 1);
        $this->put(route('projects.update', $project), $this->projectData(['institution_id' => Institution::factory()->create()->id, 'idea_id' => $idea->id]))->assertUnprocessable();
        $this->assertNull($project->fresh()->institution_id);
    }

    public function test_institutional_creation_is_explicit_opt_in_and_requires_approved_institutional_origin(): void
    {
        $institution = Institution::factory()->create();
        $student = $this->member($institution);
        $this->actingAs($student->user)->post(route('projects.store'), $this->projectData(['institution_id' => $institution->id]))->assertForbidden();
        DB::table('membership_permissions')->insert(['membership_id' => $student->id, 'permission_id' => Permission::where('name', 'projects.create_institutional')->sole()->id, 'allowed' => true]);
        $idea = Idea::factory()->create(['owner_id' => $student->user_id, 'institution_id' => $institution->id, 'status' => 'draft']);
        $this->post(route('projects.store'), $this->projectData(['institution_id' => $institution->id, 'idea_id' => $idea->id]))->assertUnprocessable();
        $idea->update(['status' => 'approved']);
        $this->post(route('projects.store'), $this->projectData(['institution_id' => $institution->id, 'idea_id' => $idea->id]))->assertRedirect();
    }

    public function test_project_approval_return_revision_and_completion_are_explicit(): void
    {
        $institution = Institution::factory()->create();
        $owner = $this->member($institution, 'Institution Administrator')->user;
        $reviewer = $this->member($institution, 'Institution Administrator')->user;
        $project = Project::factory()->create(['owner_id' => $owner->id, 'institution_id' => $institution->id]);
        $this->actingAs($owner)->post(route('projects.action', $project), ['action' => 'transition', 'status' => 'pending_approval'])->assertRedirect();
        $decision = ['action' => 'review', 'decision' => 'returned', 'reason' => 'Clarify outcomes.'];
        $this->post(route('projects.action', $project), $decision)->assertForbidden();
        $this->actingAs($reviewer)->post(route('projects.action', $project), $decision)->assertRedirect();
        $this->assertSame('draft', $project->fresh()->status);
        $this->actingAs($owner)->post(route('projects.action', $project), ['action' => 'transition', 'status' => 'pending_approval'])->assertRedirect();
        $this->actingAs($reviewer)->post(route('projects.action', $project), ['action' => 'review', 'decision' => 'approved', 'reason' => 'Ready.'])->assertRedirect();
        $this->actingAs($owner)->post(route('projects.action', $project), ['action' => 'transition', 'status' => 'in_progress'])->assertRedirect();
        $this->actingAs($reviewer)->post(route('projects.action', $project), ['action' => 'transition', 'status' => 'completed', 'reason' => 'Validated.'])->assertRedirect();
        $this->assertNotNull($project->fresh()->completed_at);
        $this->assertDatabaseCount('project_decisions', 3);
    }

    public function test_group_context_supervision_leadership_and_cross_institution_validation(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution, 'Institution Administrator');
        $student = $this->member($institution);
        $supervisor = $this->member($institution, 'Lecturer / Teacher');
        $class = AcademicClass::factory()->create(['institution_id' => $institution->id]);
        DB::table('class_assignments')->insert(['institution_id' => $institution->id, 'membership_id' => $student->id, 'academic_class_id' => $class->id, 'assignment' => 'student', 'created_at' => now(), 'updated_at' => now()]);
        $data = ['institution_id' => $institution->id, 'academic_class_id' => $class->id, 'name' => 'Water research team', 'status' => 'active', 'member_ids' => [$student->id], 'leader_id' => $student->id, 'supervisor_ids' => [$supervisor->id], 'delegated_permissions' => ['projects.manage_assigned', 'tasks.create', 'tasks.assign']];
        $this->actingAs($admin->user)->post(route('groups.store'), $data)->assertRedirect();
        $group = ProjectGroup::sole();
        $this->assertSame('leader', $group->members()->sole()->role);
        $this->assertSame([$supervisor->id], $group->supervisors()->pluck('memberships.id')->all());
        $this->actingAs($student->user)->get(route('groups.show', $group))->assertOk();
        $outsider = $this->member(Institution::factory()->create());
        $this->actingAs($admin->user)->put(route('groups.update', $group), array_replace($data, ['member_ids' => [$outsider->id], 'leader_id' => $outsider->id]))->assertUnprocessable();
        $this->assertSame($student->id, $group->fresh()->members()->sole()->membership_id);
        $this->actingAs($outsider->user)->get(route('groups.show', $group))->assertNotFound();
    }

    public function test_group_leader_delegation_is_contextual_and_explicit_denials_win(): void
    {
        $institution = Institution::factory()->create();
        $leader = $this->member($institution);
        $group = ProjectGroup::factory()->create(['institution_id' => $institution->id, 'status' => 'active']);
        $group->members()->create(['institution_id' => $institution->id, 'membership_id' => $leader->id, 'role' => 'leader', 'delegated_permissions' => ['projects.manage_assigned', 'tasks.create', 'tasks.assign']]);
        $project = Project::factory()->create(['institution_id' => $institution->id, 'group_id' => $group->id, 'academic_class_id' => $group->academic_class_id]);
        $this->actingAs($leader->user)->post(route('work.task', $project), $this->taskData())->assertRedirect();
        DB::table('membership_permissions')->insert(['membership_id' => $leader->id, 'permission_id' => Permission::where('name', 'projects.manage_assigned')->sole()->id, 'allowed' => false]);
        $this->post(route('work.task', $project), $this->taskData())->assertForbidden();
        $this->get(route('system.dashboard'))->assertForbidden();
        $other = Project::factory()->create(['institution_id' => $institution->id]);
        $this->get(route('projects.show', $other))->assertNotFound();
    }

    public function test_assigned_task_progress_cannot_change_planning_fields_and_revoked_members_lose_access(): void
    {
        $project = Project::factory()->create();
        $member = User::factory()->create();
        $assignment = ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $member->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'created_by' => $project->owner_id]);
        $task->assignees()->attach($member, ['project_id' => $project->id]);
        $this->actingAs($member)->post(route('work.progress', [$project, $task]), ['status' => 'in_progress', 'comment' => 'Research started', 'title' => 'Tampered', 'priority' => 'urgent', 'owner_id' => $member->id])->assertRedirect();
        $this->assertNotSame('Tampered', $task->fresh()->title);
        $this->assertSame('medium', $task->fresh()->priority);
        $this->assertDatabaseCount('task_comments', 1);
        $this->post(route('work.task', [$project, $task]), $this->taskData())->assertForbidden();
        $assignment->delete();
        $this->get(route('work.task.show', [$project, $task]))->assertNotFound();
        $this->post(route('work.progress', [$project, $task]), ['status' => 'completed'])->assertNotFound();
    }

    public function test_task_milestone_and_assignee_contexts_are_checked(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $milestone = Milestone::factory()->create(['project_id' => $other->id]);
        $this->actingAs($project->owner)->post(route('work.task', $project), $this->taskData(['milestone_id' => $milestone->id]))->assertUnprocessable();
        $this->post(route('work.task', $project), $this->taskData(['assignee_ids' => [$other->owner_id]]))->assertUnprocessable();
        $this->post(route('work.task', $project), $this->taskData(['assignee_ids' => [$project->owner_id], 'due_at' => today()->toDateString()]))->assertRedirect();
        $this->assertDatabaseCount('project_tasks', 1);
        $task = ProjectTask::sole();
        $this->get(route('work.task.show', [$other, $task]))->assertNotFound();
        $this->post(route('work.milestone', $project), ['title' => 'Research', 'due_at' => today()->toDateString(), 'status' => 'completed', 'sort_order' => 2])->assertRedirect();
        $this->assertNotNull(Milestone::where('project_id', $project->id)->sole()->completed_at);
    }

    public function test_private_submission_versions_reviews_and_secure_downloads(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $student = User::factory()->create();
        ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $student->id]);
        $deliverable = Deliverable::factory()->create(['project_id' => $project->id, 'assigned_user_id' => $student->id]);
        $this->actingAs($student)->post(route('work.submit', [$project, $deliverable]), ['body' => 'Version one', 'file' => UploadedFile::fake()->create('evidence.pdf', 20, 'application/pdf')])->assertRedirect();
        $first = Submission::sole();
        Storage::disk('local')->assertExists($first->file_path);
        $this->post(route('work.submit', [$project, $deliverable]), ['body' => 'Duplicate'])->assertUnprocessable();
        $this->post(route('work.review', [$project, $deliverable]), ['decision' => 'approved', 'feedback' => 'My own work'])->assertForbidden();
        $this->actingAs($project->owner)->post(route('work.review', [$project, $deliverable]), ['decision' => 'changes_requested', 'feedback' => 'Add evidence.'])->assertRedirect();
        $this->actingAs($student)->post(route('work.submit', [$project, $deliverable]), ['body' => 'Version two'])->assertRedirect();
        $this->assertSame([2, 1], $deliverable->submissions()->pluck('version')->all());
        $this->assertSame('Version one', $first->fresh()->body);
        $this->actingAs($project->owner)->post(route('work.review', [$project, $deliverable]), ['decision' => 'approved', 'feedback' => 'Evidence verified.'])->assertRedirect();
        $this->get(route('work.download', [$project, $first]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->create())->get(route('work.download', [$project, $first]))->assertNotFound();
        $this->assertSame('approved', $deliverable->fresh()->status);
    }

    public function test_unsafe_files_own_reviews_and_cross_project_submissions_are_rejected(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $deliverable = Deliverable::factory()->create(['project_id' => $project->id]);
        $this->actingAs($project->owner)->post(route('work.submit', [$project, $deliverable]), ['file' => UploadedFile::fake()->create('payload.html', 1, 'text/html')])->assertSessionHasErrors('file');
        $this->post(route('work.submit', [$project, $deliverable]), ['body' => 'Owner evidence'])->assertRedirect();
        $this->post(route('work.review', [$project, $deliverable]), ['decision' => 'approved', 'feedback' => 'Self approve'])->assertForbidden();
        $other = Project::factory()->create(['owner_id' => $project->owner_id]);
        $this->post(route('work.submit', [$other, $deliverable]), ['body' => 'Cross project'])->assertNotFound();
    }

    public function test_confidential_supervision_notes_are_encrypted_and_hidden_from_owner_and_unassigned_admin(): void
    {
        $institution = Institution::factory()->create();
        $owner = $this->member($institution)->user;
        $supervisor = $this->member($institution, 'Lecturer / Teacher')->user;
        $admin = $this->member($institution, 'Institution Administrator')->user;
        $project = Project::factory()->create(['owner_id' => $owner->id, 'institution_id' => $institution->id]);
        ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $supervisor->id, 'institution_id' => $institution->id, 'membership_id' => $supervisor->memberships()->sole()->id, 'role' => 'supervisor']);
        $this->actingAs($supervisor)->post(route('work.note', $project), ['visibility' => 'internal', 'body' => 'Confidential supervision assessment'])->assertRedirect();
        $this->post(route('work.note', $project), ['visibility' => 'feedback', 'body' => 'Student visible advice'])->assertRedirect();
        $raw = DB::table('supervision_notes')->where('visibility', 'internal')->value('body');
        $this->assertStringNotContainsString('Confidential', $raw);
        foreach ([$owner, $admin] as $user) {
            $this->actingAs($user)->get(route('projects.show', [$project, 'tab' => 'feedback']))->assertOk()->assertSee('Student visible advice')->assertDontSee('Confidential supervision assessment');
            $this->post(route('work.note', $project), ['visibility' => 'internal', 'body' => 'Unassigned note'])->assertForbidden();
        }
        $this->actingAs($supervisor)->get(route('projects.show', [$project, 'tab' => 'feedback']))->assertSee('Confidential supervision assessment');
        $this->get(route('work.supervision'))->assertOk()->assertSee($project->title)->assertSee('Supervision review workload');
        $this->assertDatabaseCount('project_activities', 1);
    }

    public function test_progress_is_equal_weight_and_never_automatically_completes_project(): void
    {
        $project = Project::factory()->create(['status' => 'in_progress']);
        ProjectTask::factory()->create(['project_id' => $project->id, 'status' => 'completed']);
        ProjectTask::factory()->create(['project_id' => $project->id]);
        $deliverable = Deliverable::factory()->create(['project_id' => $project->id, 'status' => 'approved']);
        $this->assertSame(67, $project->progress()['percent']);
        $this->actingAs($project->owner)->post(route('projects.action', $project), ['action' => 'transition', 'status' => 'completed', 'reason' => 'Try early'])->assertUnprocessable();
        $project->tasks()->update(['status' => 'completed']);
        $this->assertSame(100, $project->progress()['percent']);
        $this->assertSame('in_progress', $project->fresh()->status);
        $this->post(route('projects.action', $project), ['action' => 'transition', 'status' => 'completed', 'reason' => 'All work validated.'])->assertRedirect();
        $this->post(route('work.task', $project), $this->taskData())->assertUnprocessable();
    }

    public function test_deadline_reminders_are_deduplicated_and_skip_revoked_access(): void
    {
        $project = Project::factory()->create(['status' => 'in_progress']);
        $member = User::factory()->create();
        ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $member->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'due_at' => today()->addDay()]);
        $task->assignees()->attach([$member->id, $project->owner_id], ['project_id' => $project->id]);
        $this->artisan('ideyra:projects:remind')->assertSuccessful();
        $this->artisan('ideyra:projects:remind')->assertSuccessful();
        $this->assertDatabaseCount('task_reminders', 2);
        $this->assertDatabaseCount('notifications', 2);
        $project->members()->delete();
        $task->update(['due_at' => today()]);
        $this->artisan('ideyra:projects:remind')->assertSuccessful();
        $this->assertDatabaseCount('task_reminders', 3);
    }

    public function test_analytics_date_ranges_and_csv_are_scoped_to_authorised_projects(): void
    {
        $institution = Institution::factory()->create();
        $student = $this->member($institution);
        $other = Institution::factory()->create();
        Project::factory()->create(['owner_id' => $student->user_id, 'institution_id' => $institution->id, 'status' => 'completed', 'created_at' => '2026-01-10', 'completed_at' => '2026-02-02']);
        Project::factory()->create(['institution_id' => $institution->id, 'created_at' => '2026-01-10']);
        Project::factory()->create(['institution_id' => $other->id, 'created_at' => '2026-01-10']);
        $this->actingAs($student->user);
        $data = app(ProjectAnalytics::class)->data(['from' => '2026-01-01', 'to' => '2026-02-28', 'group' => 'monthly']);
        $this->assertSame(1, $data['kpis']['Total projects']);
        $trend = collect($data['charts'])->firstWhere('key', 'project-completion');
        $this->assertSame([0, 1], $trend['values']);
        $this->get(route('work.analytics.csv', ['chart' => 'project-status', 'from' => '2026-01-01', 'to' => '2026-02-28']))->assertOk();
        $this->get(route('institution.analytics.csv', [$institution, 'project-status']))->assertForbidden();
        $this->get(route('work.analytics.csv', ['chart' => 'project-status', 'from' => '2026-03-01', 'to' => '2026-01-01']))->assertSessionHasErrors('from');
    }

    public function test_all_project_tabs_and_empty_screens_render_without_exposing_other_projects(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->owner);
        foreach (['overview', 'tasks', 'milestones', 'deliverables', 'team', 'feedback', 'activity'] as $tab) {
            $this->get(route('projects.show', [$project, 'tab' => $tab]))->assertOk();
        }
        foreach (['ideas.index', 'ideas.create', 'projects.index', 'projects.create', 'work.mine', 'work.supervision', 'dashboard'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('projects.show', [$project, 'tab' => '../identity/profile']))->assertSessionHasErrors('tab');
        $this->get(route('projects.show', $project).'?tab=')->assertOk()->assertSee('Project brief');
    }

    public function test_permission_seeder_preserves_existing_overrides_on_repeat(): void
    {
        $role = Role::where('name', 'Student')->sole();
        $permissions = array_values(array_diff($role->permissions, ['ideas.create']));
        $role->update(['permissions' => $permissions]);
        $this->seed(RolePermissionSeeder::class);
        $this->assertSame($permissions, $role->fresh()->permissions);
        $this->assertSame(1, DB::table('platform_settings')->where('key', 'phase02_permissions_initialized')->count());
    }

    public function test_academic_and_deliverable_filters_return_only_matching_authorised_records(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution, 'Institution Administrator');
        $a = AcademicClass::factory()->create(['institution_id' => $institution->id]);
        $b = AcademicClass::factory()->create(['institution_id' => $institution->id]);
        $project = Project::factory()->create(['institution_id' => $institution->id, 'academic_class_id' => $a->id, 'title' => 'Matching research project']);
        Project::factory()->create(['institution_id' => $institution->id, 'academic_class_id' => $b->id, 'title' => 'Excluded research project']);
        $this->actingAs($admin->user)->get(route('projects.index', ['programme_id' => $a->programme_id, 'academic_year_id' => $a->academic_year_id]))->assertOk()->assertSee('Matching research project')->assertDontSee('Excluded research project');
        $this->get(route('projects.create'))->assertOk()->assertSee($a->name);
        Deliverable::factory()->create(['project_id' => $project->id, 'title' => 'Matching evidence', 'status' => 'submitted']);
        Deliverable::factory()->create(['project_id' => $project->id, 'title' => 'Excluded evidence', 'status' => 'pending']);
        $this->get(route('projects.show', [$project, 'tab' => 'deliverables', 'deliverable_status' => 'submitted', 'deliverable_q' => 'Matching']))->assertOk()->assertSee('Matching evidence')->assertDontSee('Excluded evidence');
    }

    public function test_old_notification_links_recheck_access_after_project_assignment_removal(): void
    {
        $project = Project::factory()->create();
        $member = User::factory()->create();
        $this->actingAs($project->owner)->post(route('projects.action', $project), ['action' => 'team', 'email' => $member->email, 'role' => 'member'])->assertRedirect();
        $notification = $member->notifications()->sole();
        $this->actingAs($member)->get(route('notifications.open', $notification->id))->assertRedirect(route('projects.show', $project));
        $project->members()->delete();
        $this->get(route('notifications.open', $notification->id))->assertRedirect(route('notifications.index'));
        $this->get(route('projects.show', $project))->assertNotFound();
    }

    public function test_database_constraints_reject_cross_project_milestone_even_without_http_validation(): void
    {
        $a = Project::factory()->create();
        $b = Project::factory()->create();
        $milestone = Milestone::factory()->create(['project_id' => $b->id]);
        $this->expectException(QueryException::class);
        ProjectTask::factory()->create(['project_id' => $a->id, 'milestone_id' => $milestone->id]);
    }

    public function test_explicit_idea_visibility_denials_also_prevent_mutation_and_review(): void
    {
        $institution = Institution::factory()->create();
        $owner = $this->member($institution);
        $reviewer = $this->member($institution, 'Lecturer / Teacher');
        $idea = Idea::factory()->create(['institution_id' => $institution->id, 'owner_id' => $owner->user_id]);
        foreach ([$owner->id => 'ideas.view_own', $reviewer->id => 'ideas.view_shared'] as $memberId => $key) {
            DB::table('membership_permissions')->insert(['membership_id' => $memberId, 'permission_id' => Permission::where('name', $key)->sole()->id, 'allowed' => false]);
        }
        $this->actingAs($owner->user)->put(route('ideas.update', $idea), $this->ideaData(['institution_id' => $institution->id]))->assertForbidden();
        $idea->update(['visibility' => 'institutional', 'status' => 'submitted', 'reviewer_id' => $reviewer->user_id]);
        $this->actingAs($reviewer->user)->post(route('ideas.action', $idea), ['action' => 'review', 'decision' => 'approved', 'feedback' => 'Blocked by view denial.'])->assertForbidden();
        $this->assertSame('submitted', $idea->fresh()->status);
    }
}
