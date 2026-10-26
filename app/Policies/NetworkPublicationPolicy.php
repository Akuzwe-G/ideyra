<?php

namespace App\Policies;

use App\Models\NetworkPublication;
use App\Models\User;
use App\Support\NetworkAccess;

class NetworkPublicationPolicy
{
    public function view(?User $user, NetworkPublication $publication): bool
    {
        return NetworkAccess::publicationVisible($user, $publication);
    }

    public function update(User $user, NetworkPublication $publication): bool
    {
        return NetworkAccess::publicationEdit($user, $publication);
    }

    public function review(User $user, NetworkPublication $publication): bool
    {
        return NetworkAccess::publicationReview($user, $publication);
    }

    public function unpublish(User $user, NetworkPublication $publication): bool
    {
        return NetworkAccess::publicationUnpublish($user, $publication);
    }
}
