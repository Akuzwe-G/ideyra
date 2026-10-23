<?php

namespace Tests\Feature;

use App\Models\AcademicClass;
use App\Models\IdentityInvitation;
use App\Models\Institution;
use App\Models\JoinApplication;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\IdentityOnboarding;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PhaseOneSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Mail::fake();
    }

    private function system(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'System Administrator')->sole());

        return $user;
    }

    private function member(Institution $institution, string $role = 'Student'): Membership
    {
        return Membership::factory()->create(['institution_id' => $institution->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }

    public function test_database_foreign_keys_reject_cross_institution_class_assignment_even_without_controller(): void
    {
        $class = AcademicClass::factory()->create();
        $member = $this->member(Institution::factory()->create());
        $this->expectException(QueryException::class);
        DB::table('class_assignments')->insert(['institution_id' => $class->institution_id, 'academic_class_id' => $class->id, 'membership_id' => $member->id, 'assignment' => 'student', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_locked_profile_fields_cannot_be_written_by_institution_administrator_and_system_can_clear_locks(): void
    {
        $institution = Institution::factory()->create(['locked_fields' => ['name']]);
        $admin = $this->member($institution, 'Institution Administrator');
        $this->actingAs($admin->user)->put(route('institutions.update', $institution), ['name' => 'Bypass attempt'])->assertForbidden();
        $this->assertNotSame('Bypass attempt', $institution->fresh()->name);
        $data = collect($institution->getAttributes())->only(['name', 'type', 'slug', 'country', 'region', 'city', 'contact_email', 'student_population'])->all();
        $this->actingAs($this->system())->put(route('institutions.update', $institution), $data)->assertRedirect();
        $this->assertSame([], $institution->fresh()->locked_fields);
    }

    public function test_role_change_clears_old_overrides_and_contextual_assignments(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution, 'Institution Administrator');
        $student = $this->member($institution);
        $class = AcademicClass::factory()->create(['institution_id' => $institution->id]);
        DB::table('membership_permissions')->insert(['membership_id' => $student->id, 'permission_id' => Permission::where('name', 'projects.approve')->value('id'), 'allowed' => true]);
        DB::table('class_assignments')->insert(['institution_id' => $institution->id, 'academic_class_id' => $class->id, 'membership_id' => $student->id, 'assignment' => 'group_leader', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($admin->user)->put(route('members.update', [$institution, $student]), ['role_id' => Role::where('name', 'Lecturer / Teacher')->value('id'), 'status' => 'active'])->assertRedirect();
        $this->assertDatabaseCount('membership_permissions', 0);
        $this->assertDatabaseCount('class_assignments', 0);
    }

    public function test_signed_pdf_cannot_be_swapped_to_another_application_or_downloaded_by_an_unprivileged_account(): void
    {
        $a = JoinApplication::factory()->create();
        $b = JoinApplication::factory()->create();
        $signed = URL::temporarySignedRoute('applications.pdf', now()->addHour(), ['application' => $a->uuid]);
        $this->get(str_replace($a->uuid, $b->uuid, $signed))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('system.applications.pdf', $a))->assertForbidden();
    }

    public function test_private_activation_handoff_requires_verified_email_and_never_prints_the_token(): void
    {
        Storage::fake('local');
        $institution = Institution::factory()->create();
        $user = User::factory()->create(['status' => 'pending']);
        $member = Membership::factory()->create(['institution_id' => $institution->id, 'user_id' => $user->id, 'status' => 'pending']);
        $this->artisan('ideyra:activation-link', ['membership' => $member->id, '--verified-email' => 'wrong@example.test'])->assertFailed();
        Artisan::call('ideyra:activation-link', ['membership' => $member->id, '--verified-email' => $user->email]);
        $files = Storage::disk('local')->files('activation-links');
        $this->assertCount(1, $files);
        $url = Storage::disk('local')->get($files[0]);
        $this->assertStringContainsString('/activate/', $url);
        $this->assertStringNotContainsString('token=', Artisan::output());
        $this->assertSame(64, strlen(IdentityInvitation::sole()->token_hash));
    }

    public function test_invitation_reissue_invalidates_the_earlier_link(): void
    {
        $member = Membership::factory()->create(['status' => 'pending', 'user_id' => User::factory()->create(['status' => 'pending'])->id]);
        $service = app(IdentityOnboarding::class);
        $first = $service->invite($member, false);
        $second = $service->invite($member, false);
        $this->get($first)->assertForbidden();
        $this->get($second)->assertOk();
    }

    public function test_applicant_receipt_recovery_requires_verified_contact_and_outputs_private_file_only(): void
    {
        Storage::fake('local');
        $application = JoinApplication::factory()->create();
        $this->artisan('ideyra:application-receipt', ['reference' => $application->reference, '--verified-email' => 'wrong@example.test'])->assertFailed();
        Artisan::call('ideyra:application-receipt', ['reference' => $application->reference, '--verified-email' => $application->payload['email']]);
        $files = Storage::disk('local')->files('application-receipts');
        $this->assertCount(1, $files);
        $this->assertStringNotContainsString('signature=', Artisan::output());
        $this->get(Storage::disk('local')->get($files[0]))->assertOk();
    }

    public function test_custom_role_cannot_include_platform_permissions_and_reserved_baselines_are_protected(): void
    {
        $this->actingAs($this->system());
        $this->post(route('system.roles.store'), ['name' => 'Misleading role', 'permissions' => ['system.institutions']])->assertSessionHasErrors('permissions.0');
        $this->post(route('system.roles.store'), ['name' => 'Platform Admin'])->assertSessionHasErrors('name');
        $this->post(route('system.roles.store'), ['name' => 'Project creator', 'permissions' => ['projects.create']])->assertRedirect();
        $role = Role::where('name', 'Project creator')->sole();
        $this->assertSame('institution', $role->scope);
        $this->assertNotContains('projects.approve', $role->permissions);
        $this->put(route('system.roles.update', Role::where('name', 'System Administrator')->sole()), ['name' => 'Changed'])->assertForbidden();
        $permission = Permission::where('name', 'projects.create')->sole();
        $this->put(route('system.permissions.update', $permission), ['group' => 'Project authoring'])->assertRedirect();
        $this->assertSame('Project authoring', $permission->fresh()->group);
    }

    public function test_profile_update_cannot_change_email_status_or_roles_and_password_reset_expires(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/profile', ['name' => 'Updated identity', 'email' => 'attacker@example.test', 'status' => 'suspended', 'role_id' => Role::where('name', 'System Administrator')->value('id')])->assertRedirect();
        $this->assertSame('active', $user->fresh()->status);
        $this->assertNotSame('attacker@example.test', $user->fresh()->email);
        $this->assertSame(0, $user->roles()->count());
        auth()->logout();
        $token = Password::createToken($user);
        $this->travel(61)->minutes();
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123'])->assertSessionHasErrors('email');
    }

    public function test_password_reset_uses_configured_origin_and_generic_response_for_unknown_identity(): void
    {
        Notification::fake();
        config(['app.url' => 'https://trusted.example.test']);
        $user = User::factory()->create();
        $this->withHeader('Host', 'attacker.example.test')->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        $first = session('status');
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): bool {
            return str_starts_with($notification->toMail($user)->actionUrl, 'https://trusted.example.test/');
        });
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertSessionHas('status', $first);
    }

    public function test_local_log_mail_does_not_record_activation_or_password_reset_bearer_tokens(): void
    {
        Storage::fake('local');
        config(['mail.default' => 'log']);
        $pending = User::factory()->create(['status' => 'pending']);
        $member = Membership::factory()->create(['user_id' => $pending->id, 'status' => 'pending']);
        app(IdentityOnboarding::class)->invite($member);
        $this->assertSame('assistance_required', IdentityInvitation::sole()->mail_status);
        Mail::assertNothingSent();
        $active = User::factory()->create();
        $this->post('/forgot-password', ['email' => $active->email])->assertSessionHas('status');
        $files = Storage::disk('local')->files('password-reset-handoffs');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('/reset-password/', Storage::disk('local')->get($files[0]));
        $this->assertStringNotContainsString('token=', (string) session('status'));
    }
}
