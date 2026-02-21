<?php

declare(strict_types=1);

namespace App\Core\Providers;

use App\Core\Contracts\ModuleInterface;
use App\Core\Mcp\Server\McpServer;
use App\Core\Module\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);
        $server = $this->app->make(McpServer::class);

        $modules = config('modules', []);

        // Instantiate and register all modules
        $instances = [];
        foreach ($modules as $slug => $class) {
            if (! class_exists($class)) {
                continue;
            }

            /** @var ModuleInterface $module */
            $module = new $class;
            $registry->register($module);
            $instances[] = $module;
        }

        // Boot each module: routes, listeners, migrations, MCP tools
        foreach ($instances as $module) {
            $module->registerRoutes();
            $module->registerListeners();

            $migrationPath = $module->migrationPath();
            if ($migrationPath !== '') {
                $this->loadMigrationsFrom($migrationPath);
            }

            $mcpTools = $module->mcpTools();
            if ($mcpTools !== []) {
                $server->registerModuleTools($module->slug(), $mcpTools);
            }
        }
    }
}
