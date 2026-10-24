<?php

namespace Database\Factories;

use App\Models\ProfessionalProfile;
use App\Models\Project;
use App\Models\ProjectShowcase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectShowcase> */
class ProjectShowcaseFactory extends Factory
{
    public function definition(): array
    {
        return ['professional_profile_id' => ProfessionalProfile::factory(), 'project_id' => Project::factory(), 'reflection' => 'My contribution reflection', 'technologies' => 'PHP'];
    }
}
