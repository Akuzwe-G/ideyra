<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Institution> */
class InstitutionFactory extends Factory
{
    public function definition(): array
    {
        return ['identifier' => (string) Str::uuid(), 'name' => fake()->company(), 'slug' => fake()->unique()->slug(), 'type' => 'university', 'country' => 'Rwanda', 'region' => 'Kigali', 'city' => 'Kigali', 'contact_email' => fake()->safeEmail(), 'student_population' => 1000, 'status' => 'active', 'created_by' => User::factory()];
    }
}
