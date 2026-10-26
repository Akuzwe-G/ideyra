<?php

namespace Database\Factories;

use App\Models\NetworkPublication;
use App\Models\PublicationConsent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublicationConsent> */
class PublicationConsentFactory extends Factory
{
    public function definition(): array
    {
        return ['network_publication_id' => NetworkPublication::factory(), 'user_id' => User::factory(), 'version' => 1];
    }
}
