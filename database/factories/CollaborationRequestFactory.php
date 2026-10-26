<?php

namespace Database\Factories;

use App\Models\CollaborationRequest;
use App\Models\Institution;
use App\Models\NetworkPublication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CollaborationRequest> */
class CollaborationRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['identifier' => (string) Str::ulid(), 'network_publication_id' => NetworkPublication::factory(), 'user_id' => User::factory(), 'institution_id' => Institution::factory(), 'representative_id' => User::factory(), 'type' => 'technical', 'motivation' => 'Test-only collaboration request.', 'skills' => 'Research and development', 'contribution' => 'Build an explicit shared prototype.', 'status' => 'draft', 'expires_at' => now()->addDays(30)];
    }
}
