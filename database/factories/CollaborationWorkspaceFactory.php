<?php

namespace Database\Factories;

use App\Models\CollaborationRequest;
use App\Models\CollaborationWorkspace;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CollaborationWorkspace> */
class CollaborationWorkspaceFactory extends Factory
{
    public function definition(): array
    {
        return ['identifier' => (string) Str::ulid(), 'collaboration_request_id' => CollaborationRequest::factory(), 'network_publication_id' => fn (array $a) => CollaborationRequest::findOrFail($a['collaboration_request_id'])->network_publication_id, 'institution_id' => Institution::factory(), 'created_by' => User::factory(), 'title' => 'Test collaboration workspace', 'purpose' => 'Explicit shared information only', 'institution_ids' => fn (array $a) => [(int) $a['institution_id']], 'starts_at' => today(), 'status' => 'active'];
    }
}
