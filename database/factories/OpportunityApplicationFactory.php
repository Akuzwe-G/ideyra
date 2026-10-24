<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\OpportunityApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OpportunityApplication> */
class OpportunityApplicationFactory extends Factory
{
    public function definition(): array
    {
        return ['identifier' => (string) Str::ulid(), 'opportunity_id' => Opportunity::factory(), 'applicant_id' => User::factory(), 'institution_id' => Institution::factory(), 'subject_key' => fn (array $a) => 'user:'.$a['applicant_id'], 'motivation' => fake()->paragraph(), 'status' => 'draft', 'participant_ids' => fn (array $a) => [$a['applicant_id']]];
    }
}
