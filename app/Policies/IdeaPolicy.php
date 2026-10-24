<?php

namespace App\Policies;

use App\Models\Idea;
use App\Models\User;
use App\Support\InnovationAccess;
use Illuminate\Auth\Access\Response;

class IdeaPolicy
{
    public function view(User $user, Idea $idea): Response
    {
        return InnovationAccess::ideas($user)->whereKey($idea->id)->exists() ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Idea $idea): bool
    {
        return InnovationAccess::ideas($user)->whereKey($idea->id)->exists() && (int) $idea->owner_id === (int) $user->id && (! $idea->institution_id || InnovationAccess::can($idea->institution, 'ideas.edit_own', $user));
    }

    public function review(User $user, Idea $idea): bool
    {
        return InnovationAccess::ideas($user)->whereKey($idea->id)->exists() && $idea->institution_id && $idea->visibility === 'institutional' && (int) $idea->owner_id !== (int) $user->id
            && InnovationAccess::can($idea->institution, 'ideas.review', $user)
            && ((int) $idea->reviewer_id === (int) $user->id || InnovationAccess::can($idea->institution, 'ideas.review_all', $user));
    }
}
