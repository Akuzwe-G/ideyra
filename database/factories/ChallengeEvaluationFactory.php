<?php

namespace Database\Factories;

use App\Models\ChallengeEvaluation;
use App\Models\ChallengeSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChallengeEvaluation> */
class ChallengeEvaluationFactory extends Factory
{
    public function definition(): array
    {
        return ['challenge_submission_id' => ChallengeSubmission::factory(), 'user_id' => User::factory(), 'scores' => [], 'feedback' => 'Private assessment', 'total' => 0, 'status' => 'draft'];
    }
}
