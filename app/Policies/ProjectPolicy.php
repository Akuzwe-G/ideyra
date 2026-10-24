<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\InnovationAccess;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    public function view(User $user, Project $project): Response
    {
        return InnovationAccess::projects($user)->whereKey($project->id)->exists() ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Project $project): bool
    {
        return InnovationAccess::projectManage($project, $user);
    }

    public function review(User $user, Project $project): bool
    {
        return $project->institution_id && (int) $project->owner_id !== (int) $user->id && InnovationAccess::projects($user)->whereKey($project->id)->exists() && InnovationAccess::can($project->institution, 'projects.approve', $user) && InnovationAccess::can($project->institution, 'projects.review', $user);
    }

    public function supervise(User $user, Project $project): bool
    {
        return InnovationAccess::supervisor($project, $user);
    }
}
