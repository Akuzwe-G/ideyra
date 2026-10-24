<?php

namespace Database\Factories;

use App\Models\PortfolioMedia;
use App\Models\ProjectShowcase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PortfolioMedia> */
class PortfolioMediaFactory extends Factory
{
    public function definition(): array
    {
        return ['project_showcase_id' => ProjectShowcase::factory(), 'path' => 'portfolio-media/fixture.png', 'alt' => 'Project screenshot', 'mime' => 'image/png', 'public_approved' => false];
    }
}
