<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectTask> */
class ProjectTaskFactory extends Factory
{
    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'created_by' => User::factory(), 'title' => fake()->sentence(4), 'priority' => 'medium', 'status' => 'to_do', 'due_at' => today()->addWeek()];
    }
}
