<?php

namespace Database\Factories;

use App\Models\AcademicClass;
use App\Models\Institution;
use App\Models\Intake;
use App\Models\Programme;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicClass> */
class AcademicClassFactory extends Factory
{
    public function definition(): array
    {
        return ['institution_id' => Institution::factory(), 'programme_id' => fn (array $attributes) => Programme::factory()->create(['institution_id' => $attributes['institution_id']])->id, 'intake_id' => fn (array $attributes) => Intake::factory()->create(['institution_id' => $attributes['institution_id']])->id, 'academic_year_id' => fn (array $attributes) => Intake::findOrFail($attributes['intake_id'])->academic_year_id, 'name' => fake()->unique()->bothify('Class ####??'), 'status' => 'active'];
    }
}
