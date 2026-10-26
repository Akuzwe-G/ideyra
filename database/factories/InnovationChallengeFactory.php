<?php

namespace Database\Factories;

use App\Models\InnovationChallenge;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InnovationChallenge> */
class InnovationChallengeFactory extends Factory
{
    public function definition(): array
    {
        return ['opportunity_id' => Opportunity::factory()->state(['institution_id' => Institution::factory(), 'network_type' => 'challenge']), 'institution_id' => fn (array $a) => Opportunity::findOrFail($a['opportunity_id'])->institution_id, 'created_by' => User::factory(), 'kind' => 'competition', 'visibility' => 'institution', 'status' => 'draft', 'rules' => 'Test challenge rules', 'allow_cross_institution' => false, 'results_state' => 'preliminary'];
    }
}
