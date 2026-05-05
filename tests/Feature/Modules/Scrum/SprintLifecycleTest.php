<?php

declare(strict_types=1);

use App\Core\Models\ApiToken;
use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\Tenant;
use App\Core\Models\User;
use App\Core\Services\TenantManager;
use App\Modules\Scrum\Models\ScrumColumn;
use App\Modules\Scrum\Models\ScrumItemPlacement;
use App\Modules\Scrum\Models\Sprint;
use App\Modules\Scrum\Models\SprintItem;
use Illuminate\Testing\TestResponse;

// ============================================================
// Shared setup helpers
// ============================================================

/**
 * @return array{tenant: Tenant, user: User, token: string, project: Project}
 */
function lifecycleSetup(string $code = 'LFC'): array
{
    $tenant = createTenant();
    $user = User::factory()->manager()->create(['tenant_id' => $tenant->id]);
    $raw = ApiToken::generateRaw();
    $user->apiTokens()->create([
        'name' => 'test',
        'token' => $raw['hash'],
        'tenant_id' => $tenant->id,
    ]);
    $project = Project::factory()->create([
        'code' => $code,
        'tenant_id' => $tenant->id,
        'modules' => ['scrum'],
    ]);
    ProjectMember::create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'position' => 'owner',
    ]);
    app(TenantManager::class)->setTenant($tenant);

    return ['tenant' => $tenant, 'user' => $user, 'token' => $raw['raw'], 'project' => $project];
}

/**
 * Seed a sprint with one valid plan item so commit_sprint passes
 * validate_sprint_plan (no empty_sprint, no missing_estimation).
 */
function seedReadyItem(Sprint $sprint): SprintItem
{
    $epic = Epic::factory()->create(['project_id' => $sprint->project_id]);
    $story = Story::factory()->create([
        'epic_id' => $epic->id,
        'statut' => 'open',
        'ready' => true,
        'story_points' => 3,
        'description' => 'Seeded for commit_sprint validation',
    ]);
    /** @var Artifact $artifact */
    $artifact = Artifact::where('artifactable_id', $story->id)
        ->where('artifactable_type', Story::class)
        ->firstOrFail();

    return SprintItem::create([
        'tenant_id' => $sprint->tenant_id,
        'sprint_id' => $sprint->id,
        'artifact_id' => $artifact->id,
        'position' => 0,
    ]);
}

function makeSprint(Project $project, string $status = 'planned', ?string $closedAt = null): Sprint
{
    static $counter = 0;
    $counter++;

    return Sprint::create([
        'tenant_id' => $project->tenant_id,
        'project_id' => $project->id,
        'name' => 'Sprint '.$counter,
        'goal' => 'Default sprint goal for tests',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-15',
        'status' => $status,
        'closed_at' => $closedAt,
    ]);
}

function mcpLifecycleCall(string $tool, array $args, string $token): TestResponse
{
    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $args],
    ], ['Authorization' => 'Bearer '.$token]);
}

function assertLifecycleSuccess(TestResponse $response): array
{
    $response->assertOk();
    $data = $response->json();
    expect($data)->not->toHaveKey('error');

    return json_decode($data['result']['content'][0]['text'], true);
}

function assertLifecycleError(TestResponse $response, string $contains): void
{
    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveKey('error');
    expect($data['error']['message'])->toContain($contains);
}

// ============================================================
// tools() — discovery
// ============================================================

it('exposes the 3 lifecycle tools in tools()', function () {
    $ctx = lifecycleSetup();
    $response = test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/list',
        'params' => [],
    ], ['Authorization' => 'Bearer '.$ctx['token']]);

    $response->assertOk();
    $names = array_column($response->json('result.tools'), 'name');
    expect($names)->toContain('commit_sprint');
    expect($names)->toContain('close_sprint');
    expect($names)->toContain('cancel_sprint');
});

it('rejects unknown tool dispatch', function () {
    $ctx = lifecycleSetup();
    assertLifecycleError(
        mcpLifecycleCall('reopen_sprint', ['identifier' => 'LFC-S1'], $ctx['token']),
        'reopen_sprint'
    );
});

// ============================================================
// commit_sprint — happy path
// ============================================================

it('starts a planned sprint', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'planned');
    seedReadyItem($sprint);

    $result = assertLifecycleSuccess(
        mcpLifecycleCall('commit_sprint', ['identifier' => $sprint->identifier], $ctx['token'])
    );

    expect($result['status'])->toBe('active');
    expect($result['closed_at'])->toBeNull();
    expect($sprint->fresh()->status)->toBe('active');
});

// ============================================================
// commit_sprint — invalid transitions
// ============================================================

