<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\Membership;
use App\Models\NetworkSetting;
use App\Models\Project;
use App\Models\Role;
use App\Support\NetworkAccess;
use App\Support\PublicationWorkflow;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NetworkPublicationTest extends TestCase
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

    private function draft(Project $p, Membership $m, array $extra = []): array
    {
        return $extra + ['project_id' => $p->id, 'institution_id' => $m->institution_id, 'title' => 'Approved public invention', 'summary' => 'An explicit public-facing summary.', 'description' => 'A controlled presentation only.', 'problem' => 'A recorded problem', 'solution' => 'A proposed approach', 'objectives' => 'Test the prototype', 'category' => 'Research', 'technologies' => ['PHP'], 'demo_links' => [], 'interests' => ['technical'], 'visibility' => 'public', 'attribution_ids' => [$m->user_id], 'institution_attribution' => true, 'collaboration_open' => true, 'declared_owner' => 'Student-declared owner', 'ip_acknowledgement' => 1, 'publication_consent' => 1, 'version' => 0];
    }

    public function test_publication_requires_independent_approval_and_revocation_takes_effect_immediately(): void
    {
        $i = Institution::factory()->create();
        $s = $this->member($i);
        $admin = $this->member($i, 'Institution Administrator');
        NetworkSetting::factory()->create(['institution_id' => $i->id, 'allow_public' => true]);
        $project = Project::factory()->create(['institution_id' => $i->id, 'owner_id' => $s->user_id, 'description' => 'INTERNAL CONFIDENTIAL DESCRIPTION']);
        $w = app(PublicationWorkflow::class);
        $this->actingAs($s->user);
        $p = $w->save($s->user, $this->draft($project, $s));
        $w->transition($s->user, $p, 'submitted', 'Explicit submission');
        $this->assertFalse(NetworkAccess::publicationVisible(null, $p));
        $this->actingAs($admin->user);
        $w->transition($admin->user, $p, 'approved', 'Confidential material excluded');
        $w->transition($admin->user, $p->fresh(), 'published', 'Approved audience');
        $p->refresh();
        $this->assertTrue(NetworkAccess::publicationVisible(null, $p));
        $data = $w->presentation($p);
        $this->assertSame('Approved public invention', $data['title']);
        $this->assertStringNotContainsString('INTERNAL CONFIDENTIAL', json_encode($data));
        $w->transition($admin->user, $p, 'unpublished', 'Publication revoked');
        $this->assertFalse(NetworkAccess::publicationVisible(null, $p->fresh()));
    }

    public function test_private_and_institutional_publications_are_not_cross_institution_discovery(): void
    {
        $i = Institution::factory()->create();
        $s = $this->member($i);
        $a = $this->member($i, 'Institution Administrator');
        $other = $this->member(Institution::factory()->create());
        $p = Project::factory()->create(['institution_id' => $i->id, 'owner_id' => $s->user_id]);
        $w = app(PublicationWorkflow::class);
        $this->actingAs($s->user);
        $pub = $w->save($s->user, $this->draft($p, $s, ['visibility' => 'institution']));
        $w->transition($s->user, $pub, 'submitted', 'Review');
        $this->actingAs($a->user);
        $w->transition($a->user, $pub, 'approved', 'Reviewed');
        $w->transition($a->user, $pub->fresh(), 'published', 'Publish');
        $pub->refresh();
        $this->assertTrue(NetworkAccess::publicationVisible($s->user, $pub));
        $this->assertFalse(NetworkAccess::publicationVisible($other->user, $pub));
        $this->assertFalse(NetworkAccess::publicationVisible(null, $pub));
    }

    public function test_attribution_requires_each_contributor_consent_and_revocation_removes_the_name(): void
    {
        $i = Institution::factory()->create();
        $s = $this->member($i);
        $peer = $this->member($i);
        $a = $this->member($i, 'Institution Administrator');
        NetworkSetting::factory()->create(['institution_id' => $i->id, 'allow_public' => true]);
        $p = Project::factory()->create(['institution_id' => $i->id, 'owner_id' => $s->user_id]);
        $p->members()->create(['user_id' => $peer->user_id, 'institution_id' => $i->id, 'membership_id' => $peer->id, 'role' => 'member']);
        $w = app(PublicationWorkflow::class);
        $this->actingAs($s->user);
        $pub = $w->save($s->user, $this->draft($p, $s, ['attribution_ids' => [$s->user_id, $peer->user_id]]));
        try {
            $w->transition($s->user, $pub, 'submitted', 'Review');
            $this->fail('Missing consent was accepted.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->actingAs($peer->user);
        $w->consent($peer->user, $pub, true);
        $this->actingAs($s->user);
        $w->transition($s->user, $pub, 'submitted', 'All contributors consented');
        $this->actingAs($a->user);
        $w->transition($a->user, $pub, 'approved', 'Reviewed');
        $w->transition($a->user, $pub->fresh(), 'published', 'Publish');
        $this->assertCount(2, $w->presentation($pub->fresh())['team']);
        $this->actingAs($peer->user);
        $w->consent($peer->user,$pub->fresh(),false);
        $this->assertCount(1,$w->presentation($pub->fresh())['team']);
    }
}
