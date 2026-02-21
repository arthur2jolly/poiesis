<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Policies\ArtifactPolicy;
use App\Policies\EpicPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\StoryPolicy;
use App\Policies\TaskPolicy;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Artifact::class => ArtifactPolicy::class,
        Epic::class => EpicPolicy::class,
        Story::class => StoryPolicy::class,
        Task::class => TaskPolicy::class,
        Project::class => ProjectPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
