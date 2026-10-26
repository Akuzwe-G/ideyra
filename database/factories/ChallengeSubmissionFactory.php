<?php

namespace Database\Factories;

use App\Models\ChallengeSubmission;
use App\Models\InnovationChallenge;
use App\Models\OpportunityApplication;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChallengeSubmission> */
class ChallengeSubmissionFactory extends Factory
{
    public function definition(): array
    {
        return ['innovation_challenge_id' => InnovationChallenge::factory(), 'opportunity_application_id' => OpportunityApplication::factory(), 'project_id' => Project::factory(), 'snapshot' => ['title' => 'Explicit private entry', 'summary' => 'A submitted prototype', 'description' => 'Authorised submitted material', 'participant_ids' => []], 'version' => 1, 'submitted_at' => now(), 'status' => 'submitted'];
    }
}
