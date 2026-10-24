<?php

namespace App\Policies;

use App\Models\ProjectGroup;
use App\Models\User;
use App\Support\InnovationAccess;
use Illuminate\Auth\Access\Response;

class ProjectGroupPolicy
{
    public function view(User $user, ProjectGroup $projectGroup): Response
    {
        return InnovationAccess::groups($user)->whereKey($projectGroup->id)->exists() ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, ProjectGroup $projectGroup): bool
    {
        return InnovationAccess::groupManage($projectGroup, $user);
    }
}
