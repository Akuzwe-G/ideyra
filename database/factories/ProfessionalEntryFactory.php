<?php

namespace Database\Factories;

use App\Models\ProfessionalEntry;
use App\Models\ProfessionalProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProfessionalEntry> */
class ProfessionalEntryFactory extends Factory
{
    public function definition(): array
    {
        return ['professional_profile_id' => ProfessionalProfile::factory(), 'type' => 'skill', 'title' => 'Research', 'category' => 'Research', 'content' => ['description' => 'Evidence-based project research']];
    }
}
