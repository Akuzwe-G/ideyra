<?php

namespace Database\Factories;

use App\Models\InnovationEvent;
use App\Models\Institution;
use App\Models\NetworkRecognition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NetworkRecognition> */
class NetworkRecognitionFactory extends Factory
{
    public function definition(): array
    {
        return ['institution_id' => Institution::factory(), 'user_id' => User::factory(), 'confirmed_by' => User::factory(), 'source_type' => 'event', 'source_id' => InnovationEvent::factory(), 'title' => 'Confirmed participation', 'evidence' => 'Attendance independently reviewed', 'status' => 'confirmed', 'confirmed_at' => now()];
    }
}
