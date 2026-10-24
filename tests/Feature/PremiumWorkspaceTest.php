<?php

namespace Tests\Feature;

use App\Models\AcademicClass;
use App\Models\Institution;
use App\Models\JoinApplication;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Support\ApplicationWorkflow;
use App\Support\Audit;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PremiumWorkspaceTest extends TestCase
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

    public function test_platform_totals_are_real_and_pending_queue_matches_the_card(): void
    {
        $system = $this->system();
        User::factory()->create(['status' => 'suspended']);
        Institution::factory()->create(['status' => 'active', 'created_by' => $system->id, 'approved_at' => now()]);
        Institution::factory()->create(['status' => 'draft', 'created_by' => $system->id]);
        foreach (['submitted', 'under_review', 'more_information_requested', 'approved'] as $status) {
            JoinApplication::factory()->create(['status' => $status]);
        }
        $this->actingAs($system)->get(route('system.dashboard'))->assertOk()->assertViewHas('totals', [
            'Total institutions' => 2, 'Active institutions' => 1, 'Total users' => 2,
            'Total applications' => 4, 'Pending applications' => 3, 'Approved applications' => 1,
        ])->assertSee('Recently onboarded institutions');
        $this->get(route('system.applications', ['queue' => 'pending']))->assertOk()->assertViewHas('applications', fn ($rows) => $rows->total() === 3);
    }

    public function test_application_institution_and_user_filters_and_role_access(): void
    {
        $system = $this->system();
        $match = JoinApplication::factory()->create(['country' => 'Kenya', 'type' => 'student', 'status' => 'approved']);
        JoinApplication::factory()->create(['country' => 'Rwanda', 'status' => 'submitted']);
        $this->actingAs($system)->get(route('system.applications', ['country' => 'Kenya', 'status' => 'approved', 'type' => 'student']))
            ->assertOk()->assertViewHas('applications', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $match->id);
        $this->get(route('system.applications', ['q' => $match->reference]))->assertOk()->assertViewHas('applications', fn ($rows) => $rows->total() === 1);
        $institution = Institution::factory()->create(['name' => 'Searchable University', 'country' => 'Kenya', 'status' => 'active']);
        Institution::factory()->create(['country' => 'Rwanda', 'status' => 'draft']);
        $this->get(route('institutions.index', ['q' => 'Searchable', 'country' => 'Kenya', 'status' => 'active']))->assertOk()->assertViewHas('institutions', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $institution->id);
        $this->get(route('system.users', ['q' => $system->email]))->assertOk()->assertViewHas('users', fn ($rows) => $rows->total() === 1);
        foreach (['Student', 'Lecturer / Teacher', 'Mentor / Supervisor', 'Institution Administrator'] as $role) {
            $member = Membership::factory()->create(['institution_id' => $institution->id, 'role_id' => Role::where('name', $role)->value('id')]);
            $this->actingAs($member->user)->get(route('system.users'))->assertForbidden();
            $this->get(route('system.dashboard'))->assertForbidden();
            $this->get(route('institution.workspace', $institution))->assertOk();
        }
    }

    public function test_institution_overview_counts_are_scoped_and_students_only_see_assigned_academics(): void
    {
        $class = AcademicClass::factory()->create();
        $institution = Institution::findOrFail($class->institution_id);
        $admin = Membership::factory()->create(['institution_id' => $institution->id, 'role_id' => Role::where('name', 'Institution Administrator')->value('id')]);
        $student = Membership::factory()->create(['institution_id' => $institution->id]);
        Membership::factory()->create(['institution_id' => $institution->id, 'role_id' => Role::where('name', 'Lecturer / Teacher')->value('id')]);
        Membership::factory()->create(['institution_id' => $institution->id, 'role_id' => Role::where('name', 'Mentor / Supervisor')->value('id')]);
        Membership::factory()->create();
        $this->actingAs($admin->user)->get(route('institution.workspace', $institution))->assertOk()->assertViewHas('overview', [
            'Students' => 1, 'Lecturers and mentors' => 2, 'Academic years' => 1, 'Intakes' => 1, 'Programmes' => 1, 'Classes' => 1,
        ]);
        $this->actingAs($student->user)->get(route('institution.workspace', $institution))->assertOk()->assertViewHas('classes', fn ($classes) => $classes->isEmpty())->assertDontSee('data-analytics', false);
        DB::table('class_assignments')->insert(['institution_id' => $institution->id, 'membership_id' => $student->id, 'academic_class_id' => $class->id, 'assignment' => 'student']);
        $this->get(route('institution.workspace', $institution))->assertOk()->assertSee($class->programme->name)->assertSee($class->academicYear->name);
    }

    public function test_private_avatar_upload_replacement_removal_and_ownership(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('profile.update'), ['name' => 'Updated profile', 'avatar' => UploadedFile::fake()->image('portrait.png', 120, 120), 'avatar_path' => 'untrusted', 'status' => 'suspended', 'email' => 'untrusted@example.test'])->assertRedirect();
        $path = $user->fresh()->avatar_path;
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('avatars/', $path);
        $this->assertSame('active', $user->fresh()->status);
        $this->assertSame($user->email, $user->fresh()->email);
        $this->get(route('profile.avatar'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        Storage::disk('local')->delete($path);
        $this->get(route('profile'))->assertOk()->assertDontSee(route('profile.avatar'), false);
        $this->actingAs(User::factory()->create())->get(route('profile.avatar', ['user' => $user->id]))->assertNotFound();
        $this->actingAs($user)->post(route('profile.update'), ['name' => $user->name, 'avatar' => UploadedFile::fake()->image('replacement.jpg')])->assertRedirect();
        Storage::disk('local')->assertMissing($path);
        $replacement = $user->fresh()->avatar_path;
        $this->post(route('profile.update'), ['name' => $user->name, 'remove_avatar' => 1])->assertRedirect();
        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('local')->assertMissing($replacement);
    }

    public function test_avatar_validation_rejects_svg_forged_and_oversized_files(): void
    {
        Storage::fake('local');
        $this->actingAs($user = User::factory()->create());
        foreach ([
            UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->create('fake.png', 10, 'text/plain'),
            UploadedFile::fake()->image('oversized.png')->size(2049),
            UploadedFile::fake()->image('wide.png', 4097, 1),
        ] as $file) {
            $this->post(route('profile.update'), ['name' => $user->name, 'avatar' => $file])->assertSessionHasErrors('avatar');
        }
        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('local')->allFiles('avatars'));
    }

    public function test_real_events_notify_only_intended_recipients_and_private_notes_stay_private(): void
    {
        $system = $this->system();
        $other = User::factory()->create();
        $application = JoinApplication::factory()->create();
        Audit::record('application.submitted', $application);
        $this->assertSame(1, $system->unreadNotifications()->count());
        $this->assertSame(0, $other->notifications()->count());
        $this->actingAs($system);
        app(ApplicationWorkflow::class)->review($application, 'under_review', 'PRIVATE REVIEWER MATERIAL');
        $this->assertSame(2, $system->notifications()->count());
        $this->assertStringNotContainsString('PRIVATE REVIEWER MATERIAL', $system->notifications()->get()->toJson());
        $this->get(route('notifications.index'))->assertOk()->assertSee('New application received');
        $this->get(route('notifications.open', $system->notifications()->first()->id))->assertRedirect(route('system.applications.show', $application));
        Audit::record('academic.record_saved', $application);
        $this->assertSame(2, $system->notifications()->count());
    }

    public function test_notification_ownership_read_status_revoked_access_and_rollback(): void
    {
        $owner = $this->system();
        $other = User::factory()->create();
        $application = JoinApplication::factory()->create();
        Audit::record('application.submitted', $application);
        $notification = $owner->notifications()->sole();
        $this->actingAs($other)->patch(route('notifications.update', $notification->id), ['read' => 1])->assertNotFound();
        $this->get(route('notifications.open', $notification->id))->assertNotFound();
        $this->post(route('notifications.read-all'))->assertRedirect();
        $this->assertNull($notification->fresh()->read_at);
        $this->actingAs($owner)->patch(route('notifications.update', $notification->id), ['read' => 1])->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->patch(route('notifications.update', $notification->id), ['read' => 0])->assertRedirect();
        $this->assertNull($notification->fresh()->read_at);
        $owner->roles()->detach();
        $this->get(route('notifications.open', $notification->id))->assertRedirect(route('notifications.index'));
        DB::beginTransaction();
        Audit::record('identity.profile_updated', $other);
        $this->assertSame(1, $other->notifications()->count());
        DB::rollBack();
        $this->assertSame(0, $other->notifications()->count());
    }

    public function test_institution_and_membership_events_are_scoped_and_revoked_institution_links_are_safe(): void
    {
        $system = $this->system();
        $institution = Institution::factory()->create();
        $admin = Membership::factory()->create(['institution_id' => $institution->id, 'role_id' => Role::where('name', 'Institution Administrator')->value('id')]);
        $student = Membership::factory()->create(['institution_id' => $institution->id]);
        $outside = Membership::factory()->create();
        $this->actingAs($system)->post(route('institutions.status', $institution), ['status' => 'suspended'])->assertRedirect();
        $this->assertSame(1, $system->notifications()->count());
        $this->assertSame(1, $admin->user->notifications()->count());
        $this->assertSame(0, $student->user->notifications()->count());
        $this->assertSame(0, $outside->user->notifications()->count());
        $this->put(route('members.update', [$institution, $student]), ['role_id' => $student->role_id, 'status' => 'suspended'])->assertRedirect();
        $this->assertSame(1, $student->user->notifications()->count());
        $notification = $student->user->notifications()->sole();
        $this->actingAs($student->user)->get(route('notifications.open', $notification->id))->assertRedirect(route('notifications.index'));
    }
}
