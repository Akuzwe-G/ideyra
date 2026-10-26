<?php

namespace Database\Factories;

use App\Models\ExhibitionProject;
use App\Models\InnovationEvent;
use App\Models\NetworkPublication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExhibitionProject> */
class ExhibitionProjectFactory extends Factory
{
    public function definition(): array
    {
        return ['innovation_event_id' => InnovationEvent::factory(), 'network_publication_id' => NetworkPublication::factory(), 'representative_id' => User::factory(), 'category' => 'Research', 'sort_order' => 0];
    }
}