it('refuses to start a sprint already active', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'active');

    assertLifecycleError(
        mcpLifecycleCall('commit_sprint', ['identifier' => $sprint->identifier], $ctx['token']),
        "Cannot commit a sprint in status 'active'"
    );
});

it('refuses to start a completed sprint', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'completed');

    assertLifecycleError(
        mcpLifecycleCall('commit_sprint', ['identifier' => $sprint->identifier], $ctx['token']),
        "Cannot commit a sprint in status 'completed'"
    );
});

it('refuses to start a cancelled sprint', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'cancelled');

    assertLifecycleError(
        mcpLifecycleCall('commit_sprint', ['identifier' => $sprint->identifier], $ctx['token']),
        "Cannot commit a sprint in status 'cancelled'"
    );
});

// ============================================================
// commit_sprint — uniqueness
// ============================================================

it('refuses to start a sprint when another is already active in the same project', function () {
    $ctx = lifecycleSetup();
    $s1 = makeSprint($ctx['project'], 'active');
    $s2 = makeSprint($ctx['project'], 'planned');
    seedReadyItem($s2);

    $response = mcpLifecycleCall('commit_sprint', ['identifier' => $s2->identifier], $ctx['token']);
    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveKey('error');
    expect($data['error']['message'])->toContain($s1->identifier);
    // s2 must remain planned
    expect($s2->fresh()->status)->toBe('planned');
});

it('allows starting a sprint after the previous one is closed', function () {
    $ctx = lifecycleSetup();
    $s1 = makeSprint($ctx['project'], 'active');
    $s2 = makeSprint($ctx['project'], 'planned');
    seedReadyItem($s2);

    assertLifecycleSuccess(mcpLifecycleCall('close_sprint', ['identifier' => $s1->identifier], $ctx['token']));
    $result = assertLifecycleSuccess(mcpLifecycleCall('commit_sprint', ['identifier' => $s2->identifier], $ctx['token']));

    expect($result['status'])->toBe('active');
    expect($s1->fresh()->status)->toBe('completed');
});

it('allows starting a sprint after the previous one is cancelled', function () {
    $ctx = lifecycleSetup();
    $s1 = makeSprint($ctx['project'], 'active');
    $s2 = makeSprint($ctx['project'], 'planned');
    seedReadyItem($s2);

    assertLifecycleSuccess(mcpLifecycleCall('cancel_sprint', ['identifier' => $s1->identifier], $ctx['token']));
    $result = assertLifecycleSuccess(mcpLifecycleCall('commit_sprint', ['identifier' => $s2->identifier], $ctx['token']));

    expect($result['status'])->toBe('active');
    expect($s1->fresh()->status)->toBe('cancelled');
});

it('isolates active uniqueness per project (cross-project in same tenant)', function () {
    $ctx = lifecycleSetup('PRJA');
    // Second project in same tenant — need unique code
    $projectB = Project::factory()->create([
        'code' => 'PRJB',
        'tenant_id' => $ctx['tenant']->id,
        'modules' => ['scrum'],
    ]);
    ProjectMember::create([
        'project_id' => $projectB->id,
        'user_id' => $ctx['user']->id,
        'position' => 'owner',
    ]);

    makeSprint($ctx['project'], 'active');
    $sB = makeSprint($projectB, 'planned');
    seedReadyItem($sB);

    $result = assertLifecycleSuccess(
        mcpLifecycleCall('commit_sprint', ['identifier' => $sB->identifier], $ctx['token'])
    );

    expect($result['status'])->toBe('active');
});

// ============================================================
// commit_sprint — internal validate_sprint_plan + force flag (POIESIS-55)
// ============================================================

it('refuses to commit an empty sprint (empty_sprint blocking error)', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'planned');
    // No items seeded.

    assertLifecycleError(
        mcpLifecycleCall('commit_sprint', ['identifier' => $sprint->identifier], $ctx['token']),
        '[empty_sprint]'
    );
    expect($sprint->fresh()->status)->toBe('planned');
});

it('refuses to commit when warnings are present and force is not set', function () {
    $ctx = lifecycleSetup();
    // Sprint with no goal triggers missing_goal warning.
    $sprint = Sprint::create([
        'tenant_id' => $ctx['project']->tenant_id,
        'project_id' => $ctx['project']->id,
        'name' => 'Warn Sprint',
        'goal' => null,
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-15',
        'status' => 'planned',
    ]);
    seedReadyItem($sprint);

    assertLifecycleError(
        mcpLifecycleCall('commit_sprint', ['identifier' => $sprint->identifier], $ctx['token']),
        'force=true'
    );
    expect($sprint->fresh()->status)->toBe('planned');
});

