<?php

namespace Database\Factories;

use App\Models\InstitutionKpi;
use App\Models\KpiObservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KpiObservation> */
class KpiObservationFactory extends Factory
{
    public function definition(): array
    {
        return ['institution_kpi_id' => InstitutionKpi::factory()->state(['source' => 'manual', 'metric' => 'manual']), 'reported_by' => User::factory(), 'value' => 5, 'observed_on' => today(), 'evidence' => 'Test-only observation evidence.'];
    }
}
