<?php

declare(strict_types=1);

namespace App\Policies;

use App\Core\Models\Epic;
use App\Core\Models\User;
use App\Core\Support\Role;

class EpicPolicy
{
    /**
     * Determine if the user can view the epic.
     */
    public function view(User $user, Epic $epic): bool
    {
        // All authenticated users can view epics if they're members of the project
        return $user->projects->contains($epic->project_id);
    }

    /**
     * Determine if the user can create epics.
     */
    public function create(User $user): bool
    {
        return Role::canCrudArtifacts($user->role);
    }

    /**
     * Determine if the user can update the epic.
     */
    public function update(User $user, Epic $epic): bool
    {
        return Role::canCrudArtifacts($user->role) &&
               $user->projects->contains($epic->project_id);
    }

    /**
     * Determine if the user can delete the epic.
     */
    public function delete(User $user, Epic $epic): bool
    {
        return Role::canCrudArtifacts($user->role) &&
               $user->projects->contains($epic->project_id);
    }
}
