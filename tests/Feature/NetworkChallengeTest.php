<?php

namespace Tests\Feature;

use App\Models\AchievementVerification;
use App\Models\ChallengeJudge;
use App\Models\ChallengeSubmission;
use App\Models\InnovationChallenge;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\NetworkPublication;
use App\Models\Opportunity;
use App\Models\OpportunityApplication;
use App\Models\Project;
use App\Models\Role;
use App\Support\ChallengeWorkflow;
use App\Support\NetworkAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NetworkChallengeTest extends TestCase
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

    private function draft(Institution $i, array $extra = []): array
    {
        return $extra + ['institution_id' => $i->id, 'title' => 'Prototype challenge', 'summary' => 'A real recorded challenge', 'description' => 'An institution innovation activity', 'organiser' => $i->name, 'kind' => 'competition', 'rules' => 'Submit an eligible prototype before the deadline.', 'eligibility_text' => 'Active student membership required.', 'opens_at' => now()->subDay()->toIso8601String(), 'deadline_at' => now()->addDay()->toIso8601String(), 'visibility' => 'institution', 'allow_groups' => true, 'criteria' => [['name' => 'Innovation', 'weight' => 60], ['name' => 'Feasibility', 'weight' => 40]]];
    }

    private function published(Institution $i, Membership $creator, Membership $reviewer): InnovationChallenge
    {
        $w = app(ChallengeWorkflow::class);
        $this->actingAs($creator->user);
        $c = $w->save($creator->user, $this->draft($i));
        $w->transition($creator->user, $c, 'submitted', 'Ready');
        $this->actingAs($reviewer->user);
        $w->transition($reviewer->user, $c->fresh(), 'approved', 'Independent review');
        $w->transition($reviewer->user, $c->fresh(), 'published', 'Publish');

        return $c->fresh();
    }

    public function test_challenge_reuses_one_opportunity_and_enforces_independent_publication(): void
    {
        $i = Institution::factory()->create();
        $a = $this->member($i, 'Institution Administrator');
        $b = $this->member($i, 'Institution Administrator');
        $c = $this->published($i, $a, $b);
        $this->assertSame(1, Opportunity::count());
        $this->assertSame('challenge', $c->opportunity->network_type);
        $this->assertSame('published', $c->status);
        $this->actingAs($a->user);
        $w = app(ChallengeWorkflow::class);
        $d = $w->save($a->user, $this->draft($i));
        $w->transition($a->user, $d, 'submitted', 'Ready');
        $this->expectException(HttpException::class);
        $w->transition($a->user, $d->fresh(), 'approved', 'Self approval');
    }

    public function test_registration_submission_judging_and_explicit_results_publication(): void
    {
        $i = Institution::factory()->create();
        $a = $this->member($i, 'Institution Administrator');
        $b = $this->member($i, 'Institution Administrator');
        $s = $this->member($i);
        $judge = $this->member($i, 'Lecturer / Teacher');
        $c = $this->published($i, $a, $b);
        $w = app(ChallengeWorkflow::class);
        $project = Project::factory()->create(['institution_id' => $i->id, 'owner_id' => $s->user_id, 'description' => 'INTERNAL PROJECT SECRET']);
        $this->actingAs($s->user);
        $application = $w->register($s->user, $c, ['institution_id' => $i->id, 'motivation' => 'Build a prototype']);
        $w->commitRegistration($s->user, $c, $application->fresh());
        $submission = $w->submit($s->user, $c, $application->fresh(), ['project_id' => $project->id, 'title' => 'Private submitted prototype', 'summary' => 'Submitted only to authorised judges', 'description' => 'An explicit challenge submission.']);
        $this->assertStringNotContainsString('INTERNAL PROJECT SECRET', json_encode($submission->snapshot));
        $this->assertSame(0, NetworkPublication::count());
        $this->actingAs($a->user);
        $w->assignJudge($a->user, $c, $judge->user);
        $this->travel(2)->days();
        $w->transition($a->user, $c, 'closed', 'Deadline passed');
        $w->transition($a->user, $c->fresh(), 'evaluation', 'Begin assigned judging');
        $scores = [];
        foreach ($c->criteria as $criterion) {
            $scores[$criterion->id] = ['score' => 8, 'comment' => 'Evidence-based evaluation'];
        }
        $this->actingAs($judge->user);
        $evaluation = $w->evaluate($judge->user, $submission->fresh(), ['scores' => $scores, 'feedback' => 'Private judge feedback', 'final' => true]);
        $this->assertSame(80.0, $evaluation->total);
        $this->assertFalse(NetworkAccess::judge($s->user, $c));
        $this->actingAs($a->user);
        $w->finalise($a->user, $c->fresh(), 'Every independent judge finished');
        $this->assertSame('final', $c->fresh()->results_state);
        $this->actingAs($b->user);
        $w->transition($b->user, $c->fresh(), 'results_published', 'Publish final aggregate results');
        $result = $c->fresh()->results_snapshot;
        $this->assertSame(80.0, (float) $result['entries'][0]['score']);
        $this->assertStringNotContainsString('Private judge feedback', json_encode($result));
        $this->assertStringNotContainsString('Private submitted prototype', json_encode($result));
        $this->assertSame(0, AchievementVerification::count());
        $this->travelBack();
    }

    public function test_judging_files_require_current_assignment_evaluation_stage_and_no_conflict(): void
    {
        Storage::fake('local');
        $i = Institution::factory()->create();
        $admin = $this->member($i, 'Institution Administrator');
        $student = $this->member($i);
        $judge = $this->member($i, 'Lecturer / Teacher');
        $outsider = $this->member($i, 'Lecturer / Teacher');
        $challenge = InnovationChallenge::factory()->create(['institution_id' => $i->id, 'status' => 'published']);
        $application = OpportunityApplication::factory()->create(['institution_id' => $i->id, 'opportunity_id' => $challenge->opportunity_id, 'applicant_id' => $student->user_id, 'status' => 'submitted']);
        $project = Project::factory()->create(['institution_id' => $i->id, 'owner_id' => $student->user_id]);
        $submission = ChallengeSubmission::factory()->create(['innovation_challenge_id' => $challenge->id, 'opportunity_application_id' => $application->id, 'project_id' => $project->id, 'snapshot' => ['title' => 'Private prototype', 'participant_ids' => [$student->user_id]]]);
        $assignment = ChallengeJudge::factory()->create(['innovation_challenge_id' => $challenge->id, 'user_id' => $judge->user_id]);
        $this->actingAs($judge->user);
        $this->get(route('network.submissions.show', $submission))->assertForbidden();
        $challenge->update(['status' => 'evaluation']);
        $this->get(route('network.submissions.show', $submission))->assertOk();
        $this->actingAs($outsider->user);
        $this->get(route('network.submissions.file', [$submission, 1]))->assertForbidden();
        $this->actingAs($judge->user);
        app(ChallengeWorkflow::class)->declareConflict($judge->user, $submission, 'Independent conflict declaration');
        $this->get(route('network.submissions.show', $submission))->assertForbidden();
        $this->assertDatabaseHas('challenge_conflicts', ['challenge_submission_id' => $submission->id, 'user_id' => $judge->user_id]);
        $assignment->update(['active' => false]);
        $this->assertFalse(NetworkAccess::judge($judge->user, $challenge));
        $this->get(route('network.judges', ['challenge_id' => $challenge->id]))->assertForbidden();
    }

    public function test_expired_challenge_registration_is_denied(): void
    {
        $i = Institution::factory()->create();
        $a = $this->member($i, 'Institution Administrator');
        $b = $this->member($i, 'Institution Administrator');
        $s = $this->member($i);
        $c = $this->published($i, $a, $b);
        $this->travel(2)->days();
        $this->actingAs($s->user);
        $this->expectException(HttpException::class);
        app(ChallengeWorkflow::class)->register($s->user, $c, ['institution_id' => $i->id, 'motivation' => 'Late application']);
    }
}
