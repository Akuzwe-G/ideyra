<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\Programme;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Programme> */
class ProgrammeFactory extends Factory
{
    public function definition(): array
    {
        return ['institution_id' => Institution::factory(), 'name' => fake()->words(3, true), 'code' => fake()->unique()->bothify('PROG-####??'), 'status' => 'active'];
    }
}