it('commits a sprint with warnings when force=true', function () {
    $ctx = lifecycleSetup();
    $sprint = Sprint::create([
        'tenant_id' => $ctx['project']->tenant_id,
        'project_id' => $ctx['project']->id,
        'name' => 'Force Sprint',
        'goal' => null,
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-15',
        'status' => 'planned',
    ]);
    seedReadyItem($sprint);

    $result = assertLifecycleSuccess(
        mcpLifecycleCall('commit_sprint', [
            'identifier' => $sprint->identifier,
            'force' => true,
        ], $ctx['token'])
    );

    expect($result['status'])->toBe('active');
    expect($sprint->fresh()->status)->toBe('active');
});

it('does not let force=true bypass blocking errors', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'planned');
    // Empty sprint -> blocking error. force must NOT be enough.

    assertLifecycleError(
        mcpLifecycleCall('commit_sprint', [
            'identifier' => $sprint->identifier,
            'force' => true,
        ], $ctx['token']),
        '[empty_sprint]'
    );
    expect($sprint->fresh()->status)->toBe('planned');
});

it('refuses lifecycle tools when scrum module is inactive for the sprint project', function () {
    $ctx = lifecycleSetup('NOCR');
    $ctx['project']->update(['modules' => []]);
    $sprint = makeSprint($ctx['project'], 'planned');

    foreach ([
        'commit_sprint' => 'planned',
        'close_sprint' => 'planned',
        'cancel_sprint' => 'planned',
    ] as $tool => $expectedStatus) {
        assertLifecycleError(
            mcpLifecycleCall($tool, ['identifier' => $sprint->identifier], $ctx['token']),
            "Module 'scrum' is not active for project 'NOCR'."
        );
        expect($sprint->fresh()->status)->toBe($expectedStatus);
    }
});

// ============================================================
// close_sprint — happy path
// ============================================================

it('closes an active sprint and stamps closed_at', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'active');

    $before = now()->subSecond();
    $result = assertLifecycleSuccess(
        mcpLifecycleCall('close_sprint', ['identifier' => $sprint->identifier], $ctx['token'])
    );

    expect($result['status'])->toBe('completed');
    expect($result['closed_at'])->not->toBeNull();

    $fresh = $sprint->fresh();
    expect($fresh->closed_at)->not->toBeNull();
    expect($fresh->closed_at->timestamp)->toBeGreaterThanOrEqual($before->timestamp);
});

it('closes sprint stories tasks and board placements when closing the sprint', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'active');
    $epic = Epic::factory()->create(['project_id' => $ctx['project']->id]);
    $story = Story::factory()->create([
        'epic_id' => $epic->id,
        'statut' => 'open',
    ]);
    $storyTask = Task::factory()->create([
        'project_id' => $ctx['project']->id,
        'story_id' => $story->id,
        'statut' => 'open',
    ]);
    $standaloneTask = Task::factory()->standalone()->create([
        'project_id' => $ctx['project']->id,
        'statut' => 'draft',
    ]);
    $inProgressColumn = ScrumColumn::create([
        'tenant_id' => $ctx['project']->tenant_id,
        'project_id' => $ctx['project']->id,
        'name' => 'In Progress',
        'position' => 0,
    ]);
    $doneColumn = ScrumColumn::create([
        'tenant_id' => $ctx['project']->tenant_id,
        'project_id' => $ctx['project']->id,
        'name' => 'Done',
        'position' => 1,
    ]);

    $storySprintItem = SprintItem::create([
        'sprint_id' => $sprint->id,
        'artifact_id' => $story->artifact->id,
        'position' => 0,
    ]);
    $taskSprintItem = SprintItem::create([
        'sprint_id' => $sprint->id,
        'artifact_id' => $standaloneTask->artifact->id,
        'position' => 1,
    ]);
    ScrumItemPlacement::create([
        'sprint_item_id' => $storySprintItem->id,
        'column_id' => $inProgressColumn->id,
        'position' => 0,
    ]);
    ScrumItemPlacement::create([
        'sprint_item_id' => $taskSprintItem->id,
        'column_id' => $inProgressColumn->id,
        'position' => 1,
    ]);

    assertLifecycleSuccess(
        mcpLifecycleCall('close_sprint', ['identifier' => $sprint->identifier], $ctx['token'])
    );

    expect($sprint->fresh()->status)->toBe('completed');
    expect($story->fresh()->statut)->toBe('closed');
    expect($storyTask->fresh()->statut)->toBe('closed');
    expect($standaloneTask->fresh()->statut)->toBe('closed');
    expect(ScrumItemPlacement::where('column_id', $doneColumn->id)->count())->toBe(2);
    expect(ScrumItemPlacement::where('column_id', $inProgressColumn->id)->count())->toBe(0);
});

// ============================================================
// close_sprint — invalid transitions
// ============================================================

