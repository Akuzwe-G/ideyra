<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SupervisionNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupervisionNote> */
class SupervisionNoteFactory extends Factory
{
    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'author_id' => User::factory(), 'visibility' => 'feedback', 'body' => fake()->paragraph()];
    }
}
