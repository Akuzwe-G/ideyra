<?php

namespace Database\Factories;

use App\Models\CollaborationFile;
use App\Models\CollaborationWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CollaborationFile> */
class CollaborationFileFactory extends Factory
{
    public function definition(): array
    {
        return ['collaboration_workspace_id' => CollaborationWorkspace::factory(), 'user_id' => User::factory(), 'path' => 'network/test/'.fake()->uuid().'.txt', 'name' => 'shared-note.txt', 'mime' => 'text/plain', 'bytes' => 100];
    }
}
