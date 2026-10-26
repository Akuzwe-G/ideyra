<?php

namespace App\Policies;

use App\Models\CollaborationRequest;
use App\Models\User;
use App\Support\NetworkAccess;

class CollaborationRequestPolicy
{
    public function view(User $user, CollaborationRequest $collaborationRequest): bool
    {
        return NetworkAccess::requestView($user, $collaborationRequest);
    }

    public function update(User $user, CollaborationRequest $collaborationRequest): bool
    {
        return $collaborationRequest->user_id === $user->id && NetworkAccess::requestView($user, $collaborationRequest);
    }
}
