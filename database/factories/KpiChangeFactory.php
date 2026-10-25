<?php

namespace Database\Factories;

use App\Models\InstitutionKpi;
use App\Models\KpiChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KpiChange> */
class KpiChangeFactory extends Factory
{
    public function definition(): array
    {
        return ['institution_kpi_id' => InstitutionKpi::factory(), 'requested_by' => User::factory(), 'status' => 'pending', 'previous' => ['target' => 10], 'proposed' => ['target' => 15], 'reason' => 'Review the documented reporting objective.', 'base_version' => 1];
    }
}
