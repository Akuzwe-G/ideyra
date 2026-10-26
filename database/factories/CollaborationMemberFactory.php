<?php

namespace Database\Factories;

use App\Models\CollaborationMember;
use App\Models\CollaborationWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CollaborationMember> */
class CollaborationMemberFactory extends Factory
{
    public function definition(): array
    {
        return ['collaboration_workspace_id' => CollaborationWorkspace::factory(), 'user_id' => User::factory(), 'institution_id' => fn (array $a) => CollaborationWorkspace::findOrFail($a['collaboration_workspace_id'])->institution_id, 'invited_by' => User::factory(), 'role' => 'member', 'status' => 'invited'];
    }
}
