<?php

namespace Database\Factories;

use App\Models\SponsorContact;
use App\Models\SponsorshipCommitment;
use App\Models\SponsorshipRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SponsorshipCommitment> */
class SponsorshipCommitmentFactory extends Factory
{
    public function definition(): array
    {
        return ['sponsorship_request_id' => SponsorshipRequest::factory(), 'sponsor_contact_id' => fn (array $a) => SponsorContact::factory()->create(['sponsorship_request_id' => $a['sponsorship_request_id']])->id, 'created_by' => User::factory(), 'offered_support' => fake()->sentence(), 'status' => 'proposed'];
    }
}
