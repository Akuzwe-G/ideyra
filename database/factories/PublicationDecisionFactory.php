<?php

namespace Database\Factories;

use App\Models\NetworkPublication;
use App\Models\PublicationDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublicationDecision> */
class PublicationDecisionFactory extends Factory
{
    public function definition(): array
    {
        return ['network_publication_id' => NetworkPublication::factory(), 'actor_id' => User::factory(), 'status' => 'returned', 'reason' => 'Clarify the approved projection.', 'version' => 1];
    }
}
