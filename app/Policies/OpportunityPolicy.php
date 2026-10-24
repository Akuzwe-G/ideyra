<?php

namespace App\Policies;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\OpportunityAccess;
use Illuminate\Auth\Access\Response;

class OpportunityPolicy
{
    public function view(User $user, Opportunity $opportunity): Response
    {
        return OpportunityAccess::visible($user)->whereKey($opportunity->id)->exists() ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        return OpportunityAccess::visible($user)->whereKey($opportunity->id)->exists() && (OpportunityAccess::manages($opportunity, $user) || ($opportunity->created_by === $user->id && OpportunityAccess::permission($opportunity, 'opportunities.edit_own', $user)));
    }

    public function review(User $user, Opportunity $opportunity): bool
    {
        return OpportunityAccess::manages($opportunity, $user, 'opportunities.approve');
    }

    public function publish(User $user, Opportunity $opportunity): bool
    {
        return OpportunityAccess::manages($opportunity, $user, 'opportunities.publish');
    }
}
