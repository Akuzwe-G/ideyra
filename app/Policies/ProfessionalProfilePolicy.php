<?php

namespace App\Policies;

use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Support\CareerAccess;

class ProfessionalProfilePolicy
{
    public function view(User $user, ProfessionalProfile $profile): bool
    {
        return CareerAccess::own($user, $profile->user_id);
    }

    public function update(User $user, ProfessionalProfile $profile): bool
    {
        return $this->view($user, $profile);
    }
}
