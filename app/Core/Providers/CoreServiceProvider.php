<?php

namespace App\Core\Providers;

use App\Core\Console\Commands\ProjectAddMemberCommand;
use App\Core\Console\Commands\ProjectMembersCommand;
use App\Core\Console\Commands\ProjectRemoveMemberCommand;
use App\Core\Console\Commands\RoleSeedCommand;
use App\Core\Console\Commands\TokenCreateCommand;
use App\Core\Console\Commands\TokenListCommand;
use App\Core\Console\Commands\TokenRevokeCommand;
use App\Core\Console\Commands\UserCreateCommand;
use App\Core\Console\Commands\UserDeleteCommand;
use App\Core\Console\Commands\UserListCommand;
use App\Core\Console\Commands\UserUpdateCommand;
use App\Core\Http\Middleware\AuthenticateBearer;
use App\Core\Http\Middleware\EnsureModuleActive;
use App\Core\Http\Middleware\EnsureProjectAccess;
use App\Core\Mcp\Prompts\AgileWorkflowPrompt;
use App\Core\Mcp\Resources\ProjectConfigResource;
use App\Core\Mcp\Resources\ProjectOverviewResource;
use App\Core\Mcp\Server\McpServer;
use App\Core\Mcp\Server\McpTransport;
use App\Core\Mcp\Tools\ArtifactTools;
use App\Core\Mcp\Tools\DependencyTools;
use App\Core\Mcp\Tools\EpicTools;
use App\Core\Mcp\Tools\ModuleTools;
use App\Core\Mcp\Tools\ProjectTools;
use App\Core\Mcp\Tools\StoryTools;
use App\Core\Mcp\Tools\TaskTools;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\DependencyService;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../../config/core.php',
            'core'
        );

        $this->app->singleton(McpServer::class);
        $this->app->singleton(McpTransport::class);
        $this->app->singleton(DependencyService::class);
    }

    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('auth.bearer', AuthenticateBearer::class);
        $router->aliasMiddleware('project.access', EnsureProjectAccess::class);
        $router->aliasMiddleware('module.active', EnsureModuleActive::class);

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/mcp.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/oauth.php');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->registerMcpTools();
        $this->registerMcpResources();
        $this->registerMcpPrompts();

        if ($this->app->runningInConsole()) {
            $this->commands($this->coreCommands());
        }
    }

    private function registerMcpTools(): void
    {
        /** @var McpServer $server */
        $server = $this->app->make(McpServer::class);

        $server->registerCoreTools(new ProjectTools);
        $server->registerCoreTools(new EpicTools);
        $server->registerCoreTools(new StoryTools);
        $server->registerCoreTools(new TaskTools);
        $server->registerCoreTools(new ArtifactTools);
        $server->registerCoreTools(new ModuleTools(
            $this->app->make(ModuleRegistry::class)
        ));
        $server->registerCoreTools(new DependencyTools(
            $this->app->make(DependencyService::class)
        ));
    }

    private function registerMcpResources(): void
    {
        /** @var McpServer $server */
        $server = $this->app->make(McpServer::class);

        $server->registerCoreResource(new ProjectOverviewResource);
        $server->registerCoreResource(new ProjectConfigResource);
    }

    private function registerMcpPrompts(): void
    {
        /** @var McpServer $server */
        $server = $this->app->make(McpServer::class);

        $server->registerPrompt(new AgileWorkflowPrompt);
    }

    /** @return array<int, class-string> */
    private function coreCommands(): array
    {
        return [
            UserCreateCommand::class,
            UserListCommand::class,
            UserUpdateCommand::class,
            UserDeleteCommand::class,
            TokenCreateCommand::class,
            TokenListCommand::class,
            TokenRevokeCommand::class,
            ProjectMembersCommand::class,
            ProjectAddMemberCommand::class,
            ProjectRemoveMemberCommand::class,
            RoleSeedCommand::class,
        ];
    }
}
