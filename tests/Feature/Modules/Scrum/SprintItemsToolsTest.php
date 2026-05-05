<?php

declare(strict_types=1);

use App\Core\Models\ApiToken;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\Tenant;
use App\Core\Models\User;
use App\Core\Services\TenantManager;
use App\Modules\Scrum\Models\Sprint;
use App\Modules\Scrum\Models\SprintItem;
use Illuminate\Testing\TestResponse;

// ============================================================
// Setup helpers
// ============================================================

/**
 * @return array{tenant: Tenant, project: Project, manager_token: string, viewer_token: string}
 */
function itemsSetup(string $code = 'ITM'): array
{
    $tenant = createTenant();
    app(TenantManager::class)->setTenant($tenant);

    $manager = User::factory()->manager()->create(['tenant_id' => $tenant->id]);
    $managerRaw = ApiToken::generateRaw();
    $manager->apiTokens()->create([
        'name' => 'manager',
        'token' => $managerRaw['hash'],
        'tenant_id' => $tenant->id,
    ]);

    $viewer = User::factory()->viewer()->create(['tenant_id' => $tenant->id]);
    $viewerRaw = ApiToken::generateRaw();
    $viewer->apiTokens()->create([
        'name' => 'viewer',
        'token' => $viewerRaw['hash'],
        'tenant_id' => $tenant->id,
    ]);

    $project = Project::factory()->create([
        'code' => $code,
        'tenant_id' => $tenant->id,
        'modules' => ['scrum'],
    ]);

    ProjectMember::create(['project_id' => $project->id, 'user_id' => $manager->id, 'position' => 'owner']);
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $viewer->id, 'position' => 'viewer']);

    return [
        'tenant' => $tenant,
        'project' => $project,
        'manager_token' => $managerRaw['raw'],
        'viewer_token' => $viewerRaw['raw'],
    ];
}

function makeSprintWithStatus(Project $project, string $status = 'planned'): Sprint
{
    return Sprint::create([
        'tenant_id' => $project->tenant_id,
        'project_id' => $project->id,
        'name' => 'Sprint Test',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-15',
        'status' => $status,
    ]);
}

function makeStory(Project $project, array $overrides = []): Story
{
    $epic = Epic::factory()->create(['project_id' => $project->id]);

    // Default to ready=true: realistic case for tests that add stories to a
    // sprint. add_to_sprint enforces DoR — pass ['ready' => false] to opt out.
    return Story::factory()->create(array_merge(
        ['epic_id' => $epic->id, 'ready' => true],
        $overrides
    ));
}

function makeStandaloneTask(Project $project, array $overrides = []): Task
{
    return Task::factory()->standalone()->create(array_merge(['project_id' => $project->id], $overrides));
}

function makeLinkedTask(Project $project, Story $story): Task
{
    return Task::factory()->create([
        'project_id' => $project->id,
        'story_id' => $story->id,
    ]);
}

function mcpItemsCall(string $tool, array $args, string $token): TestResponse
{
    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $args],
    ], ['Authorization' => 'Bearer '.$token]);
}

function assertItemsSuccess(TestResponse $response): mixed
{
    $response->assertOk();
    $data = $response->json();
    expect($data)->not->toHaveKey('error');

    return json_decode($data['result']['content'][0]['text'], true);
}

function assertItemsError(TestResponse $response, string $contains): void
{
    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveKey('error');
    expect($data['error']['message'])->toContain($contains);
}

// ============================================================
// T-01 — add_to_sprint happy path Story
// ============================================================

it('T-01: adds a story to a planned sprint', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);

    $result = assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    expect($result['position'])->toBe(0);
    expect($result['artifact']['type'])->toBe('story');
    expect($result['sprint_identifier'])->toBe($sprint->identifier);
    expect($result['artifact']['identifier'])->toBe($story->identifier);

    expect(SprintItem::count())->toBe(1);
});

// ============================================================
// T-02 — add_to_sprint happy path Task standalone
// ============================================================

