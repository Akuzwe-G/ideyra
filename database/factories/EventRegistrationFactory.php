<?php

namespace Database\Factories;

use App\Models\EventRegistration;
use App\Models\InnovationEvent;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventRegistration> */
class EventRegistrationFactory extends Factory
{
    public function definition(): array
    {
        return ['innovation_event_id' => InnovationEvent::factory(), 'user_id' => User::factory(), 'institution_id' => Institution::factory(), 'status' => 'registered', 'registered_at' => now()];
    }
}
