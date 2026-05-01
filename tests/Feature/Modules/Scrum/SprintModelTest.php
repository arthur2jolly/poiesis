<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Scrum;

use App\Core\Models\Project;
use App\Core\Models\Tenant;
use App\Core\Services\TenantManager;
use App\Modules\Scrum\Models\Sprint;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SprintModelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = createTenant();

        app(TenantManager::class)->setTenant($this->tenant);

        $this->project = Project::factory()->create([
            'code' => 'POI',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_sprint_create_assigns_sprint_number_1(): void
    {
        $sprint = Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint Alpha',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
        ]);

        $this->assertEquals(1, $sprint->sprint_number);
        $this->assertEquals('POI-S1', $sprint->identifier);
        $this->assertEquals('planned', $sprint->status);
    }

    public function test_sprint_number_increments_per_project(): void
    {
        $s1 = Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint 1',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-14',
        ]);

        $s2 = Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint 2',
            'start_date' => '2026-05-15',
            'end_date' => '2026-05-28',
        ]);

        $s3 = Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint 3',
            'start_date' => '2026-05-29',
            'end_date' => '2026-06-11',
        ]);

        $this->assertEquals(1, $s1->sprint_number);
        $this->assertEquals(2, $s2->sprint_number);
        $this->assertEquals(3, $s3->sprint_number);
        $this->assertEquals('POI-S1', $s1->identifier);
        $this->assertEquals('POI-S3', $s3->identifier);
    }

    public function test_sprint_numbers_are_independent_per_project(): void
    {
        $projectB = Project::factory()->create([
            'code' => 'BRD',
            'tenant_id' => $this->tenant->id,
        ]);

        $sA = Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint A',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-14',
        ]);

        $sB = Sprint::create([
            'project_id' => $projectB->id,
            'name' => 'Sprint B',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-14',
        ]);

        $this->assertEquals(1, $sA->sprint_number);
        $this->assertEquals(1, $sB->sprint_number);
    }

    public function test_sprint_unique_constraint_on_project_id_sprint_number(): void
    {
        Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint 1',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-14',
        ]);

        $this->expectException(QueryException::class);

        // Force duplicate sprint_number via raw insert (bypasses boot hook)
        DB::table('scrum_sprints')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'sprint_number' => 1,
            'name' => 'Duplicate Sprint',
            'start_date' => '2026-05-15',
            'end_date' => '2026-05-28',
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sprint_belongs_to_tenant_scope(): void
    {
        $tenantA = $this->tenant;
        $tenantB = createTenant();

        $projectB = Project::factory()->create([
            'code' => 'TNB',
            'tenant_id' => $tenantB->id,
        ]);

        app(TenantManager::class)->setTenant($tenantA);

        Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint A',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-14',
        ]);

        app(TenantManager::class)->setTenant($tenantB);

        $this->assertCount(0, Sprint::all());
    }

    public function test_sprint_tenant_id_auto_injected(): void
    {
        $sprint = Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint Auto',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-14',
        ]);

        $this->assertEquals($this->tenant->id, $sprint->tenant_id);
    }

    public function test_sprint_default_status_is_planned(): void
    {
        $sprint = Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint Status',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-14',
        ]);

        $this->assertEquals('planned', $sprint->status);
    }

    public function test_sprint_casts_dates(): void
    {
        $sprint = Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint Dates',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
        ]);

        $this->assertInstanceOf(Carbon::class, $sprint->start_date);
        $this->assertInstanceOf(Carbon::class, $sprint->end_date);
    }

    public function test_project_cascade_delete_removes_sprints(): void
    {
        $sprint = Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Sprint Cascade',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-14',
        ]);

        $sprintId = $sprint->id;

        $this->project->delete();

        $this->assertNull(Sprint::withoutGlobalScope('tenant')->find($sprintId));
    }

    public function test_sprint_can_be_created_with_all_fillable_fields(): void
    {
        $sprint = Sprint::create([
            'project_id' => $this->project->id,
            'name' => 'Full Sprint',
            'goal' => 'Deliver feature X',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-14',
            'capacity' => 40,
            'status' => 'active',
        ]);

        $this->assertEquals('Full Sprint', $sprint->name);
        $this->assertEquals('Deliver feature X', $sprint->goal);
        $this->assertEquals(40, $sprint->capacity);
        $this->assertEquals('active', $sprint->status);
    }
}
