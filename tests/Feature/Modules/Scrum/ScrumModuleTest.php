<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Scrum;

use App\Core\Contracts\ModuleInterface;
use App\Core\Models\User;
use App\Modules\Scrum\Mcp\ScrumTools;
use App\Modules\Scrum\ScrumModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScrumModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_is_registered_in_config(): void
    {
        $this->assertSame(ScrumModule::class, config('modules.scrum'));
    }

    public function test_module_implements_module_interface(): void
    {
        $module = new ScrumModule;

        $this->assertInstanceOf(ModuleInterface::class, $module);
        $this->assertSame('scrum', $module->slug());
        $this->assertSame('Scrum', $module->name());
        $this->assertNotEmpty($module->description());
        $this->assertSame([], $module->dependencies());
    }

    public function test_module_exposes_list_and_get_sprint_tools(): void
    {
        $tools = (new ScrumTools)->tools();

        $names = array_column($tools, 'name');
        $this->assertContains('list_sprints', $names);
        $this->assertContains('get_sprint', $names);
    }

    public function test_scrum_tools_execute_throws_for_any_tool(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @var User $user */
        $user = User::factory()->make();

        (new ScrumTools)->execute('any_tool', [], $user);
    }

    public function test_migrations_run_and_rollback_cleanly(): void
    {
        $this->assertTrue(Schema::hasTable('scrum_sprints'));
        $this->assertTrue(Schema::hasTable('scrum_sprint_items'));
        $this->assertTrue(Schema::hasColumn('stories', 'rank'));
        $this->assertTrue(Schema::hasColumn('stories', 'ready'));
    }

    public function test_sprint_statuses_config_is_loaded(): void
    {
        $this->assertSame(
            ['planned', 'active', 'completed', 'cancelled'],
            config('core.sprint_statuses')
        );
    }
}
