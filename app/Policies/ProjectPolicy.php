<?php

declare(strict_types=1);

namespace App\Policies;

use App\Core\Models\Project;
use App\Core\Models\User;
use App\Core\Support\Role;

class ProjectPolicy
{
    /**
     * Determine if the user can view the project.
     */
    public function view(User $user, Project $project): bool
    {
        // Users can view projects they're members of
        return $user->projects->contains($project->id);
    }

    /**
     * Determine if the user can create projects.
     */
    public function create(User $user): bool
    {
        return Role::canCrudProjects($user->role);
    }

    /**
     * Determine if the user can update the project.
     */
    public function update(User $user, Project $project): bool
    {
        return Role::canCrudProjects($user->role) &&
               $user->projects->contains($project->id);
    }

    /**
     * Determine if the user can delete the project.
     */
    public function delete(User $user, Project $project): bool
    {
        return Role::canCrudProjects($user->role) &&
               $user->projects->contains($project->id);
    }

    /**
     * Determine if the user can manage project members.
     */
    public function managemembers(User $user, Project $project): bool
    {
        // Only managers and administrators can manage project members
        return Role::isManagerOrAbove($user->role) &&
               $user->projects->contains($project->id);
    }

    /**
     * Determine if the user can manage project modules.
     */
    public function manageModules(User $user, Project $project): bool
    {
        // Only managers and administrators can manage modules
        return Role::isManagerOrAbove($user->role) &&
               $user->projects->contains($project->id);
    }
}
