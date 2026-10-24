<?php

namespace Tests\Feature;

use App\Models\AcademicClass;
use App\Models\AuditEntry;
use App\Models\Institution;
use App\Models\JoinApplication;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\DashboardAnalytics;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function filters(string $group = 'daily'): array
    {
        return ['from' => '2026-01-01', 'to' => '2026-01-31', 'group' => $group];
    }

    private function chart(array $data, string $key): array
    {
        return collect($data['charts'])->firstWhere('key', $key);
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'System Administrator')->sole());

        return $user;
    }

    public function test_application_counts_date_boundaries_and_daily_weekly_monthly_buckets(): void
    {
        foreach (['2025-12-31 23:59:59', '2026-01-01 00:00:00', '2026-01-04 12:00:00', '2026-01-05 12:00:00', '2026-01-31 23:59:59', '2026-02-01 00:00:00'] as $date) {
            JoinApplication::factory()->create(['created_at' => $date, 'country' => str_contains($date, '01-05') ? 'Kenya' : 'Rwanda', 'status' => str_contains($date, '01-05') ? 'approved' : 'submitted']);
        }
        $analytics = app(DashboardAnalytics::class);
        $data = $analytics->system($this->filters());
        $daily = $this->chart($data, 'applications');
        $this->assertCount(31, $daily['labels']);
        $this->assertSame(4, array_sum($daily['values']));
        $this->assertSame(1, $daily['values'][0]);
        $this->assertSame(0, $daily['values'][1]);
        $this->assertSame(1, $daily['values'][30]);
        $this->assertSame([3, 0, 0, 1, 0, 0], $this->chart($data, 'statuses')['values']);
        $this->assertSame(['Rwanda', 'Kenya'], $this->chart($data, 'countries')['labels']);
        $this->assertSame([3, 1], $this->chart($data, 'countries')['values']);
        $weekly = $this->chart($analytics->system($this->filters('weekly')), 'applications');
        $this->assertSame('2025-12-29', $weekly['labels'][0]);
        $this->assertSame([2, 1, 0, 0, 1], $weekly['values']);
        $this->assertSame([4], $this->chart($analytics->system($this->filters('monthly')), 'applications')['values']);
    }

    public function test_institution_status_and_account_growth_follow_actual_creation_dates(): void
    {
        $creator = User::factory()->create(['created_at' => '2025-01-01']);
        User::factory()->create(['created_at' => '2026-01-31 23:59:59']);
        User::factory()->create(['created_at' => '2026-02-01']);
        foreach (['active', 'pending_activation', 'suspended', 'archived', 'draft'] as $status) {
            Institution::factory()->create(['created_by' => $creator->id, 'status' => $status, 'created_at' => '2026-01-10']);
        }
        Institution::factory()->create(['created_by' => $creator->id, 'created_at' => '2025-01-01']);
        $data = app(DashboardAnalytics::class)->system($this->filters('monthly'));
        $this->assertSame([1, 1, 1, 1, 1], $this->chart($data, 'onboarding')['values']);
        $this->assertSame([1], $this->chart($data, 'users')['values']);
        $this->assertSame(5, $data['kpis']['Institutions created']);
    }

    public function test_unique_student_counts_and_institution_isolation(): void
    {
        $institution = Institution::factory()->create();
        $first = AcademicClass::factory()->create(['institution_id' => $institution->id, 'name' => 'First class']);
        $second = AcademicClass::factory()->create(['institution_id' => $institution->id, 'programme_id' => $first->programme_id, 'intake_id' => $first->intake_id, 'academic_year_id' => $first->academic_year_id, 'name' => 'Second class']);
        $student = Membership::factory()->create(['institution_id' => $institution->id, 'created_at' => '2026-01-15']);
        $outside = Membership::factory()->create(['institution_id' => $institution->id, 'created_at' => '2025-01-15']);
        $pending = Membership::factory()->create(['institution_id' => $institution->id, 'status' => 'pending', 'created_at' => '2026-01-15']);
        foreach ([$first, $second] as $class) {
            foreach ([$student, $outside, $pending] as $member) {
                DB::table('class_assignments')->insert(['institution_id' => $institution->id, 'academic_class_id' => $class->id, 'membership_id' => $member->id, 'assignment' => 'class_representative']);
            }
        }
        $other = AcademicClass::factory()->create(['name' => 'Private other institution']);
        Membership::factory()->create(['institution_id' => $other->institution_id, 'created_at' => '2026-01-15']);
        $lecturer = Membership::factory()->create(['institution_id' => $institution->id, 'role_id' => Role::where('name', 'Lecturer / Teacher')->value('id'), 'created_at' => '2026-01-15']);
        $data = app(DashboardAnalytics::class)->institution($institution, $this->filters('monthly'));
        foreach (['programmes', 'intakes', 'academic_years'] as $key) {
            $this->assertSame([1], $this->chart($data, $key)['values']);
        }
        $this->assertSame([1, 1], $this->chart($data, 'academic_classes')['values']);
        $this->assertSame([1, 1, 0, 0], $this->chart($data, 'composition')['values']);
        $this->assertSame([3], $this->chart($data, 'members')['values']);
        $this->assertStringNotContainsString('Private other institution', json_encode($data));
        $this->actingAs($lecturer->user)->get(route('institution.workspace', $institution))->assertOk()->assertDontSee('data-analytics', false);
        $this->get(route('institution.analytics.csv', [$institution, 'members']))->assertForbidden();
    }

    public function test_csv_authorisation_and_date_validation(): void
    {
        $institution = Institution::factory()->create();
        $admin = Membership::factory()->create(['institution_id' => $institution->id, 'role_id' => Role::where('name', 'Institution Administrator')->value('id')]);
        $other = Institution::factory()->create();
        $this->get(route('system.analytics.csv', 'countries'))->assertRedirect(route('login'));
        $this->actingAs($admin->user)->get(route('system.analytics.csv', 'countries'))->assertForbidden();
        $this->get(route('institution.analytics.csv', [$other, 'members']))->assertForbidden();
        $this->get(route('institution.workspace', $other))->assertForbidden();
        $this->get(route('institution.analytics.csv', [$institution, 'members'] + $this->filters()))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->get(route('institution.analytics.csv', [$institution, 'unknown']))->assertNotFound();
        $this->actingAs($this->administrator())->get(route('system.dashboard', $this->filters()))->assertOk()->assertSee('No matching data');
        $this->get(route('system.dashboard', ['from' => '2026-02-01', 'to' => '2026-01-01']))->assertSessionHasErrors('from');
        $this->getJson(route('system.analytics.csv', ['chart' => 'countries', 'from' => 'not-a-date']))->assertUnprocessable();
        $this->getJson(route('system.dashboard', ['from' => '2000-01-01', 'to' => '2026-01-01']))->assertUnprocessable();
    }

    public function test_csv_includes_all_categories_and_escapes_spreadsheet_formulas(): void
    {
        foreach (range(1, 17) as $index) {
            JoinApplication::factory()->create(['country' => 'Country '.$index, 'created_at' => '2026-01-10']);
        }
        JoinApplication::factory()->create(['country' => '=malicious-formula', 'created_at' => '2026-01-10']);
        $this->actingAs($this->administrator());
        $response = $this->get(route('system.analytics.csv', ['chart' => 'countries'] + $this->filters()));
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=malicious-formula", $csv);
        $this->assertStringContainsString('Country 17', $csv);
        $this->assertCount(19, array_filter(explode("\n", trim($csv))));
    }

    public function test_empty_data_does_not_invent_enrolment(): void
    {
        $institution = Institution::factory()->create();
        $data = app(DashboardAnalytics::class)->institution($institution, $this->filters());
        $this->assertSame([], $this->chart($data, 'programmes')['values']);
        $this->assertSame([0, 0, 0, 0], $this->chart($data, 'composition')['values']);
        $this->assertSame(0, array_sum($this->chart($data, 'members')['values']));
        $this->actingAs($this->administrator())->get(route('institution.workspace', [$institution] + $this->filters()))->assertOk()->assertSee('No matching data');
    }

    public function test_composition_excludes_suspended_users_and_members_and_honours_audit_denials(): void
    {
        $institution = Institution::factory()->create();
        $admin = Membership::factory()->create(['institution_id' => $institution->id, 'role_id' => Role::where('name', 'Institution Administrator')->value('id'), 'created_at' => '2026-01-10']);
        $custom = Role::create(['name' => 'Academic Coordinator', 'scope' => 'institution', 'permissions' => []]);
        foreach (['Student', 'Lecturer / Teacher', 'Mentor / Supervisor', $custom->name] as $role) {
            Membership::factory()->create(['institution_id' => $institution->id, 'role_id' => Role::where('name', $role)->value('id'), 'created_at' => '2026-01-10']);
        }
        Membership::factory()->create(['institution_id' => $institution->id, 'status' => 'suspended', 'created_at' => '2026-01-10']);
        Membership::factory()->create(['institution_id' => $institution->id, 'user_id' => User::factory()->create(['status' => 'suspended'])->id, 'created_at' => '2026-01-10']);
        $this->actingAs($admin->user);
        AuditEntry::create(['institution_id' => $institution->id, 'actor_id' => $admin->user_id, 'action' => 'institution.profile_updated', 'subject_type' => 'Institution', 'subject_id' => $institution->id, 'metadata' => [], 'created_at' => '2026-01-15']);
        $analytics = app(DashboardAnalytics::class);
        $data = $analytics->institution($institution, $this->filters('monthly'));
        $this->assertSame([1, 1, 1, 2], $this->chart($data, 'composition')['values']);
        $this->assertSame([7], $this->chart($data, 'members')['values']);
        $this->assertCount(1, $data['activities']);
        DB::table('membership_permissions')->insert(['membership_id' => $admin->id, 'permission_id' => Permission::where('name', 'audit.view')->value('id'), 'allowed' => false]);
        $this->assertCount(0, $analytics->institution($institution, $this->filters())['activities']);
    }
}
