<?php

namespace App\Policies;

use App\Models\CollaborationWorkspace;
use App\Models\User;
use App\Support\NetworkAccess;

class CollaborationWorkspacePolicy
{
    public function view(User $user, CollaborationWorkspace $workspace): bool
    {
        return (bool) NetworkAccess::member($user, $workspace);
    }

    public function update(User $user, CollaborationWorkspace $workspace): bool
    {
        return $workspace->status === 'active' && (bool) NetworkAccess::member($user, $workspace, true);
    }
}
