<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicYear> */
class AcademicYearFactory extends Factory
{
    public function definition(): array
    {
        return ['institution_id' => Institution::factory(), 'name' => 'Year '.fake()->unique()->numberBetween(100, 999999), 'starts_at' => '2026-08-01', 'ends_at' => '2027-07-31', 'status' => 'active'];
    }
}
