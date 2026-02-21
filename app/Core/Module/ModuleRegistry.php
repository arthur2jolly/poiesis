<?php

declare(strict_types=1);

namespace App\Core\Module;

use App\Core\Contracts\ModuleInterface;

class ModuleRegistry
{
    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    public function register(ModuleInterface $module): void
    {
        $this->modules[$module->slug()] = $module;
    }

    public function get(string $slug): ?ModuleInterface
    {
        return $this->modules[$slug] ?? null;
    }

    /** @return array<string, ModuleInterface> */
    public function all(): array
    {
        return $this->modules;
    }

    public function isRegistered(string $slug): bool
    {
        return isset($this->modules[$slug]);
    }

    /** @return array<int, string> */
    public function getDependenciesFor(string $slug): array
    {
        $module = $this->get($slug);

        return $module ? $module->dependencies() : [];
    }

    /**
     * Find all registered modules that depend on the given slug.
     *
     * @return array<int, string>
     */
    public function getDependentsOf(string $slug): array
    {
        $dependents = [];

        foreach ($this->modules as $module) {
            if (in_array($slug, $module->dependencies(), true)) {
                $dependents[] = $module->slug();
            }
        }

        return $dependents;
    }
}
