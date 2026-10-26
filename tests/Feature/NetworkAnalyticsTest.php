<?php

namespace Tests\Feature;

use App\Models\CollaborationRequest;
use App\Models\CollaborationWorkspace;
use App\Models\EventRegistration;
use App\Models\InnovationEvent;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\NetworkPublication;
use App\Models\Role;
use App\Support\IntelligenceAccess;
use App\Support\IntelligenceFilters;
use App\Support\IntelligenceMetrics;
use App\Support\IntelligenceReports;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NetworkAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function member(Institution $i): Membership
    {
        return Membership::factory()->create(['institution_id' => $i->id, 'role_id' => Role::where('name', 'Institution Administrator')->sole()->id]);
    }

    private function data(Membership $m, array $extra = []): array
    {
        $f = app(IntelligenceFilters::class)->normalise($m->user, $extra + ['institution_id' => $m->institution_id, 'section' => 'network', 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()]);

        return app(IntelligenceMetrics::class)->data(app(IntelligenceAccess::class)->context($m->user, $f), $f);
    }

    public function test_network_analytics_are_empty_without_records_and_charts_have_real_zero_values(): void
    {
        $m = $this->member(Institution::factory()->create());
        $data = $this->data($m);
        $this->assertCount(6, $data['charts']);
        $this->assertSame(0, $data['metrics']['network_publications']['value']);
        $this->assertSame(0, array_sum($data['charts'][0]['values']));
        $this->actingAs($m->user)->get(route('intelligence.index', ['institution_id' => $m->institution_id, 'section' => 'network']))->assertOk()->assertSee('Showcase publications over time');
    }

    public function test_counts_scope_dates_visibility_and_requests_are_distinct_from_accepted_workspaces(): void
    {
        $m = $this->member(Institution::factory()->create());
        $other = Institution::factory()->create();
        $base = ['institution_id' => $m->institution_id, 'status' => 'published', 'visibility' => 'network', 'approved_version' => 1, 'published_at' => now(), 'category' => 'Research'];
        $p = NetworkPublication::factory()->create($base);
        NetworkPublication::factory()->create($base);
        NetworkPublication::factory()->create(array_replace($base, ['visibility' => 'private']));
        NetworkPublication::factory()->create(array_replace($base, ['status' => 'unpublished']));
        NetworkPublication::factory()->create(array_replace($base, ['published_at' => now()->subYears(2)]));
        NetworkPublication::factory()->create(array_replace($base, ['institution_id' => $other->id]));
        $r = CollaborationRequest::factory()->create(['network_publication_id' => $p->id, 'institution_id' => $other->id, 'status' => 'submitted', 'submitted_at' => now()]);
        CollaborationRequest::factory()->create(['network_publication_id' => $p->id, 'institution_id' => $other->id, 'status' => 'declined', 'submitted_at' => now()]);
        CollaborationRequest::factory()->create(['network_publication_id' => $p->id, 'status' => 'draft']);
        CollaborationWorkspace::factory()->create(['collaboration_request_id' => $r->id, 'network_publication_id' => $p->id, 'institution_id' => $m->institution_id, 'institution_ids' => [$m->institution_id, $other->id], 'status' => 'active']);
        $d = $this->data($m);
        $this->assertSame(2, $d['metrics']['network_publications']['value']);
        $this->assertSame(2, $d['metrics']['network_requests']['value']);
        $this->assertSame(1, $d['metrics']['network_active_collaborations']['value']);
        $this->assertSame(0, $d['metrics']['network_collaborations_initiated']['value']);
        $this->assertSame(2, array_sum($d['charts'][0]['values']));
        $before = $this->data($m, ['from' => now()->subMonths(2)->startOfMonth()->toDateString(), 'to' => now()->subMonth()->endOfMonth()->toDateString()]);
        $this->assertSame(0, $before['metrics']['network_publications']['value']);
    }

    public function test_network_metrics_and_csv_require_both_analytics_and_network_permissions(): void
    {
        $m = $this->member(Institution::factory()->create());
        $other = $this->member(Institution::factory()->create());
        $this->actingAs($other->user)->get(route('intelligence.index', ['institution_id' => $m->institution_id, 'section' => 'network']))->assertForbidden();
        $url = route('intelligence.csv', ['chart' => 'network-publications', 'institution_id' => $m->institution_id, 'section' => 'network']);
        $this->actingAs($m->user)->get($url)->assertOk();
        $permissions = $m->role->permissions;
        $m->role->update(['permissions' => array_values(array_diff($permissions, ['innovation-analytics.view']))]);
        $this->get($url)->assertForbidden();
    }

    public function test_network_report_snapshot_exports_and_downloads_require_current_network_authority(): void
    {
        Storage::fake('local');
        $m = $this->member(Institution::factory()->create());
        $this->actingAs($m->user);
        $service = app(IntelligenceReports::class);
        $run = $service->request($m->user, ['institution_id' => $m->institution_id], 'network', 'csv');
        $service->generate($run);
        $run->refresh();
        $this->assertSame('ready', $run->status);
        $this->assertStringContainsString('Showcase publications over time', Storage::disk('local')->get($run->path));
        $this->get(route('reports.download', $run))->assertOk();
        $m->role->update(['permissions' => array_values(array_diff($m->role->permissions, ['innovation-analytics.view']))]);
        $this->get(route('reports.download', $run))->assertForbidden();
    }

    public function test_event_participation_counts_unique_people_and_excludes_cancellations_and_foreign_members(): void
    {
        $m = $this->member(Institution::factory()->create());
        $event = InnovationEvent::factory()->create(['institution_id' => $m->institution_id]);
        $student = Membership::factory()->create(['institution_id' => $m->institution_id]);
        $another = InnovationEvent::factory()->create(['institution_id' => $m->institution_id]);
        foreach ([$event, $another] as $e) {
            EventRegistration::factory()->create(['innovation_event_id' => $e->id, 'institution_id' => $m->institution_id, 'user_id' => $student->user_id]);
        }
        EventRegistration::factory()->create(['innovation_event_id' => $event->id, 'institution_id' => $m->institution_id, 'status' => 'cancelled']);
        EventRegistration::factory()->create(['innovation_event_id' => $event->id]);
        for ($index = 0; $index < 5; $index++) {
            $staff = $this->member($m->institution);
            EventRegistration::factory()->create(['innovation_event_id' => $event->id, 'institution_id' => $m->institution_id, 'user_id' => $staff->user_id]);
        }
        $d = $this->data($m);
        $this->assertSame(6, $d['metrics']['network_event_participants']['value']);
        $this->assertSame(2, $d['metrics']['network_events']['value']);
    }
}
