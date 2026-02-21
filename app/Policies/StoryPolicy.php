<?php

declare(strict_types=1);

namespace App\Policies;

use App\Core\Models\Story;
use App\Core\Models\User;
use App\Core\Support\Role;

class StoryPolicy
{
    /**
     * Determine if the user can view the story.
     */
    public function view(User $user, Story $story): bool
    {
        // All authenticated users can view stories if they're members of the project
        return $user->projects->contains($story->epic->project_id);
    }

    /**
     * Determine if the user can create stories.
     */
    public function create(User $user): bool
    {
        return Role::canCrudArtifacts($user->role);
    }

    /**
     * Determine if the user can update the story.
     */
    public function update(User $user, Story $story): bool
    {
        return Role::canCrudArtifacts($user->role) &&
               $user->projects->contains($story->epic->project_id);
    }

    /**
     * Determine if the user can delete the story.
     */
    public function delete(User $user, Story $story): bool
    {
        return Role::canCrudArtifacts($user->role) &&
               $user->projects->contains($story->epic->project_id);
    }
}
