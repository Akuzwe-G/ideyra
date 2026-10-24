<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectActivity> */
class ProjectActivityFactory extends Factory
{
    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'actor_id' => User::factory(), 'action' => 'project.created', 'metadata' => []];
    }
}
