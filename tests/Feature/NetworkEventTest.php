<?php

namespace Tests\Feature;

use App\Models\AchievementVerification;
use App\Models\EventRegistration;
use App\Models\InnovationEvent;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\Role;
use App\Support\EventWorkflow;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NetworkEventTest extends TestCase
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

    private function published(Institution $i, Membership $a, Membership $b): InnovationEvent
    {
        $this->actingAs($a->user);
        $w = app(EventWorkflow::class);
        $e = $w->save($a->user, ['institution_id' => $i->id, 'title' => 'Student innovation exhibition', 'description' => 'An approved exhibition', 'organiser' => $i->name, 'kind' => 'exhibition', 'venue' => 'Innovation studio', 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDay()->addHours(2)->toIso8601String(), 'timezone' => 'Indian/Mauritius', 'capacity' => 1, 'registration_rules' => 'Active institutional participants only.', 'visibility' => 'institution']);
        $w->transition($a->user, $e, 'submitted', 'Ready');
        $this->actingAs($b->user);
        $w->transition($b->user, $e->fresh(), 'approved', 'Reviewed');
        $w->transition($b->user, $e->fresh(), 'published', 'Publish');

        return $e->fresh();
    }

    public function test_event_registration_is_idempotent_and_respects_capacity(): void
    {
        $i = Institution::factory()->create();
        $a = $this->member($i, 'Institution Administrator');
        $b = $this->member($i, 'Institution Administrator');
        $s = $this->member($i);
        $other = $this->member($i);
        $e = $this->published($i, $a, $b);
        $w = app(EventWorkflow::class);
        $this->actingAs($s->user);
        $r = $w->register($s->user, $e, $i->id);
        $this->assertSame($r->id, $w->register($s->user, $e, $i->id)->id);
        $this->assertSame(1, EventRegistration::count());
        $this->assertSame(1, Opportunity::count());
        $this->actingAs($other->user);
        $this->expectException(HttpException::class);
        $w->register($other->user, $e, $i->id);
    }

    public function test_only_organisers_record_attendance_and_completion_is_explicit(): void
    {
        $i = Institution::factory()->create();
        $a = $this->member($i, 'Institution Administrator');
        $b = $this->member($i, 'Institution Administrator');
        $s = $this->member($i);
        $e = $this->published($i, $a, $b);
        $w = app(EventWorkflow::class);
        $this->actingAs($s->user);
        $r = $w->register($s->user, $e, $i->id);
        $this->travel(2)->days();
        $this->actingAs($a->user);
        $w->attendance($a->user, $r, true);
        $this->assertSame('attended', $r->fresh()->status);
        $w->transition($a->user, $e, 'completed', 'Event concluded');
        $this->assertSame('completed', $e->fresh()->status);
        $this->assertSame(0,AchievementVerification::count());
        $this->travelBack();
    }
}