it('refuses to close a planned sprint', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'planned');

    assertLifecycleError(
        mcpLifecycleCall('close_sprint', ['identifier' => $sprint->identifier], $ctx['token']),
        "Cannot close a sprint in status 'planned'"
    );
});

it('refuses to close a completed sprint (idempotence rejected)', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'completed');

    assertLifecycleError(
        mcpLifecycleCall('close_sprint', ['identifier' => $sprint->identifier], $ctx['token']),
        "Cannot close a sprint in status 'completed'"
    );
});

it('refuses to close a cancelled sprint', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'cancelled');

    assertLifecycleError(
        mcpLifecycleCall('close_sprint', ['identifier' => $sprint->identifier], $ctx['token']),
        "Cannot close a sprint in status 'cancelled'"
    );
});

// ============================================================
// cancel_sprint — happy paths
// ============================================================

it('cancels a planned sprint without setting closed_at', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'planned');

    $result = assertLifecycleSuccess(
        mcpLifecycleCall('cancel_sprint', ['identifier' => $sprint->identifier], $ctx['token'])
    );

    expect($result['status'])->toBe('cancelled');
    expect($result['closed_at'])->toBeNull();
    expect($sprint->fresh()->closed_at)->toBeNull();
});

it('cancels an active sprint and frees the active slot', function () {
    $ctx = lifecycleSetup();
    $s1 = makeSprint($ctx['project'], 'active');
    $s2 = makeSprint($ctx['project'], 'planned');
    seedReadyItem($s2);

    assertLifecycleSuccess(mcpLifecycleCall('cancel_sprint', ['identifier' => $s1->identifier], $ctx['token']));

    expect($s1->fresh()->status)->toBe('cancelled');
    expect($s1->fresh()->closed_at)->toBeNull();

    $result = assertLifecycleSuccess(
        mcpLifecycleCall('commit_sprint', ['identifier' => $s2->identifier], $ctx['token'])
    );
    expect($result['status'])->toBe('active');
});

// ============================================================
// cancel_sprint — invalid transitions
// ============================================================

it('refuses to cancel a completed sprint', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'completed');

    assertLifecycleError(
        mcpLifecycleCall('cancel_sprint', ['identifier' => $sprint->identifier], $ctx['token']),
        "Cannot cancel a sprint in status 'completed'"
    );
});

it('refuses to cancel an already cancelled sprint (idempotence rejected)', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'cancelled');

    assertLifecycleError(
        mcpLifecycleCall('cancel_sprint', ['identifier' => $sprint->identifier], $ctx['token']),
        "Cannot cancel a sprint in status 'cancelled'"
    );
});

// ============================================================
// Permissions
// ============================================================

it('rejects lifecycle tools when user lacks CRUD permission', function () {
    $ctx = lifecycleSetup();
    $sprint = makeSprint($ctx['project'], 'planned');

    // Create a viewer user
    $viewer = User::factory()->viewer()->create(['tenant_id' => $ctx['tenant']->id]);
    $raw = ApiToken::generateRaw();
    $viewer->apiTokens()->create([
        'name' => 'viewer',
        'token' => $raw['hash'],
        'tenant_id' => $ctx['tenant']->id,
    ]);
    ProjectMember::create([
        'project_id' => $ctx['project']->id,
        'user_id' => $viewer->id,
        'position' => 'viewer',
    ]);

    foreach (['commit_sprint', 'close_sprint', 'cancel_sprint'] as $tool) {
        assertLifecycleError(
            mcpLifecycleCall($tool, ['identifier' => $sprint->identifier], $raw['raw']),
            'You do not have permission to manage sprints.'
        );
    }
});

// ============================================================
// Cross-tenant / non-member access
// ============================================================

it('hides sprints of other projects behind Sprint not found', function () {
    $ctxA = lifecycleSetup('TENA');
    // Reset tenant context for second tenant
    $ctxB = lifecycleSetup('TENB');

    $sprint = makeSprint($ctxB['project'], 'planned');

    foreach (['commit_sprint', 'close_sprint', 'cancel_sprint'] as $tool) {
        assertLifecycleError(
            mcpLifecycleCall($tool, ['identifier' => $sprint->identifier], $ctxA['token']),
            'Sprint not found.'
        );
    }
});

// ============================================================
// Identifier validation
// ============================================================

it('rejects malformed identifier', function () {
    $ctx = lifecycleSetup();

    assertLifecycleError(
        mcpLifecycleCall('commit_sprint', ['identifier' => 'not-an-id'], $ctx['token']),
        'Invalid sprint identifier format.'
    );
});

it('rejects non-existent sprint number with Sprint not found', function () {
    $ctx = lifecycleSetup();

    assertLifecycleError(
        mcpLifecycleCall('commit_sprint', ['identifier' => 'LFC-S99'], $ctx['token']),
        'Sprint not found.'
    );
});
