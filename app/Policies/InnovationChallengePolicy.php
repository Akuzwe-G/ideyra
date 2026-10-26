<?php

namespace App\Policies;

use App\Models\InnovationChallenge;
use App\Models\User;
use App\Support\NetworkAccess;

class InnovationChallengePolicy
{
    public function view(?User $user, InnovationChallenge $challenge): bool
    {
        return NetworkAccess::challenges($user)->whereKey($challenge->id)->exists();
    }

    public function update(User $user, InnovationChallenge $challenge): bool
    {
        return NetworkAccess::challengeManage($user, $challenge);
    }

    public function judge(User $user, InnovationChallenge $challenge): bool
    {
        return NetworkAccess::judge($user, $challenge);
    }
}
