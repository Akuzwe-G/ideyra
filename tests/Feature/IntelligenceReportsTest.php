<?php

namespace Tests\Feature;

use App\Jobs\GenerateIntelligenceReport;
use App\Models\AcademicClass;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\Role;
use App\Notifications\IntelligenceReportReady;
use App\Support\IntelligenceFilters;
use App\Support\IntelligenceReports;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IntelligenceReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    private function admin(Institution $i): Membership
    {
        return Membership::factory()->create(['institution_id' => $i->id, 'role_id' => Role::where('name', 'Institution Administrator')->sole()->id]);
    }

    private function filters(Membership $m): array
    {
        return app(IntelligenceFilters::class)->normalise($m->user, ['institution_id' => $m->institution_id, 'from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString()]);
    }

    public function test_all_formats_generate_real_private_files_and_immutable_encrypted_snapshots(): void
    {
        $i = Institution::factory()->create(['name' => 'Reporting Institution']);
        $m = $this->admin($i);
        $service = app(IntelligenceReports::class);
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $r = $service->request($m->user, $this->filters($m), 'overview', $format);
            $service->generate($r);
            $r->refresh();
            $this->assertSame('ready', $r->status, 'Format '.$format.' failed: '.json_encode(DB::table('audit_entries')->where('action', 'report.failed')->pluck('metadata')->all()));
            $content = Storage::disk('local')->get($r->path);
            $this->assertNotEmpty($content);
            if ($format === 'pdf') {
                $this->assertStringStartsWith('%PDF-', $content);
            } elseif ($format === 'xlsx') {
                $this->assertStringStartsWith('PK', $content);
            } else {
                $this->assertStringContainsString('Reporting Institution', $content);
            }
            $this->assertStringNotContainsString('Reporting Institution', DB::table('report_runs')->where('id', $r->id)->value('snapshot'));
            $hash = $r->file_hash;
            $service->generate($r);
            $this->assertSame($hash, $r->fresh()->file_hash);
            $this->actingAs($m->user)->get(route('reports.download', $r))->assertOk()->assertDownload();
            $this->get(route('reports.show', $r))->assertOk()->assertSee('Reporting Institution');
        }
    }

    public function test_report_centre_previews_all_ten_reports_and_gates_platform_and_kpi_scope(): void
    {
        $i = Institution::factory()->create();
        $m = $this->admin($i);
        foreach (array_keys(config('intelligence.reports')) as $type) {
            $response = $this->actingAs($m->user)->get(route('reports.preview', $this->filters($m) + ['report_type' => $type]));
            if ($type === 'platform') {
                $response->assertForbidden();
            } else {
                $response->assertOk()->assertSee(config('intelligence.reports')[$type]);
            }
        }
        $this->get(route('reports.index', $this->filters($m)))->assertOk();
        $this->get(route('reports.history', $this->filters($m)))->assertOk();
        $this->get(route('reports.schedules', $this->filters($m)))->assertOk();
    }

    public function test_download_rechecks_revocation_foreign_users_and_file_integrity(): void
    {
        $i = Institution::factory()->create();
        $m = $this->admin($i);
        $other = $this->admin(Institution::factory()->create());
        $s = app(IntelligenceReports::class);
        $r = $s->request($m->user, $this->filters($m), 'overview', 'csv');
        $s->generate($r);
        $r->refresh();
        $this->actingAs($other->user)->get(route('reports.download', $r))->assertForbidden();
        Storage::disk('local')->put($r->path, 'tampered');
        $this->actingAs($m->user)->get(route('reports.download', $r))->assertConflict();
        $m->update(['status' => 'suspended']);
        $this->get(route('reports.show', $r))->assertForbidden();
    }

    public function test_formula_injection_is_escaped_and_small_groups_are_not_released(): void
    {
        $this->assertSame("'=SUM(1,2)", IntelligenceReports::cell('=SUM(1,2)'));
        $this->assertSame("' @command", IntelligenceReports::cell(' @command'));
        $i = Institution::factory()->create();
        $m = $this->admin($i);
        Membership::factory()->create(['institution_id' => $i->id]);
        $p = app(IntelligenceReports::class)->preview($m->user, $this->filters($m), 'participation');
        $this->assertNull($p['data']['metrics']['students']['value']);
        $rows = iterator_to_array(app(IntelligenceReports::class)->rows($p));
        $this->assertStringContainsString('Protected / unavailable', json_encode($rows, JSON_UNESCAPED_SLASHES));
    }

    public function test_schedules_validate_recipients_dispatch_once_and_use_previous_complete_period(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 05:00:00', 'UTC'));
        $i = Institution::factory()->create();
        $m = $this->admin($i);
        $f = $this->filters($m);
        $this->actingAs($m->user)->post(route('reports.schedule'), $f + ['report_type' => 'overview', 'format' => 'csv', 'frequency' => 'monthly', 'recipient_ids' => [$m->user_id], 'confirm' => 1])->assertRedirect();
        $s = ReportSchedule::sole();
        $s->update(['next_run_at' => '2026-10-01 04:00:00']);
        $this->assertSame(1, app(IntelligenceReports::class)->dispatchDue());
        $this->assertSame(0, app(IntelligenceReports::class)->dispatchDue());
        Queue::assertPushed(GenerateIntelligenceReport::class, 1);
        $r = ReportRun::sole();
        $this->assertSame('2026-09-01', $r->filters['from']);
        $this->assertSame('2026-09-30', $r->filters['to']);
        $this->assertFalse(IntelligenceReports::emailOperational());
        $this->assertSame(['database'], (new IntelligenceReportReady($r->id))->via($m->user));
        $this->travelBack();
    }

    public function test_assigned_staff_can_export_only_their_classes_and_pending_scope_changes_fail_closed(): void
    {
        $i = Institution::factory()->create();
        $staff = Membership::factory()->create(['institution_id' => $i->id, 'role_id' => Role::where('name', 'Lecturer / Teacher')->sole()->id]);
        $class = AcademicClass::factory()->create(['institution_id' => $i->id]);
        $other = AcademicClass::factory()->create(['institution_id' => $i->id]);
        DB::table('class_assignments')->insert(['institution_id' => $i->id, 'academic_class_id' => $class->id, 'membership_id' => $staff->id, 'assignment' => 'lecturer']);
        Project::factory()->create(['institution_id' => $i->id, 'academic_class_id' => $class->id]);
        Project::factory()->create(['institution_id' => $i->id, 'academic_class_id' => $other->id]);
        $service = app(IntelligenceReports::class);
        $r = $service->request($staff->user, $this->filters($staff), 'projects', 'csv');
        $service->generate($r);
        $r->refresh();
        $this->assertSame('ready', $r->status);
        $this->assertSame(1, $r->snapshot['data']['metrics']['projects']['value']);
        $pending = $service->request($staff->user, $this->filters($staff), 'projects', 'csv');
        DB::table('class_assignments')->insert(['institution_id' => $i->id, 'academic_class_id' => $other->id, 'membership_id' => $staff->id, 'assignment' => 'lecturer']);
        $service->generate($pending);
        $this->assertSame('failed', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->snapshot);
    }

    public function test_foreign_and_student_schedule_recipients_are_rejected(): void
    {
        $i = Institution::factory()->create();
        $m = $this->admin($i);
        $foreign = $this->admin(Institution::factory()->create());
        $student = Membership::factory()->create(['institution_id' => $i->id]);
        foreach ([$foreign->user_id, $student->user_id] as $id) {
            $response = $this->actingAs($m->user)->post(route('reports.schedule'), $this->filters($m) + ['report_type' => 'overview', 'format' => 'csv', 'frequency' => 'monthly', 'recipient_ids' => [$id], 'confirm' => 1]);
            $this->assertContains($response->status(), [403, 422]);
        }
        $this->assertSame(0, ReportSchedule::count());
    }

    public function test_revoked_schedule_is_disabled_and_job_failure_exposes_no_private_contents(): void
    {
        Queue::fake();
        $i = Institution::factory()->create();
        $m = $this->admin($i);
        $service = app(IntelligenceReports::class);
        $filters = $this->filters($m);
        $schedule = ReportSchedule::factory()->create(['user_id' => $m->user_id, 'institution_id' => $i->id, 'filters' => $filters, 'recipient_ids' => [$m->user_id], 'next_run_at' => now()->subHour()]);
        $run = $service->request($m->user, $filters, 'overview', 'csv');
        $m->update(['status' => 'suspended']);
        $this->assertSame(0, $service->dispatchDue());
        $this->assertFalse($schedule->fresh()->enabled);
        Queue::assertNothingPushed();
        $service->generate($run);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNull($run->fresh()->snapshot);
        $this->assertSame('generation_failed', $run->fresh()->failure_code);
        $this->assertStringNotContainsString($m->user->email, json_encode(DB::table('audit_entries')->where('action', 'report.failed')->pluck('metadata')->all()));
    }

    public function test_report_snapshot_preserves_original_metrics_after_source_changes(): void
    {
        $i = Institution::factory()->create();
        $m = $this->admin($i);
        $service = app(IntelligenceReports::class);
        $r = $service->request($m->user, $this->filters($m), 'projects', 'csv');
        $service->generate($r);
        $r->refresh();
        $this->assertSame(0, $r->snapshot['data']['metrics']['projects']['value']);
        Project::factory()->create(['institution_id' => $i->id]);
        $this->assertSame(0, $r->fresh()->snapshot['data']['metrics']['projects']['value']);
        $this->actingAs($m->user)->get(route('reports.download', $r))->assertOk();
    }

    public function test_retention_removes_private_files_and_student_exports_are_forbidden(): void
    {
        $i = Institution::factory()->create();
        $m = $this->admin($i);
        $s = Membership::factory()->create(['institution_id' => $i->id]);
        $service = app(IntelligenceReports::class);
        $r = $service->request($m->user, $this->filters($m), 'overview', 'csv');
        $service->generate($r);
        $r->refresh();
        $path = $r->path;
        $r->update(['expires_at' => now()->subSecond()]);
        $this->actingAs($m->user)->get(route('reports.download', $r))->assertForbidden();
        $this->assertSame(1, $service->purge());
        Storage::disk('local')->assertMissing($path);
        $this->actingAs($s->user)->get(route('reports.index', ['institution_id' => $i->id]))->assertForbidden();
    }
}
