<?php

namespace Database\Factories;

use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Milestone> */
class MilestoneFactory extends Factory
{
    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'title' => fake()->sentence(3), 'due_at' => today()->addWeek(), 'status' => 'not_started', 'sort_order' => 0];
    }
}
