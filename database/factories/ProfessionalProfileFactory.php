<?php

namespace Database\Factories;

use App\Models\ProfessionalProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProfessionalProfile> */
class ProfessionalProfileFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'display_name' => fake()->name(), 'headline' => 'Student innovator', 'biography' => 'Building thoughtful projects.'];
    }
}
