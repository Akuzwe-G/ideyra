<?php

namespace App\Policies;

use App\Models\InnovationEvent;
use App\Models\User;
use App\Support\NetworkAccess;

class InnovationEventPolicy
{
    public function view(?User $user, InnovationEvent $event): bool
    {
        return NetworkAccess::events($user)->whereKey($event->id)->exists();
    }

    public function update(User $user, InnovationEvent $event): bool
    {
        return NetworkAccess::eventManage($user, $event);
    }
}
