<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return ['identifier' => (string) Str::ulid(), 'owner_id' => User::factory(), 'title' => fake()->sentence(4), 'description' => fake()->paragraph(), 'problem' => fake()->paragraph(), 'objectives' => fake()->paragraph(), 'outcomes' => fake()->paragraph(), 'category' => 'Research', 'status' => 'draft'];
    }
}
