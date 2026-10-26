<?php

namespace Database\Factories;

use App\Models\ChallengeCriterion;
use App\Models\InnovationChallenge;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChallengeCriterion> */
class ChallengeCriterionFactory extends Factory
{
    public function definition(): array
    {
        return ['innovation_challenge_id' => InnovationChallenge::factory(), 'name' => 'Innovation', 'weight' => 100];
    }
}
