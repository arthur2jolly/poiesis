<?php

declare(strict_types=1);

namespace App\Policies;

use App\Core\Models\Task;
use App\Core\Models\User;
use App\Core\Support\Role;

class TaskPolicy
{
    /**
     * Determine if the user can view the task.
     */
    public function view(User $user, Task $task): bool
    {
        // All authenticated users can view tasks if they're members of the project
        if ($task->story_id) {
            // Task belongs to a story
            return $user->projects->contains($task->story->epic->project_id);
        }

        // Standalone task - check if user is member of any project (simplified)
        return true;
    }

    /**
     * Determine if the user can create tasks.
     */
    public function create(User $user): bool
    {
        return Role::canCrudArtifacts($user->role);
    }

    /**
     * Determine if the user can update the task.
     */
    public function update(User $user, Task $task): bool
    {
        if (! Role::canCrudArtifacts($user->role)) {
            return false;
        }

        if ($task->story_id) {
            return $user->projects->contains($task->story->epic->project_id);
        }

        return true;
    }

    /**
     * Determine if the user can delete the task.
     */
    public function delete(User $user, Task $task): bool
    {
        if (! Role::canCrudArtifacts($user->role)) {
            return false;
        }

        if ($task->story_id) {
            return $user->projects->contains($task->story->epic->project_id);
        }

        return true;
    }
}
