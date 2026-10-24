<?php

namespace Database\Factories;

use App\Models\Portfolio;
use App\Models\ProfessionalProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Portfolio> */
class PortfolioFactory extends Factory
{
    public function definition(): array
    {
        return ['professional_profile_id' => ProfessionalProfile::factory(), 'slug' => 'portfolio-'.strtolower((string) Str::ulid()), 'theme' => 'minimal', 'sections' => ['biography'], 'entry_ids' => [], 'showcase_ids' => [], 'contact_fields' => [], 'published' => false];
    }
}
