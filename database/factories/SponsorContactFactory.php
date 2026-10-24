<?php

namespace Database\Factories;

use App\Models\SponsorContact;
use App\Models\SponsorshipRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SponsorContact> */
class SponsorContactFactory extends Factory
{
    public function definition(): array
    {
        return ['sponsorship_request_id' => SponsorshipRequest::factory(), 'created_by' => User::factory(), 'name' => fake()->company(), 'email' => fake()->safeEmail(), 'type' => 'organisation'];
    }
}