it('T-02: adds a standalone task to a planned sprint', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $task = makeStandaloneTask($ctx['project']);

    $result = assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $task->identifier,
    ], $ctx['manager_token']));

    expect($result['artifact']['type'])->toBe('task');
    expect($result['artifact']['story_points'])->toBeNull();
    expect($result['artifact']['identifier'])->toBe($task->identifier);
});

// ============================================================
// T-03 — second add increments position
// ============================================================

it('T-03: second item gets position 1 when first is at 0', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story1 = makeStory($ctx['project']);
    $story2 = makeStory($ctx['project']);

    assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story1->identifier,
    ], $ctx['manager_token']));

    $result = assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story2->identifier,
    ], $ctx['manager_token']));

    expect($result['position'])->toBe(1);
});

// ============================================================
// T-04 — explicit position stored as-is
// ============================================================

it('T-04: explicit position is stored without compacting', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);

    $result = assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
        'position' => 5,
    ], $ctx['manager_token']));

    expect($result['position'])->toBe(5);
    expect(SprintItem::first()->position)->toBe(5);
});

// ============================================================
// T-05 — position négative refusée
// ============================================================

it('T-05: negative position is rejected', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
        'position' => -1,
    ], $ctx['manager_token']), 'Position must be a non-negative integer.');
});

// ============================================================
// T-06 — sprint completed refusé
// ============================================================

it('T-06: cannot add to a completed sprint', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project'], 'completed');
    $story = makeStory($ctx['project']);

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']), "Cannot add items to a sprint in status 'completed'");
});

// ============================================================
// T-07 — sprint cancelled refusé
// ============================================================

it('T-07: cannot add to a cancelled sprint', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project'], 'cancelled');
    $story = makeStory($ctx['project']);

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']), "Cannot add items to a sprint in status 'cancelled'");
});

// ============================================================
// T-08 — Epic refusé
// ============================================================

it('T-08: epics cannot be added to a sprint', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $epic = Epic::factory()->create(['project_id' => $ctx['project']->id]);

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $epic->identifier,
    ], $ctx['manager_token']), 'Epics are not sprintable');
});

// ============================================================
// T-09 — Task non-standalone refusée
// ============================================================

it('T-09: non-standalone task (linked to story) is rejected', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);
    $task = makeLinkedTask($ctx['project'], $story);

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $task->identifier,
    ], $ctx['manager_token']), 'Add the parent story instead.');
});

// ============================================================
// T-10 — cross-projet refusé
// ============================================================

it('T-10: item from another project is rejected', function () {
    $ctx = itemsSetup('ITMA');
    $sprint = makeSprintWithStatus($ctx['project']);

    $projectB = Project::factory()->create([
        'code' => 'ITMB',
        'tenant_id' => $ctx['tenant']->id,
        'modules' => ['scrum'],
    ]);
    ProjectMember::create([
        'project_id' => $projectB->id,
        'user_id' => User::where('tenant_id', $ctx['tenant']->id)->first()->id,
        'position' => 'owner',
    ]);
    $storyB = makeStory($projectB);

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $storyB->identifier,
    ], $ctx['manager_token']), 'Item not found.');
});

// ============================================================
// T-11 — cross-tenant refusé
// ============================================================

it('T-11: item from another tenant is not found', function () {
    $ctxA = itemsSetup('TENA');
    $sprint = makeSprintWithStatus($ctxA['project']);

    // Create a second tenant with its own story
    $tenantB = createTenant();
    app(TenantManager::class)->setTenant($tenantB);
    $projectB = Project::factory()->create([
        'code' => 'TENB',
        'tenant_id' => $tenantB->id,
    ]);
    $storyB = makeStory($projectB);
    $storyBIdentifier = $storyB->identifier;

    // Restore tenant A context
    app(TenantManager::class)->setTenant($ctxA['tenant']);

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $storyBIdentifier,
    ], $ctxA['manager_token']), 'Item not found.');
});

// ============================================================
// T-12 — double-affectation même sprint
// ============================================================

it('T-12: adding the same item twice to the same sprint is rejected', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);

    assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']), 'Item is already in this sprint.');
});

// ============================================================
// T-13 — double-affectation autre sprint
// ============================================================

