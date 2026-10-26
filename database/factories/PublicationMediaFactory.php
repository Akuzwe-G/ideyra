<?php

namespace Database\Factories;

use App\Models\NetworkPublication;
use App\Models\PublicationMedia;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublicationMedia> */
class PublicationMediaFactory extends Factory
{
    public function definition(): array
    {
        return ['network_publication_id' => NetworkPublication::factory(), 'uploaded_by' => User::factory(), 'path' => 'network/test/'.fake()->uuid().'.jpg', 'alt' => 'Prototype image', 'mime' => 'image/jpeg', 'bytes' => 100, 'approved' => false];
    }
}
