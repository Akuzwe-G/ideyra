<?php

namespace App\Policies;

use App\Models\SponsorshipRequest;
use App\Models\User;
use App\Support\InnovationAccess;
use App\Support\OpportunityAccess;
use Illuminate\Auth\Access\Response;

class SponsorshipRequestPolicy
{
    public function view(User $user, SponsorshipRequest $sponsorship): Response
    {
        return OpportunityAccess::sponsorships($user)->whereKey($sponsorship->id)->exists() ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, SponsorshipRequest $sponsorship): bool
    {
        return InnovationAccess::projectManage($sponsorship->project, $user) && ($sponsorship->requested_by === $user->id || OpportunityAccess::staff($sponsorship->project, $user, 'sponsorships.manage'));
    }

    public function review(User $user, SponsorshipRequest $sponsorship): bool
    {
        return $sponsorship->requested_by !== $user->id && OpportunityAccess::staff($sponsorship->project, $user, 'sponsorships.review');
    }

    public function manage(User $user, SponsorshipRequest $sponsorship): bool
    {
        return OpportunityAccess::staff($sponsorship->project, $user, 'sponsorships.manage');
    }
}