it('T-13: adding an item already in another sprint mentions that sprint', function () {
    $ctx = itemsSetup();
    $s1 = makeSprintWithStatus($ctx['project']);
    $s2 = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);

    assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $s1->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    $response = mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $s2->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']);

    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveKey('error');
    expect($data['error']['message'])->toContain($s1->identifier);
    expect($data['error']['message'])->toContain('Remove it from there first.');
});

// ============================================================
// T-14 — item identifier malformé / inconnu
// ============================================================

it('T-14: unknown item identifier returns Item not found', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => 'ITM-99999',
    ], $ctx['manager_token']), 'Item not found.');
});

// ============================================================
// T-15 — item identifier = sprint identifier (CL-26)
// ============================================================

it('T-15: passing a sprint identifier as item_identifier returns Item not found', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $sprint->identifier,
    ], $ctx['manager_token']), 'Item not found.');
});

// ============================================================
// T-16 — viewer denied (write)
// ============================================================

it('T-16: viewer cannot add items to sprint', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['viewer_token']), 'You do not have permission to manage sprints.');
});

// ============================================================
// T-17 — sprint identifier malformé
// ============================================================

it('T-17: malformed sprint identifier is rejected', function () {
    $ctx = itemsSetup();
    $story = makeStory($ctx['project']);

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => 'notvalid',
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']), 'Invalid sprint identifier format.');
});

// ============================================================
// T-18 — sprint cross-projet (non-member)
// ============================================================

it('T-18: sprint of a non-member project returns Sprint not found', function () {
    $ctxA = itemsSetup('SPRA');
    // Create a story for project A while still in tenant A context
    $story = makeStory($ctxA['project']);
    $storyIdentifier = $story->identifier;

    // Now setup a second context (switches tenant)
    $ctxB = itemsSetup('SPRB');
    $sprintB = makeSprintWithStatus($ctxB['project']);
    $sprintBIdentifier = $sprintB->identifier;

    // Switch back to tenant A context for the API call
    app(TenantManager::class)->setTenant($ctxA['tenant']);

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprintBIdentifier,
        'item_identifier' => $storyIdentifier,
    ], $ctxA['manager_token']), 'Sprint not found.');
});

// ============================================================
// T-19 — story closed ajoutée (autorisé)
// ============================================================

it('T-19: closed story can be added to sprint (no status guard)', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project'], ['statut' => 'closed']);

    $result = assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    expect($result['artifact']['status'])->toBe('closed');
});

// ============================================================
// T-20 — story draft ajoutée (autorisé, pas de transition)
// ============================================================

it('T-20: draft story can be added to sprint without auto-transition', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project'], ['statut' => 'draft']);

    $result = assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    expect($result['artifact']['status'])->toBe('draft');
    expect($story->fresh()->statut)->toBe('draft');
});

it('T-20b: add list and remove do not transition story or task statuses', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project'], ['statut' => 'draft']);
    $task = makeStandaloneTask($ctx['project'], ['statut' => 'open']);

    assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));
    assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $task->identifier,
    ], $ctx['manager_token']));
    assertItemsSuccess(mcpItemsCall('list_sprint_items', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));
    assertItemsSuccess(mcpItemsCall('remove_from_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));
    assertItemsSuccess(mcpItemsCall('remove_from_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $task->identifier,
    ], $ctx['manager_token']));

    expect($story->fresh()->statut)->toBe('draft');
    expect($task->fresh()->statut)->toBe('open');
});

// ============================================================
// T-21 — remove_from_sprint happy path
// ============================================================

it('T-21: removes an item from a sprint', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);

    assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    $result = assertItemsSuccess(mcpItemsCall('remove_from_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    expect($result['message'])->toBe('Item removed from sprint.');
    expect(SprintItem::count())->toBe(0);
});

// ============================================================
// T-22 — remove item absent du sprint
// ============================================================

it('T-22: removing an item not in the sprint returns error', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);

    assertItemsError(mcpItemsCall('remove_from_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']), 'Item is not in this sprint.');
});

// ============================================================
// T-23 — remove sprint completed refusé
// ============================================================

