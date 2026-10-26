<?php

namespace Database\Factories;

use App\Models\CollaborationApproval;
use App\Models\CollaborationRequest;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CollaborationApproval> */
class CollaborationApprovalFactory extends Factory
{
    public function definition(): array
    {
        return ['collaboration_request_id' => CollaborationRequest::factory(), 'institution_id' => Institution::factory(), 'status' => 'pending'];
    }
}
