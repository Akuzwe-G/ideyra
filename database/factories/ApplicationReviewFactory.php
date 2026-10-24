<?php

namespace Database\Factories;

use App\Models\ApplicationReview;
use App\Models\OpportunityApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApplicationReview> */
class ApplicationReviewFactory extends Factory
{
    public function definition(): array
    {
        return ['application_id' => OpportunityApplication::factory(), 'reviewer_id' => User::factory(), 'decision' => 'under_review', 'feedback' => fake()->paragraph()];
    }
}
