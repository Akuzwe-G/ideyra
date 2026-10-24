<?php

namespace Tests\Feature;

use App\Models\AchievementVerification;
use App\Models\AiConversation;
use App\Models\AiUsage;
use App\Models\CvDocument;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\ProfessionalEntry;
use App\Models\Role;
use App\Models\User;
use App\Support\AiContext;
use App\Support\AiProvider;
use App\Support\CareerWorkflow;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Http::preventStrayRequests();
        config(['ai.enabled' => true, 'ai.key' => 'test-only-placeholder', 'ai.model' => 'test-fixture', 'cache.default' => 'array']);
    }

    private function input(): array
    {
        return ['feature' => 'career', 'mode' => 'explore', 'message' => 'Explore a learning goal', 'consent' => 1];
    }

    public function test_only_explicit_https_configuration_is_accepted(): void
    {
        foreach (['http://example.test', 'https://user:pass@example.test', 'https://example.test?key=secret', 'https://example.test#fragment'] as $endpoint) {
            config(['ai.endpoint' => $endpoint]);
            $this->assertFalse(app(AiProvider::class)->available());
        }
        config(['ai.endpoint' => 'https://example.test/v1/chat/completions']);
        $this->assertTrue(app(AiProvider::class)->available());
        config(['ai.provider' => 'unknown']);
        $this->assertFalse(app(AiProvider::class)->available());
        Http::assertNothingSent();
    }

    public function test_platform_quota_includes_other_users(): void
    {
        config(['ai.platform_daily' => 1]);
        AiUsage::factory()->create();
        $this->actingAs(User::factory()->create())->post(route('ai.start'), $this->input())->assertSessionHasErrors('message');
        Http::assertNothingSent();
    }

    public function test_edited_claims_lose_the_current_verification_label(): void
    {
        $entry = ProfessionalEntry::factory()->create();
        $u = $entry->profile->user;
        AchievementVerification::factory()->create(['professional_entry_id' => $entry->id, 'user_id' => $u->id, 'status' => 'verified', 'approved_content' => app(CareerWorkflow::class)->entryPayload($entry)]);
        $this->assertStringContainsString('Institution-confirmed', AiContext::evidenceStatus($entry));
        $entry->update(['title' => 'Changed claim']);
        $entry->unsetRelation('verifications');
        $this->assertStringContainsString('Self-reported', AiContext::evidenceStatus($entry));
        $this->assertArrayNotHasKey('evidence', app(AiContext::class)->build($u, 'career', null, null, [], [$entry->id])['selected_professional_records'][0]);
    }

    public function test_real_aggregation_and_monthly_filters_are_exact(): void
    {
        $root = User::factory()->create();
        $root->roles()->attach(Role::where('name', 'System Administrator')->sole());
        $i = Institution::factory()->create();
        AiUsage::factory()->count(2)->create(['institution_id' => $i->id, 'created_at' => '2026-06-05 12:00:00']);
        AiUsage::factory()->create(['created_at' => '2026-07-05 12:00:00', 'status' => 'provider_failed']);
        $this->actingAs($root)->get(route('ai.activity', ['scope' => 'system', 'from' => '2026-06-01', 'to' => '2026-07-31', 'group' => 'monthly']))
            ->assertOk()->assertViewHas('analytics', fn ($a) => $a['kpis']['Requests'] === 3 && $a['kpis']['Reported tokens'] === 450 && $a['kpis']['Failed requests'] === 1 && $a['charts'][0]['values'] === [2, 1] && array_sum($a['charts'][3]['values']) === 3);
        $this->get(route('ai.activity', ['scope' => 'system', 'from' => '2026-06-01', 'to' => '2026-06-30']))->assertViewHas('analytics', fn ($a) => $a['kpis']['Requests'] === 2);
    }

    public function test_credentials_and_oversized_context_are_blocked_before_network_or_storage(): void
    {
        $this->actingAs(User::factory()->create())->post(route('ai.start'), ['message' => 'test-only-placeholder'] + $this->input())->assertSessionHasErrors('message');
        config(['ai.max_input' => 10]);
        $this->post(route('ai.start'), $this->input())->assertSessionHasErrors('message');
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_conversations', 0);
        $this->assertDatabaseCount('ai_usages', 0);
    }

    public function test_json_interaction_preserves_retry_support_without_flashing_credentials(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->postJson(route('ai.start'), ['consent' => 0] + $this->input())->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->post(route('ai.start'), ['message' => 'test-only-placeholder'] + $this->input())->assertSessionHasErrors('message')->assertSessionMissing('_old_input');
        $content = ['reflection' => 'Review your learning goal.', 'questions' => ['What would you like to learn?'], 'assumptions' => [], 'risks' => [], 'alternatives' => [], 'next_action' => 'Write one goal.', 'original' => '', 'revision' => '', 'explanation' => ''];
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($content)]]]])]);
        $this->postJson(route('ai.start'), $this->input())->assertOk()->assertJsonPath('redirect', fn ($url) => str_contains($url, '/ai/conversations/'));
        $this->assertDatabaseCount('ai_conversations', 1);
    }

    public function test_partial_output_is_never_saved(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"reflection":"partial"}']]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50]])]);
        $this->actingAs(User::factory()->create())->post(route('ai.start'), $this->input())->assertSessionHasErrors('message');
        $this->assertDatabaseCount('ai_conversations', 0);
        $this->assertSame('invalid_response', AiUsage::sole()->status);
        $this->assertSame(100, AiUsage::sole()->input_tokens);
        $this->assertSame(50, AiUsage::sole()->output_tokens);
    }

    public function test_existing_personal_history_cannot_be_moved_to_an_institution(): void
    {
        $c = AiConversation::factory()->create();
        $this->actingAs(User::findOrFail($c->user_id))->post(route('ai.send', $c), ['institution_id' => Institution::factory()->create()->id] + $this->input())->assertStatus(422);
        $this->assertNull($c->fresh()->institution_id);
        Http::assertNothingSent();
    }

    public function test_cv_tailoring_uses_only_a_visible_selected_opportunity_without_inventing_facts(): void
    {
        $cv = CvDocument::factory()->create(['content' => ['summary' => 'Worked on a student project using Laravel.']]);
        $opportunity = Opportunity::factory()->create(['title' => 'Selected role', 'eligibility_text' => 'Laravel familiarity']);
        $content = ['reflection' => 'Emphasise the documented project.', 'questions' => [], 'assumptions' => [], 'risks' => [], 'alternatives' => [], 'next_action' => 'Review your wording.', 'original' => $cv->content['summary'], 'revision' => 'Worked on a student project with Laravel.', 'explanation' => 'Clarified wording only.'];
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($content)]]]])]);
        $u = User::findOrFail($cv->user_id);
        $this->actingAs($u)->post(route('ai.start'), ['feature' => 'cv', 'related_id' => $cv->id, 'fields' => ['summary'], 'target_opportunity_id' => $opportunity->id] + $this->input())->assertRedirect();
        Http::assertSent(fn ($r) => str_contains($r['messages'][1]['content'], 'Selected role'));
        $this->assertSame('Worked on a student project using Laravel.', $cv->fresh()->content['summary']);
    }

    public function test_retention_preserves_current_records_and_removes_expired_content(): void
    {
        $expired = AiConversation::factory()->create(['expires_at' => now()->subMinute()]);
        $retained = AiConversation::factory()->create();
        AiUsage::factory()->create(['created_at' => now()->subDays(366)]);
        AiUsage::factory()->create(['ai_conversation_id' => $expired->id]);
        $this->artisan('ideyra:ai:purge')->assertSuccessful();
        $this->assertSame($retained->id, AiConversation::sole()->id);
        $this->assertDatabaseCount('ai_usages', 1);
        $this->assertNull(AiUsage::sole()->ai_conversation_id);
    }
}
