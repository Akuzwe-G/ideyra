<?php

namespace Database\Factories;

use App\Models\Opportunity;
use App\Models\OpportunityCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Opportunity> */
class OpportunityFactory extends Factory
{
    public function definition(): array
    {
        return ['identifier' => (string) Str::ulid(), 'category_id' => OpportunityCategory::factory(), 'created_by' => User::factory(), 'title' => fake()->sentence(4), 'summary' => fake()->sentence(), 'description' => fake()->paragraph(), 'organiser' => fake()->company(), 'source_attribution' => 'Organiser-provided test fixture', 'instructions' => fake()->paragraph(), 'eligibility_text' => 'Active student membership required.', 'application_method' => 'internal', 'delivery_mode' => 'online', 'visibility' => 'platform', 'status' => 'published', 'published_at' => now(), 'deadline_at' => now()->addWeek(), 'eligibility' => []];
    }
}
