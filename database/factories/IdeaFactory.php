<?php

namespace Database\Factories;

use App\Models\Idea;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Idea> */
class IdeaFactory extends Factory
{
    public function definition(): array
    {
        return ['identifier' => (string) Str::ulid(), 'owner_id' => User::factory(), 'title' => fake()->sentence(4), 'description' => fake()->paragraph(), 'problem' => fake()->paragraph(), 'solution' => fake()->paragraph(), 'category' => 'Research', 'visibility' => 'private', 'status' => 'draft', 'tags' => []];
    }
}
