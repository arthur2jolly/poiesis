<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Scrum;

use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\Story;
use App\Core\Models\Tenant;
use App\Core\Services\TenantManager;
use App\Modules\Scrum\Models\Sprint;
use App\Modules\Scrum\Models\SprintItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SprintFormatTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Sprint $sprint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = createTenant();

        app(TenantManager::class)->setTenant($this->tenant);

        $this->project = Project::factory()->create([
            'code' => 'POI',
            'tenant_id' => $this->tenant->id,
        ]);

        $this->sprint = Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint Alpha',
            'goal' => 'Deliver feature X',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-14',
            'capacity' => 40,
            'status' => 'active',
        ]);
    }

    public function test_format_returns_all_13_keys(): void
    {
        $result = $this->sprint->format();

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('identifier', $result);
        $this->assertArrayHasKey('project_code', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('goal', $result);
        $this->assertArrayHasKey('start_date', $result);
        $this->assertArrayHasKey('end_date', $result);
        $this->assertArrayHasKey('capacity', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('items_count', $result);
        $this->assertArrayHasKey('closed_at', $result);
        $this->assertArrayHasKey('created_at', $result);
        $this->assertArrayHasKey('updated_at', $result);

        $this->assertCount(13, $result);
    }

    public function test_format_happy_path_values(): void
    {
        $result = $this->sprint->format();

        $this->assertEquals($this->sprint->id, $result['id']);
        $this->assertEquals('POI-S1', $result['identifier']);
        $this->assertEquals('POI', $result['project_code']);
        $this->assertEquals('Sprint Alpha', $result['name']);
        $this->assertEquals('Deliver feature X', $result['goal']);
        $this->assertEquals('2026-05-01', $result['start_date']);
        $this->assertEquals('2026-05-14', $result['end_date']);
        $this->assertEquals(40, $result['capacity']);
        $this->assertEquals('active', $result['status']);
    }

    public function test_format_date_serialization(): void
    {
        $result = $this->sprint->format();

        // start_date and end_date: YYYY-MM-DD format
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result['start_date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result['end_date']);

        // created_at and updated_at: ISO-8601
        $this->assertNotNull($result['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $result['created_at']);
        $this->assertNotNull($result['updated_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $result['updated_at']);
    }

    public function test_format_items_count_is_null_without_loadcount(): void
    {
        $result = $this->sprint->format();

        $this->assertNull($result['items_count']);
    }

    public function test_format_items_count_is_integer_after_loadcount(): void
    {
        $epic = Epic::factory()->create(['project_id' => $this->project->id]);
        $s1 = Story::factory()->create(['epic_id' => $epic->id]);
        $s2 = Story::factory()->create(['epic_id' => $epic->id]);

        SprintItem::create([
            'sprint_id' => $this->sprint->id,
            'artifact_id' => $s1->artifact->id,
            'position' => 0,
        ]);

        SprintItem::create([
            'sprint_id' => $this->sprint->id,
            'artifact_id' => $s2->artifact->id,
            'position' => 1,
        ]);

        $this->sprint->loadCount('items');

        $result = $this->sprint->format();

        $this->assertEquals(2, $result['items_count']);
    }

    public function test_format_goal_null_is_exposed_as_null(): void
    {
        $sprint = Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint No Goal',
            'start_date' => '2026-05-15',
            'end_date' => '2026-05-28',
        ]);

        $result = $sprint->format();

        $this->assertNull($result['goal']);
    }

    public function test_format_closed_at_null_is_exposed_as_null(): void
    {
        $result = $this->sprint->format();

        $this->assertNull($result['closed_at']);
    }

    public function test_format_keys_are_in_correct_order(): void
    {
        $result = $this->sprint->format();

        $expectedKeys = [
            'id', 'identifier', 'project_code', 'name', 'goal',
            'start_date', 'end_date', 'capacity', 'status',
            'items_count', 'closed_at', 'created_at', 'updated_at',
        ];

        $this->assertEquals($expectedKeys, array_keys($result));
    }
}
