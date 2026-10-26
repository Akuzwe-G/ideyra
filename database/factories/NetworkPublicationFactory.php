<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\NetworkPublication;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<NetworkPublication> */
class NetworkPublicationFactory extends Factory
{
    public function definition(): array
    {
        return ['identifier' => (string) Str::ulid(), 'project_id' => Project::factory(), 'institution_id' => Institution::factory(), 'submitted_by' => User::factory(), 'title' => fake()->sentence(4), 'summary' => fake()->sentence(), 'category' => 'Research', 'project_status' => 'in_progress', 'status' => 'draft', 'visibility' => 'private', 'draft' => [], 'version' => 1, 'collaboration_open' => false, 'institution_attribution' => false];
    }
}
