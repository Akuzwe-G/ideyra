<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Institution;
use App\Models\Intake;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Intake> */
class IntakeFactory extends Factory
{
    public function definition(): array
    {
        return ['institution_id' => Institution::factory(), 'academic_year_id' => fn (array $attributes) => AcademicYear::factory()->create(['institution_id' => $attributes['institution_id']])->id, 'name' => fake()->unique()->bothify('Intake ####??'), 'starts_at' => '2026-08-01', 'status' => 'active'];
    }
}
