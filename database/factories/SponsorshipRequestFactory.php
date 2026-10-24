<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SponsorshipRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SponsorshipRequest> */
class SponsorshipRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['identifier' => (string) Str::ulid(), 'project_id' => Project::factory(), 'requested_by' => fn (array $a) => Project::findOrFail($a['project_id'])->owner_id, 'support_type' => 'equipment', 'description' => fake()->paragraph(), 'justification' => fake()->paragraph(), 'intended_use' => fake()->sentence(), 'status' => 'draft'];
    }
}
