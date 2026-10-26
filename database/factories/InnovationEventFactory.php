<?php

namespace Database\Factories;

use App\Models\InnovationEvent;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InnovationEvent> */
class InnovationEventFactory extends Factory
{
    public function definition(): array
    {
        return ['opportunity_id' => Opportunity::factory()->state(['institution_id' => Institution::factory(), 'network_type' => 'event']), 'institution_id' => fn (array $a) => Opportunity::findOrFail($a['opportunity_id'])->institution_id, 'created_by' => User::factory(), 'title' => 'Test exhibition', 'description' => 'A controlled innovation exhibition.', 'organiser' => 'Test institution', 'kind' => 'exhibition', 'venue' => 'Innovation studio', 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(2), 'timezone' => 'Indian/Mauritius', 'registration_rules' => 'Active institutional membership required.', 'agenda' => [], 'visibility' => 'institution', 'status' => 'draft'];
    }
}
