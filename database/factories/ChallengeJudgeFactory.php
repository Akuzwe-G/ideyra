<?php

namespace Database\Factories;

use App\Models\ChallengeJudge;
use App\Models\InnovationChallenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChallengeJudge> */
class ChallengeJudgeFactory extends Factory
{
    public function definition(): array
    {
        return ['innovation_challenge_id' => InnovationChallenge::factory(), 'user_id' => User::factory(), 'assigned_by' => User::factory(), 'active' => true];
    }
}
