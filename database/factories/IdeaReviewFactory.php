<?php

namespace Database\Factories;

use App\Models\Idea;
use App\Models\IdeaReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IdeaReview> */
class IdeaReviewFactory extends Factory
{
    public function definition(): array
    {
        return ['idea_id' => Idea::factory(), 'reviewer_id' => User::factory(), 'decision' => 'returned', 'feedback' => fake()->paragraph()];
    }
}