it('T-23: cannot remove from a completed sprint', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project'], 'completed');
    $story = makeStory($ctx['project']);

    assertItemsError(mcpItemsCall('remove_from_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']), "Cannot remove items from a sprint in status 'completed'");
});

// ============================================================
// T-24 — remove sprint cancelled refusé
// ============================================================

it('T-24: cannot remove from a cancelled sprint', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project'], 'cancelled');
    $story = makeStory($ctx['project']);

    assertItemsError(mcpItemsCall('remove_from_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']), "Cannot remove items from a sprint in status 'cancelled'");
});

// ============================================================
// T-25 — remove viewer denied
// ============================================================

it('T-25: viewer cannot remove items from sprint', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);

    assertItemsError(mcpItemsCall('remove_from_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['viewer_token']), 'You do not have permission to manage sprints.');
});

// ============================================================
// T-26 — remove + re-add cycle
// ============================================================

it('T-26: remove then re-add same item succeeds with recalculated position', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);

    assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    assertItemsSuccess(mcpItemsCall('remove_from_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    $result = assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    expect($result['position'])->toBe(0);
    expect(SprintItem::count())->toBe(1);
});

// ============================================================
// T-27 — list sprint vide
// ============================================================

it('T-27: listing items of an empty sprint returns empty data', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);

    $result = assertItemsSuccess(mcpItemsCall('list_sprint_items', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['data'])->toBe([]);
    expect($result['meta']['total'])->toBe(0);
});

// ============================================================
// T-28 — list sprint mixte (Story + Task standalone)
// ============================================================

it('T-28: listing a sprint with 1 story and 1 task returns both sorted by position', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);
    $task = makeStandaloneTask($ctx['project']);

    assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $task->identifier,
    ], $ctx['manager_token']));

    $result = assertItemsSuccess(mcpItemsCall('list_sprint_items', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['meta']['total'])->toBe(2);
    expect($result['data'][0]['position'])->toBeLessThan($result['data'][1]['position']);

    $types = array_column(array_column($result['data'], 'artifact'), 'type');
    expect($types)->toContain('story');
    expect($types)->toContain('task');
});

// ============================================================
// T-29 — list sprint completed (autorisé)
// ============================================================

it('T-29: listing a completed sprint is allowed', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);

    // Add item while planned
    assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    // Mark sprint as completed directly
    $sprint->update(['status' => 'completed']);

    $result = assertItemsSuccess(mcpItemsCall('list_sprint_items', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['meta']['total'])->toBe(1);
});

it('T-29b: listing a cancelled sprint is allowed', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);

    assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    $sprint->update(['status' => 'cancelled']);

    $result = assertItemsSuccess(mcpItemsCall('list_sprint_items', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['meta']['total'])->toBe(1);
    expect($result['data'][0]['artifact']['identifier'])->toBe($story->identifier);
});

// ============================================================
// T-30 — list cascade après delete
// ============================================================

it('T-30: deleted story is removed from sprint items via cascade', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);

    assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    $story->delete();

    $result = assertItemsSuccess(mcpItemsCall('list_sprint_items', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['data'])->toBe([]);
    expect($result['meta']['total'])->toBe(0);
});

// ============================================================
// T-31 — list viewer autorisé
// ============================================================

it('T-31: viewer can list sprint items (read-only)', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);

    $result = assertItemsSuccess(mcpItemsCall('list_sprint_items', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['viewer_token']));

    expect($result['data'])->toBe([]);
});

// ============================================================
// T-32 — list cross-projet
// ============================================================

it('T-32: listing sprint of non-member project returns Sprint not found', function () {
    $ctxA = itemsSetup('LSTA');
    $ctxB = itemsSetup('LSTB');
    $sprintB = makeSprintWithStatus($ctxB['project']);

    app(TenantManager::class)->setTenant($ctxA['tenant']);

    assertItemsError(mcpItemsCall('list_sprint_items', [
        'sprint_identifier' => $sprintB->identifier,
    ], $ctxA['manager_token']), 'Sprint not found.');
});

// ============================================================
// T-33 — module scrum désactivé
// ============================================================

