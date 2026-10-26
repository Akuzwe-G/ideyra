<?php

namespace Database\Factories;

use App\Models\CollaborationEntry;
use App\Models\CollaborationWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CollaborationEntry> */
class CollaborationEntryFactory extends Factory
{
    public function definition(): array
    {
        return ['collaboration_workspace_id' => CollaborationWorkspace::factory(), 'user_id' => User::factory(), 'kind' => 'thread', 'title' => fake()->sentence(4), 'body' => 'Explicitly shared content', 'status' => 'open'];
    }
}
