<?php

namespace Database\Factories;

use App\Models\OpportunityCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OpportunityCategory> */
class OpportunityCategoryFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(3, true), 'slug' => fake()->unique()->slug(), 'active' => true];
    }
}