it('T-33: sprint item tools are absent when scrum module is not active for the scoped project', function () {
    $tenant = createTenant();
    app(TenantManager::class)->setTenant($tenant);

    $user = User::factory()->manager()->create(['tenant_id' => $tenant->id]);
    $raw = ApiToken::generateRaw();
    $user->apiTokens()->create([
        'name' => 'test',
        'token' => $raw['hash'],
        'tenant_id' => $tenant->id,
    ]);
    $project = Project::factory()->create([
        'code' => 'NOSCRM',
        'tenant_id' => $tenant->id,
        'modules' => [],  // scrum NOT active
    ]);
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'position' => 'owner']);

    // Pass project_code so McpServer scopes tools to this project's active modules
    $response = test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/list',
        'params' => ['project_code' => 'NOSCRM'],
    ], ['Authorization' => 'Bearer '.$raw['raw']]);

    $response->assertOk();
    $names = array_column($response->json('result.tools'), 'name');
    expect($names)->not->toContain('add_to_sprint');
    expect($names)->not->toContain('remove_from_sprint');
    expect($names)->not->toContain('list_sprint_items');
});

it('T-33b: sprint item tools fail when scrum module is inactive for the sprint identifier project', function () {
    $ctx = itemsSetup('NOITM');
    $ctx['project']->update(['modules' => []]);
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project']);

    foreach ([
        ['add_to_sprint', ['sprint_identifier' => $sprint->identifier, 'item_identifier' => $story->identifier]],
        ['remove_from_sprint', ['sprint_identifier' => $sprint->identifier, 'item_identifier' => $story->identifier]],
        ['list_sprint_items', ['sprint_identifier' => $sprint->identifier]],
    ] as [$tool, $arguments]) {
        assertItemsError(
            mcpItemsCall($tool, $arguments, $ctx['manager_token']),
            "Module 'scrum' is not active for project 'NOITM'."
        );
    }
});

// ============================================================
// T-34 — format polymorphe Story
// ============================================================

it('T-34: story format exposes story_points and type=story', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project'], ['story_points' => 8]);

    assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    $result = assertItemsSuccess(mcpItemsCall('list_sprint_items', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $artifact = $result['data'][0]['artifact'];
    expect($artifact['type'])->toBe('story');
    expect($artifact['story_points'])->toBe(8);
    expect($artifact['ready'])->toBeFalse();
    expect($artifact['title'])->not->toBeNull();
});

// ============================================================
// T-35 — format polymorphe Task
// ============================================================

it('T-35: task format exposes type=task and story_points=null', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $task = makeStandaloneTask($ctx['project']);

    assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $task->identifier,
    ], $ctx['manager_token']));

    $result = assertItemsSuccess(mcpItemsCall('list_sprint_items', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $artifact = $result['data'][0]['artifact'];
    expect($artifact['type'])->toBe('task');
    expect($artifact['story_points'])->toBeNull();
    expect($artifact['ready'])->toBeNull();
    expect($artifact['title'])->not->toBeNull();
});

// ============================================================
// T-50 — add_to_sprint rejects a non-ready story (DoR enforced)
// ============================================================

it('T-50: rejects a non-ready story when adding to sprint', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project'], ['ready' => false]);

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']), 'Story is not ready');

    expect(SprintItem::count())->toBe(0);
});

// ============================================================
// T-51 — add_to_sprint reports missing DoR fields
// ============================================================

it('T-51: lists missing DoR fields when story not ready', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $story = makeStory($ctx['project'], [
        'ready' => false,
        'story_points' => null,
        'description' => '',
    ]);

    assertItemsError(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']), 'story_points');

    expect(SprintItem::count())->toBe(0);
});

// ============================================================
// T-52 — add_to_sprint still accepts standalone tasks (no DoR)
// ============================================================

it('T-52: standalone tasks bypass the DoR check', function () {
    $ctx = itemsSetup();
    $sprint = makeSprintWithStatus($ctx['project']);
    $task = makeStandaloneTask($ctx['project']);

    $result = assertItemsSuccess(mcpItemsCall('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $task->identifier,
    ], $ctx['manager_token']));

    expect($result['artifact']['type'])->toBe('task');
    expect(SprintItem::count())->toBe(1);
});
