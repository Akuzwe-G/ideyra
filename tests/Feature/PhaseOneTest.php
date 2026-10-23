<?php

namespace Tests\Feature;

use App\Mail\ApplicationNotification;
use App\Mail\ApplicationUpdateMail;
use App\Mail\IdentityInvitationMail;
use App\Models\AcademicClass;
use App\Models\AcademicYear;
use App\Models\AuditEntry;
use App\Models\IdentityInvitation;
use App\Models\Institution;
use App\Models\Intake;
use App\Models\JoinApplication;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Programme;
use App\Models\Role;
use App\Models\User;
use App\Support\ApplicationSchema;
use App\Support\InstitutionAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PhaseOneTest extends TestCase
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
        $user->roles()->attach(Role::where('name', 'System Administrator')->firstOrFail());

        return $user;
    }

    private function member(Institution $institution, string $role = 'Student', ?User $user = null): Membership
    {
        return Membership::factory()->create(['institution_id' => $institution->id, 'user_id' => ($user ?? User::factory()->create())->id, 'role_id' => Role::where('name', $role)->firstOrFail()->id]);
    }

    private function payload(string $type): array
    {
        $data = [];
        foreach (ApplicationSchema::fields($type) as $key => $field) {
            $data[$key] = match ($field[1]) {
                'select' => $field[3][0], 'checkboxes' => [$field[3][0]], 'number' => 100, 'year' => 2029,
                'email' => 'applicant@example.test','url' => 'https://example.test',default => 'A meaningful answer for '.$key,
            };
        }

        return $data + ['consent' => 1, 'review_confirmed' => 1];
    }

    private function submit(string $type = 'institution'): array
    {
        $this->get('/apply/'.$type)->assertOk();
        $data = $this->payload($type) + ['submission_key' => session('application_nonce.'.$type)];
        $response = $this->post('/apply/'.$type, $data)->assertRedirect();

        return [JoinApplication::latest('id')->firstOrFail(), $response, $data];
    }

    public function test_seeder_and_verified_system_bootstrap_are_idempotent_and_create_no_default_account(): void
    {
        $roles = Role::count();
        $this->seed(RolePermissionSeeder::class);
        $this->assertSame($roles, Role::count());
        $this->assertDatabaseCount('users', 0);
        $user = User::factory()->create(['email' => 'verified@example.test']);
        $this->artisan('ideyra:system-administrator', ['email' => $user->email])->assertFailed();
        $this->assertFalse(InstitutionAccess::system($user));
        $this->artisan('ideyra:system-administrator', ['email' => $user->email, '--confirm-email' => $user->email])->assertSuccessful();
        $this->artisan('ideyra:system-administrator', ['email' => $user->email, '--confirm-email' => $user->email])->assertSuccessful();
        $this->assertSame(1, $user->roles()->count());
        $this->assertTrue(InstitutionAccess::system($user));
        $this->assertSame(1, AuditEntry::where('action', 'system.administrator_assigned')->count());
    }

    public function test_both_application_types_store_encrypted_data_and_send_configured_notifications_without_creating_accounts(): void
    {
        config(['ideyra.application_email' => 'applications@example.test', 'ideyra.contact_email' => 'contact@example.test', 'mail.default' => 'log']);
        foreach (['institution', 'student'] as $type) {
            [$application,$response] = $this->submit($type);
            $this->assertSame('submitted', $application->status);
            $this->assertSame('logged', $application->mail_status);
            $this->assertSame($type, $application->type);
            $this->assertStringStartsWith('IDEYRA-', $application->reference);
            $this->assertNotSame($application->payload['email'], DB::table('join_applications')->where('id', $application->id)->value('payload'));
            $this->get($response->headers->get('Location'))->assertOk()->assertSee($application->reference)->assertSee('Download Application PDF');
            Mail::assertSent(ApplicationNotification::class, fn ($mail) => $mail->hasTo('applications@example.test'));
        }
        $this->assertDatabaseCount('join_applications', 2);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('institutions', 0);
    }

    public function test_application_validation_conditional_fields_spam_and_rate_limit(): void
    {
        $this->get('/apply/institution')->assertOk();
        $payload = $this->payload('institution') + ['submission_key' => session('application_nonce.institution')];
        $payload['authorised'] = 'no';
        $payload['decision_maker'] = '';
        $payload['hub_details'] = '';
        $this->post('/apply/institution', $payload)->assertSessionHasErrors(['decision_maker', 'hub_details']);
        $this->post('/apply/institution', array_replace($payload, ['website_trap' => 'spam']))->assertSessionHasErrors('website_trap');
        $this->post('/apply/institution', [])->assertSessionHasErrors();
        $this->post('/apply/institution', [])->assertSessionHasErrors();
        $this->post('/apply/institution', [])->assertSessionHasErrors();
        $this->post('/apply/institution', [])->assertStatus(429);
        $this->assertDatabaseCount('join_applications', 0);
        Mail::assertNothingSent();
    }

    public function test_submission_is_idempotent_but_another_session_cannot_recover_data_by_guessing_contact_details(): void
    {
        [$application,$response,$data] = $this->submit();
        $this->post('/apply/institution', $data)->assertRedirect();
        $this->assertDatabaseCount('join_applications', 1);
        Mail::assertSentCount(1);
        $this->flushSession();
        $this->get('/apply/institution')->assertOk();
        $this->post('/apply/institution', array_replace($data, ['submission_key' => session('application_nonce.institution')]))->assertSessionHasErrors('email');
        $this->assertDatabaseCount('join_applications', 1);
    }

    public function test_notification_failure_preserves_application(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('provider unavailable'));
        [$application] = $this->submit();
        $this->assertSame('failed', $application->mail_status);
        $this->assertDatabaseCount('join_applications', 1);
    }

    public function test_signed_pdf_is_real_private_time_limited_and_excludes_internal_notes(): void
    {
        [$application] = $this->submit();
        $application->update(['review_notes' => [['note' => 'PRIVATE INTERNAL ASSESSMENT']]]);
        $this->get('/applications/'.$application->uuid.'/pdf')->assertForbidden();
        $url = URL::temporarySignedRoute('applications.pdf', now()->addMinutes(10), ['application' => $application->uuid]);
        $pdf = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $html = view('applications.pdf', ['application' => $application, 'sections' => ApplicationSchema::sections($application->type)])->render();
        $this->assertStringNotContainsString('PRIVATE INTERNAL ASSESSMENT', $html);
        $this->travel(11)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_only_system_can_review_and_approval_does_not_create_or_activate_institution(): void
    {
        $application = JoinApplication::factory()->create();
        $this->actingAs(User::factory()->create())->post(route('system.applications.review', $application), ['status' => 'approved'])->assertForbidden();
        $this->actingAs($this->system())->post(route('system.applications.review', $application), ['status' => 'approved', 'note' => 'Private review'])->assertRedirect();
        $this->assertSame('approved', $application->fresh()->status);
        $this->assertDatabaseCount('institutions', 0);
        $this->post(route('system.applications.review', $application), ['status' => 'onboarded'])->assertSessionHasErrors('status');
    }

    public function test_more_information_uses_separate_public_message_and_keeps_notes_private(): void
    {
        $application = JoinApplication::factory()->create();
        $this->actingAs($this->system())->post(route('system.applications.review', $application), [
            'status' => 'more_information_requested', 'note' => 'Private reviewer assessment', 'public_message' => 'Please clarify the pilot department.',
        ])->assertRedirect();
        Mail::assertSent(ApplicationUpdateMail::class, function ($mail): bool {
            $mail->assertSeeInHtml('Please clarify the pilot department.');
            $mail->assertDontSeeInHtml('Private reviewer assessment');

            return true;
        });
        $this->assertSame('more_information_requested', $application->fresh()->status);
    }

    public function test_explicit_onboarding_is_unique_and_institution_stays_draft(): void
    {
        $application = JoinApplication::factory()->create(['status' => 'approved']);
        $this->actingAs($this->system());
        $data = ['name' => 'Test University', 'type' => 'university', 'slug' => 'test-university', 'country' => 'Rwanda', 'region' => 'Kigali', 'city' => 'Kigali', 'contact_email' => 'office@example.test', 'student_population' => 1000, 'application_uuid' => $application->uuid];
        $this->post(route('institutions.store'), $data)->assertRedirect();
        $institution = Institution::sole();
        $this->assertSame('draft', $institution->status);
        $this->assertSame($application->id, $institution->application_id);
        $this->assertSame('approved', $application->fresh()->status);
        $this->post(route('institutions.store'), array_replace($data, ['slug' => 'another-slug']))->assertStatus(422);
        $this->post(route('institutions.status', $institution), ['status' => 'active'])->assertStatus(422);
        $this->member($institution, 'Institution Administrator');
        $this->post(route('institutions.status', $institution), ['status' => 'active'])->assertRedirect();
        $this->assertSame('onboarded', $application->fresh()->status);
    }

    public function test_institution_administrator_cannot_create_institutions_or_grant_system_role(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution, 'Institution Administrator');
        $this->actingAs($admin->user);
        $this->post(route('institutions.store'), [])->assertForbidden();
        $this->post(route('members.store', $institution), ['name' => 'Attempt', 'email' => 'attempt@example.test', 'role_id' => Role::where('name', 'System Administrator')->value('id')])->assertForbidden();
    }

    public function test_new_account_invitation_is_hashed_single_use_and_activates_only_after_acceptance(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution, 'Institution Administrator');
        $this->actingAs($admin->user)->post(route('members.store', $institution), ['name' => 'New Student', 'email' => 'new@example.test', 'role_id' => Role::where('name', 'Student')->value('id')])->assertRedirect();
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('pending', $user->status);
        $url = '';
        Mail::assertSent(IdentityInvitationMail::class, function ($mail) use (&$url): bool {
            $url = $mail->activationUrl;

            return true;
        });
        $this->assertStringNotContainsString(parse_url($url, PHP_URL_QUERY), IdentityInvitation::sole()->token_hash);
        Auth::logout();
        $this->get($url)->assertOk();
        $this->post($url, ['accept' => 1, 'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123'])->assertRedirectToRoute('dashboard');
        $this->assertSame('active', $user->fresh()->status);
        $this->assertTrue(Hash::check('StrongPassword123', $user->fresh()->password));
        $this->assertNotNull(IdentityInvitation::sole()->accepted_at);
        $this->post($url, ['accept' => 1])->assertForbidden();
    }

    public function test_existing_identity_requires_owner_login_before_additional_membership_is_accepted(): void
    {
        $institution = Institution::factory()->create();
        $owner = User::factory()->create();
        $original = $owner->password;
        $this->actingAs($this->system())->post(route('members.store', $institution), ['name' => 'Do not overwrite', 'email' => $owner->email, 'role_id' => Role::where('name', 'Student')->value('id')])->assertRedirect();
        $url = '';
        Mail::assertSent(IdentityInvitationMail::class, function ($mail) use (&$url): bool {
            $url = $mail->activationUrl;

            return true;
        });
        $this->assertSame('pending', Membership::where('user_id', $owner->id)->sole()->status);
        $this->post($url, ['accept' => 1])->assertForbidden();
        $this->actingAs($owner)->post($url, ['accept' => 1])->assertRedirectToRoute('dashboard');
        $this->assertSame($original, $owner->fresh()->password);
        $this->assertNotSame('Do not overwrite', $owner->fresh()->name);
        $this->assertSame('active', Membership::where('user_id', $owner->id)->sole()->status);
    }

    public function test_expired_and_forged_activation_links_are_denied(): void
    {
        $institution = Institution::factory()->create();
        $this->actingAs($this->system())->post(route('members.store', $institution), ['name' => 'New Identity', 'email' => 'expire@example.test', 'role_id' => Role::where('name', 'Student')->value('id')]);
        $url = '';
        Mail::assertSent(IdentityInvitationMail::class, function ($mail) use (&$url): bool {
            $url = $mail->activationUrl;

            return true;
        });
        Auth::logout();
        $this->get('/activate/'.IdentityInvitation::sole()->uuid)->assertForbidden();
        $this->travel(49)->hours();
        $this->get($url)->assertForbidden();
    }

    public function test_cross_institution_records_and_memberships_are_denied(): void
    {
        $a = Institution::factory()->create();
        $b = Institution::factory()->create();
        $admin = $this->member($a, 'Institution Administrator');
        $foreign = $this->member($b);
        $this->actingAs($admin->user);
        $this->get(route('members.index', $b))->assertForbidden();
        $this->get(route('members.edit', [$a, $foreign]))->assertNotFound();
        $foreignYear = AcademicYear::factory()->create(['institution_id' => $b->id]);
        $this->post(route('academic.store', [$a, 'intakes']), ['name' => 'Invalid intake', 'academic_year_id' => $foreignYear->id, 'starts_at' => '2026-08-01', 'status' => 'active'])->assertSessionHasErrors('academic_year_id');
        $this->get(route('institution.workspace', $b))->assertForbidden();
    }

    public function test_academic_creation_class_assignment_and_contextual_leadership_are_functional(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution, 'Institution Administrator');
        $student = $this->member($institution);
        $staff = $this->member($institution, 'Lecturer / Teacher');
        $this->actingAs($admin->user);
        $this->post(route('academic.store', [$institution, 'years']), ['name' => '2026–2027', 'starts_at' => '2026-08-01', 'ends_at' => '2027-07-31', 'status' => 'active'])->assertRedirect();
        $this->post(route('academic.store', [$institution, 'programmes']), ['name' => 'Engineering', 'code' => 'ENG', 'description' => 'Engineering programme', 'status' => 'active'])->assertRedirect();
        $this->post(route('academic.store', [$institution, 'intakes']), ['name' => 'August intake', 'academic_year_id' => AcademicYear::sole()->id, 'starts_at' => '2026-08-01', 'status' => 'active'])->assertRedirect();
        $this->post(route('academic.store', [$institution, 'classes']), ['name' => 'Engineering 2029', 'programme_id' => Programme::sole()->id, 'intake_id' => Intake::sole()->id, 'academic_year_id' => AcademicYear::sole()->id, 'status' => 'active', 'graduation_year' => 2029])->assertRedirect();
        $class = AcademicClass::sole();
        $this->post(route('classes.assign', [$institution, $class]), ['membership_id' => $student->id, 'assignment' => 'group_leader'])->assertRedirect();
        $this->post(route('classes.assign', [$institution, $class]), ['membership_id' => $staff->id, 'assignment' => 'lecturer'])->assertRedirect();
        $this->assertDatabaseCount('class_assignments', 2);
        $this->actingAs($student->user)->get(route('institution.workspace', $institution))->assertOk()->assertSee($class->name);
        $this->post(route('academic.store', [$institution, 'years']), [])->assertForbidden();
        $this->assertFalse(InstitutionAccess::allows($institution, 'projects.approve'));
        $this->assertFalse(InstitutionAccess::allows($institution, 'projects.sponsor'));
    }

    public function test_grants_denials_and_privilege_escalation_are_scoped_and_audited(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution, 'Institution Administrator');
        $student = $this->member($institution);
        $create = Permission::where('name', 'projects.create')->sole();
        $approve = Permission::where('name', 'projects.approve')->sole();
        $this->actingAs($admin->user)->post(route('members.permissions', [$institution, $student]), ['overrides' => [$create->id => 'deny', $approve->id => 'grant']])->assertRedirect();
        $this->actingAs($student->user);
        $this->assertFalse(InstitutionAccess::allows($institution, 'projects.create'));
        $this->assertTrue(InstitutionAccess::allows($institution, 'projects.approve'));
        $this->assertFalse(InstitutionAccess::allows($institution, 'projects.sponsor'));
        $this->post(route('members.permissions', [$institution, $student]), ['overrides' => [$approve->id => 'grant']])->assertForbidden();
        $lecturer = $this->member($institution, 'Lecturer / Teacher');
        DB::table('membership_permissions')->insert(['membership_id' => $lecturer->id, 'permission_id' => Permission::where('name', 'members.permissions')->value('id'), 'allowed' => true]);
        $this->actingAs($lecturer->user)->post(route('members.permissions', [$institution, $student]), ['overrides' => [Permission::where('name', 'projects.sponsor')->value('id') => 'grant']])->assertForbidden();
        $this->assertDatabaseHas('audit_entries', ['action' => 'membership.permissions_changed', 'institution_id' => $institution->id]);
    }

    public function test_final_active_administrator_is_protected_and_suspension_is_membership_scoped(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->member($institution, 'Institution Administrator');
        $this->actingAs($this->system())->put(route('members.update', [$institution, $admin]), ['role_id' => $admin->role_id, 'status' => 'suspended'])->assertSessionHasErrors('status');
        $student = $this->member($institution);
        $other = Institution::factory()->create();
        $second = $this->member($other, 'Student', $student->user);
        $this->actingAs($admin->user)->put(route('members.update', [$institution, $student]), ['role_id' => $student->role_id, 'status' => 'suspended'])->assertRedirect();
        $this->assertSame('active', $student->user->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);
        $this->actingAs($student->user)->get(route('institution.workspace', $other))->assertOk();
        $this->get(route('institution.workspace', $institution))->assertForbidden();
    }

    public function test_suspended_institution_and_suspended_account_cannot_access_private_workspaces(): void
    {
        $institution = Institution::factory()->create(['status' => 'suspended']);
        $member = $this->member($institution);
        $this->actingAs($member->user)->get(route('institution.workspace', $institution))->assertForbidden();
        $member->user->forceFill(['status' => 'suspended'])->save();
        $this->get('/dashboard')->assertForbidden();
        $this->assertGuest();
    }

    public function test_password_reset_is_single_use_and_does_not_activate_pending_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'NewStrongPassword123', 'password_confirmation' => 'NewStrongPassword123'];
        $this->post('/reset-password', $payload)->assertRedirectToRoute('login');
        $this->assertTrue(Hash::check('NewStrongPassword123', $user->fresh()->password));
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $pending = User::factory()->create(['status' => 'pending']);
        $this->post('/forgot-password', ['email' => $pending->email])->assertSessionHas('status');
        Notification::assertNotSentTo($pending, ResetPassword::class);
    }

    public function test_administrative_screens_render_and_personal_identity_is_isolated(): void
    {
        $institution = Institution::factory()->create();
        $member = $this->member($institution);
        $application = JoinApplication::factory()->create();
        $class = AcademicClass::factory()->create(['institution_id' => $institution->id]);
        $this->actingAs($this->system());
        foreach (['/system', '/system/institutions', '/system/institutions/create', '/system/applications', route('system.applications.show', $application), '/system/roles', '/system/audit', '/system/settings', route('institutions.edit', $institution), route('members.index', $institution), route('members.edit', [$institution, $member]), route('academic.index', [$institution, 'years']), route('academic.index', [$institution, 'classes']), route('classes.assignments', [$institution, $class]), route('institution.audit', $institution)] as $url) {
            $this->get($url)->assertOk();
        }
        $this->actingAs($member->user)->get('/profile')->assertOk()->assertSee($member->user->email);
        $this->get('/profile/999')->assertNotFound();
        $this->get('/system/audit')->assertForbidden();
    }
}
