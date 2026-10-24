<?php

namespace Database\Factories;

use App\Models\Deliverable;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Deliverable> */
class DeliverableFactory extends Factory
{
    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'assigned_user_id' => fn (array $a) => Project::findOrFail($a['project_id'])->owner_id, 'title' => fake()->sentence(3), 'due_at' => today()->addWeek(), 'status' => 'pending'];
    }
}
