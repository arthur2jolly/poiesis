<?php

namespace Tests\Unit\Core\Module;

use App\Core\Contracts\ModuleInterface;
use App\Core\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;

class ModuleRegistryTest extends TestCase
{
    private ModuleRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ModuleRegistry;
    }

    public function test_register_adds_module(): void
    {
        $module = $this->createModule('example');

        $this->registry->register($module);

        $this->assertTrue($this->registry->isRegistered('example'));
        $this->assertSame($module, $this->registry->get('example'));
    }

    public function test_get_returns_null_for_unregistered(): void
    {
        $this->assertNull($this->registry->get('nonexistent'));
    }

    public function test_all_returns_all_registered_modules(): void
    {
        $a = $this->createModule('alpha');
        $b = $this->createModule('beta');

        $this->registry->register($a);
        $this->registry->register($b);

        $all = $this->registry->all();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('alpha', $all);
        $this->assertArrayHasKey('beta', $all);
    }

    public function test_is_registered_returns_false_for_unknown(): void
    {
        $this->assertFalse($this->registry->isRegistered('unknown'));
    }

    public function test_duplicate_slug_overwrites(): void
    {
        $first = $this->createModule('dup');
        $second = $this->createModule('dup');

        $this->registry->register($first);
        $this->registry->register($second);

        $this->assertSame($second, $this->registry->get('dup'));
        $this->assertCount(1, $this->registry->all());
    }

    public function test_get_dependencies_for_module(): void
    {
        $module = $this->createModule('child', ['parent']);

        $this->registry->register($module);

        $this->assertEquals(['parent'], $this->registry->getDependenciesFor('child'));
    }

    public function test_get_dependencies_for_unregistered_returns_empty(): void
    {
        $this->assertEquals([], $this->registry->getDependenciesFor('ghost'));
    }

    public function test_get_dependents_of(): void
    {
        $parent = $this->createModule('parent');
        $child = $this->createModule('child', ['parent']);
        $orphan = $this->createModule('orphan');

        $this->registry->register($parent);
        $this->registry->register($child);
        $this->registry->register($orphan);

        $this->assertEquals(['child'], $this->registry->getDependentsOf('parent'));
        $this->assertEquals([], $this->registry->getDependentsOf('orphan'));
    }

    /** @param array<int, string> $dependencies */
    private function createModule(string $slug, array $dependencies = []): ModuleInterface
    {
        return new class($slug, $dependencies) implements ModuleInterface
        {
            public function __construct(
                private readonly string $slug,
                private readonly array $deps,
            ) {}

            public function slug(): string
            {
                return $this->slug;
            }

            public function name(): string
            {
                return ucfirst($this->slug).' Module';
            }

            public function description(): string
            {
                return 'Test module';
            }

            public function dependencies(): array
            {
                return $this->deps;
            }

            public function registerRoutes(): void {}

            public function registerListeners(): void {}

            public function migrationPath(): string
            {
                return '';
            }

            public function mcpTools(): array
            {
                return [];
            }
        };
    }
}
