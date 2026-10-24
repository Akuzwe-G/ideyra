<?php

namespace App\Policies;

use App\Models\OpportunityApplication;
use App\Models\User;
use App\Support\OpportunityAccess;
use Illuminate\Auth\Access\Response;

class OpportunityApplicationPolicy
{
    public function view(User $user, OpportunityApplication $application): Response
    {
        return OpportunityAccess::applications($user)->whereKey($application->id)->exists() ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, OpportunityApplication $application): bool
    {
        return $application->applicant_id === $user->id && OpportunityAccess::applications($user)->whereKey($application->id)->exists();
    }

    public function review(User $user, OpportunityApplication $application): bool
    {
        return OpportunityAccess::reviews($application, $user);
    }
}
