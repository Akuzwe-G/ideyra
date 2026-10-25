<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\InstitutionKpi;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InstitutionKpi> */
class InstitutionKpiFactory extends Factory
{
    public function definition(): array
    {
        return ['institution_id' => Institution::factory(), 'owner_id' => User::factory(), 'created_by' => User::factory(), 'title' => fake()->sentence(4), 'category' => 'Innovation', 'metric' => 'completed_projects', 'source' => 'automatic', 'unit' => 'Projects', 'period_from' => today()->startOfMonth(), 'period_to' => today()->endOfMonth(), 'target' => 10, 'direction' => 'increase', 'lifecycle' => 'draft', 'filters' => ['from' => today()->startOfMonth()->toDateString(), 'to' => today()->endOfMonth()->toDateString()], 'version' => 1];
    }
}
