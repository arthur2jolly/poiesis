<?php

declare(strict_types=1);

namespace App\Policies;

use App\Core\Models\Artifact;
use App\Core\Models\User;
use App\Core\Support\Role;

class ArtifactPolicy
{
    /**
     * Determine if the user can view the artifact.
     */
    public function view(User $user, Artifact $artifact): bool
    {
        // All authenticated users can view artifacts if they're members of the project
        return $user->projects->contains(function ($project) use ($artifact) {
            return $project->id === $artifact->epic->project_id;
        });
    }

    /**
     * Determine if the user can create artifacts.
     */
    public function create(User $user): bool
    {
        return Role::canCrudArtifacts($user->role);
    }

    /**
     * Determine if the user can update the artifact.
     */
    public function update(User $user, Artifact $artifact): bool
    {
        return Role::canCrudArtifacts($user->role) &&
               $user->projects->contains($artifact->epic->project_id);
    }

    /**
     * Determine if the user can delete the artifact.
     */
    public function delete(User $user, Artifact $artifact): bool
    {
        return Role::canCrudArtifacts($user->role) &&
               $user->projects->contains($artifact->epic->project_id);
    }
}
