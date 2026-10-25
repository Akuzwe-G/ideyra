<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\InstitutionKpi;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ReportingSetting;
use App\Models\Role;
use App\Support\KpiWorkflow;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KpiManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(Institution $i): Membership
    {
        return Membership::factory()->create(['institution_id' => $i->id, 'role_id' => Role::where('name', 'Institution Administrator')->sole()->id]);
    }

    private function definition(Membership $m, array $extra = []): array
    {
        return $extra + ['title' => 'Institution project completions', 'category' => 'Innovation', 'source' => 'automatic', 'metric' => 'completed_projects', 'unit' => 'Projects', 'period_from' => today()->startOfMonth()->toDateString(), 'period_to' => today()->endOfMonth()->toDateString(), 'target' => 10, 'direction' => 'increase', 'owner_id' => $m->user_id, 'reason' => 'Documented institutional objective', 'confirm' => 1, 'version' => 0];
    }

    public function test_kpi_activation_requires_independent_approval_and_retains_history(): void
    {
        $i = Institution::factory()->create();
        $a = $this->admin($i);
        $b = $this->admin($i);
        $this->actingAs($a->user)->post(route('kpis.save', $i), $this->definition($a))->assertRedirect();
        $k = InstitutionKpi::sole();
        $this->post(route('kpis.action', $k), ['action' => 'activate', 'reason' => 'Reviewed scope', 'confirm' => 1])->assertRedirect();
        $change = $k->changes()->sole();
        $this->assertSame('draft', $k->fresh()->lifecycle);
        $this->post(route('kpis.decide', $change), ['decision' => 'approve', 'reason' => 'Self approval', 'confirm' => 1])->assertForbidden();
        $this->actingAs($b->user)->post(route('kpis.decide', $change), ['decision' => 'approve', 'reason' => 'Independently reviewed', 'confirm' => 1])->assertRedirect();
        $this->assertSame('active', $k->fresh()->lifecycle);
        $this->assertSame($b->user_id, $change->fresh()->decided_by);
        $this->get(route('kpis.index', $i))->assertOk()->assertSee('Institution project completions')->assertSee('Independently reviewed');
    }

    public function test_automatic_actuals_and_retrospective_targets_cannot_be_overridden(): void
    {
        $i = Institution::factory()->create();
        $a = $this->admin($i);
        $workflow = app(KpiWorkflow::class);
        $k = $workflow->save($a->user, $i, $this->definition($a));
        $k->update(['lifecycle' => 'active']);
        Project::factory()->create(['institution_id' => $i->id, 'status' => 'completed', 'completed_at' => now()]);
        $this->assertSame(1.0, $workflow->actual($a->user, $k));
        $this->actingAs($a->user)->post(route('kpis.save', [$i, $k]), $this->definition($a, ['actual' => 999, 'version' => 1]))->assertSessionHasErrors('actual');
        $k->update(['period_to' => today()->subMonth()]);
        $this->post(route('kpis.save', [$i, $k]), $this->definition($a, ['target' => 1, 'version' => 1]))->assertUnprocessable();
        $this->assertSame(10.0, $k->fresh()->target);
    }

    public function test_manual_observations_are_encrypted_attributed_and_append_only(): void
    {
        $i = Institution::factory()->create();
        $a = $this->admin($i);
        $w = app(KpiWorkflow::class);
        $k = $w->save($a->user, $i, $this->definition($a, ['source' => 'manual', 'metric' => 'manual']));
        $k->update(['lifecycle' => 'active']);
        Storage::fake('local');
        $this->actingAs($a->user)->post(route('kpis.observe', $k), ['value' => 7, 'observed_on' => today()->toDateString(), 'evidence' => 'Confidential evidence reference', 'file' => UploadedFile::fake()->create('evidence.pdf', 1, 'application/pdf'), 'confirm' => 1])->assertRedirect();
        $o = $k->observations()->sole();
        $this->assertSame($a->user_id, $o->reported_by);
        $this->assertSame(7.0, $w->actual($a->user, $k));
        $this->assertStringNotContainsString('Confidential evidence', DB::table('kpi_observations')->value('evidence'));
        $this->post(route('kpis.observe', $k), ['value' => 8, 'observed_on' => today()->addYear()->toDateString(), 'evidence' => 'Future reference', 'confirm' => 1])->assertUnprocessable();
        $this->assertSame(1, $k->observations()->count());
        $this->get(route('kpis.evidence', $o))->assertOk()->assertDownload('kpi-evidence.pdf');
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'kpi.evidence_downloaded')->count());
        $foreign = $this->admin(Institution::factory()->create());
        $this->actingAs($foreign->user)->get(route('kpis.evidence', $o))->assertForbidden();
    }

    public function test_performance_directions_zero_targets_and_missing_data_are_explicit(): void
    {
        $w = app(KpiWorkflow::class);
        $this->assertSame('achieved', $w->performance(0.0, 0.0, null, 'decrease')['performance']);
        $this->assertSame('on_track', $w->performance(8.0, 10.0, null, 'increase')['performance']);
        $this->assertSame('at_risk', $w->performance(8.0, 10.0, null, 'increase', true)['performance']);
        $this->assertSame('achieved', $w->performance(12.0, 10.0, 15.0, 'range')['performance']);
        $this->assertSame('insufficient_data', $w->performance(null, 10.0, null, 'increase')['performance']);
    }

    public function test_foreign_and_student_kpi_access_is_denied_and_stale_edits_conflict(): void
    {
        $i = Institution::factory()->create();
        $a = $this->admin($i);
        $other = $this->admin(Institution::factory()->create());
        $s = Membership::factory()->create(['institution_id' => $i->id]);
        $k = app(KpiWorkflow::class)->save($a->user, $i, $this->definition($a));
        $this->actingAs($other->user)->get(route('kpis.index', $i))->assertForbidden();
        $this->actingAs($s->user)->get(route('kpis.index', $i))->assertForbidden();
        $this->actingAs($a->user)->post(route('kpis.save', [$i, $k]), $this->definition($a, ['version' => 0]))->assertConflict();
        $this->get(route('kpis.create', $i))->assertOk();
    }

    public function test_active_sensitive_changes_still_need_approval_when_policy_is_disabled(): void
    {
        $i = Institution::factory()->create();
        $a = $this->admin($i);
        $w = app(KpiWorkflow::class);
        $k = $w->save($a->user, $i, $this->definition($a));
        $k->update(['lifecycle' => 'active']);
        ReportingSetting::create(['institution_id' => $i->id, 'require_kpi_approval' => false]);
        $w->save($a->user, $i, $this->definition($a, ['version' => 1, 'target' => 20]), $k);
        $this->assertSame(10.0, $k->fresh()->target);
        $this->assertSame('pending', $k->changes()->sole()->status);
    }
}
