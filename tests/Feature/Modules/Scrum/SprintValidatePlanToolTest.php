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
use App\Core\Services\DependencyService;
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
function vspSetup(string $code = 'VSP'): array
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

function vspSprint(Project $project, string $status = 'planned', ?int $capacity = null, ?string $goal = null): Sprint
{
    return Sprint::create([
        'tenant_id' => $project->tenant_id,
        'project_id' => $project->id,
        'name' => 'Validate Sprint',
        'goal' => $goal,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-15',
        'status' => $status,
        'capacity' => $capacity,
    ]);
}

function vspStory(Project $project, array $overrides = []): Story
{
    $epic = Epic::factory()->create(['project_id' => $project->id]);

    return Story::factory()->create(array_merge(['epic_id' => $epic->id], $overrides));
}

function vspAttach(Sprint $sprint, Story|Task $model): SprintItem
{
    /** @var Artifact $artifactRow */
    $artifactRow = Artifact::where('artifactable_id', $model->getKey())
        ->where('artifactable_type', $model->getMorphClass())
        ->firstOrFail();

    return SprintItem::create([
        'sprint_id' => $sprint->id,
        'artifact_id' => $artifactRow->id,
        'position' => (int) ($sprint->items()->max('position') ?? -1) + 1,
    ]);
}

function mcpVsp(string $tool, array $args, string $token): TestResponse
{
    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $args],
    ], ['Authorization' => 'Bearer '.$token]);
}

function vspOk(TestResponse $response): mixed
{
    $response->assertOk();
    $data = $response->json();
    expect($data)->not->toHaveKey('error');

    return json_decode($data['result']['content'][0]['text'], true);
}

function vspErr(TestResponse $response, string $contains): void
{
    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveKey('error');
    expect($data['error']['message'])->toContain($contains);
}

// ============================================================
// General tests
// ============================================================

it('V-01: happy path — 1 estimated story, no dependency, goal defined, capacity OK → ok=true, no errors, no warnings', function () {
    $ctx = vspSetup('VP1');
    $sprint = vspSprint($ctx['project'], 'planned', 20, 'Deliver feature X');
    $story = vspStory($ctx['project'], ['story_points' => 5, 'statut' => 'open']);
    vspAttach($sprint, $story);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['ok'])->toBeTrue();
    expect($result['errors'])->toBe([]);
    expect($result['warnings'])->toBe([]);
    expect($result['sprint_identifier'])->toBe($sprint->identifier);
    expect($result['summary']['items_count'])->toBe(1);
    expect($result['summary']['engaged_points'])->toBe(5);
    expect($result['summary']['capacity'])->toBe(20);
});

it('V-02: non-member user → Sprint not found.', function () {
    $ctx = vspSetup('VP2');
    $sprint = vspSprint($ctx['project'], 'planned', null, 'Goal');

    $outsider = User::factory()->manager()->create(['tenant_id' => $ctx['tenant']->id]);
    $outsiderRaw = ApiToken::generateRaw();
    $outsider->apiTokens()->create([
        'name' => 'outsider',
        'token' => $outsiderRaw['hash'],
        'tenant_id' => $ctx['tenant']->id,
    ]);
    app(TenantManager::class)->setTenant($ctx['tenant']);

    vspErr(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $outsiderRaw['raw']), 'Sprint not found.');
});

it('V-03: module not activated — validate_sprint_plan absent from tools/list when project_code scoped', function () {
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
        'code' => 'NOMOD',
        'tenant_id' => $tenant->id,
        'modules' => [],
    ]);
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'position' => 'owner']);

    // tools/list scoped to project without scrum module must not contain validate_sprint_plan
    $listResponse = test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/list',
        'params' => ['project_code' => 'NOMOD'],
    ], ['Authorization' => 'Bearer '.$raw['raw']]);
    $listResponse->assertOk();
    $names = array_column($listResponse->json('result.tools'), 'name');
    expect($names)->not->toContain('validate_sprint_plan');
});

it('V-04: malformed sprint_identifier → Invalid sprint identifier format.', function () {
    $ctx = vspSetup('VP4');

    vspErr(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => 'FOO',
    ], $ctx['manager_token']), 'Invalid sprint identifier format.');
});

it('V-05: viewer (non-CRUD) can call validate_sprint_plan', function () {
    $ctx = vspSetup('VP5');
    $sprint = vspSprint($ctx['project'], 'planned', 10, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => 3, 'statut' => 'open']);
    vspAttach($sprint, $story);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['viewer_token']));

    expect($result['ok'])->toBeTrue();
});

// ============================================================
// RM-04 — empty_sprint
// ============================================================

it('V-06: empty sprint with capacity and goal → 1 error empty_sprint, 0 warnings', function () {
    $ctx = vspSetup('VP6');
    $sprint = vspSprint($ctx['project'], 'planned', 20, 'My Goal');

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveCount(1);
    expect($result['errors'][0]['code'])->toBe('empty_sprint');
    expect($result['warnings'])->toBe([]);
});

it('V-07: empty sprint without goal → 1 error empty_sprint + 1 warning missing_goal', function () {
    $ctx = vspSetup('VP7');
    $sprint = vspSprint($ctx['project'], 'planned', 10, null);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveCount(1);
    expect($result['errors'][0]['code'])->toBe('empty_sprint');
    expect($result['warnings'])->toHaveCount(1);
    expect($result['warnings'][0]['code'])->toBe('missing_goal');
});

it('V-08: empty sprint — RM-05 and RM-06 not executed', function () {
    $ctx = vspSetup('VP8');
    $sprint = vspSprint($ctx['project'], 'planned', 10, 'Goal');

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $codes = array_column($result['errors'], 'code');
    expect($codes)->not->toContain('missing_estimation');
    expect($codes)->not->toContain('blocking_dependency');
});

// ============================================================
// RM-05 — missing_estimation
// ============================================================

it('V-09: 1 story story_points=null → 1 error missing_estimation with item_identifier', function () {
    $ctx = vspSetup('VP9');
    $sprint = vspSprint($ctx['project'], 'planned', 10, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => null, 'statut' => 'open']);
    vspAttach($sprint, $story);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['ok'])->toBeFalse();
    $estErrors = array_filter($result['errors'], fn ($e) => $e['code'] === 'missing_estimation');
    expect($estErrors)->toHaveCount(1);
    $err = reset($estErrors);
    expect($err['item_identifier'])->toBe($story->identifier);
});

it('V-10: 1 story story_points=0 → no missing_estimation error (0 is valid)', function () {
    $ctx = vspSetup('VP10');
    $sprint = vspSprint($ctx['project'], 'planned', 10, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => 0, 'statut' => 'open']);
    vspAttach($sprint, $story);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $codes = array_column($result['errors'], 'code');
    expect($codes)->not->toContain('missing_estimation');
});

it('V-11: 3 stories without estimation → 3 distinct missing_estimation errors', function () {
    $ctx = vspSetup('VP11');
    $sprint = vspSprint($ctx['project'], 'planned', 30, 'Goal');
    $s1 = vspStory($ctx['project'], ['story_points' => null]);
    $s2 = vspStory($ctx['project'], ['story_points' => null]);
    $s3 = vspStory($ctx['project'], ['story_points' => null]);
    vspAttach($sprint, $s1);
    vspAttach($sprint, $s2);
    vspAttach($sprint, $s3);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $estErrors = array_filter($result['errors'], fn ($e) => $e['code'] === 'missing_estimation');
    expect($estErrors)->toHaveCount(3);
});

it('V-12: 1 standalone task without story_points → no missing_estimation error (tasks excluded)', function () {
    $ctx = vspSetup('VP12');
    $sprint = vspSprint($ctx['project'], 'planned', 10, 'Goal');
    $task = Task::factory()->standalone()->create(['project_id' => $ctx['project']->id]);
    vspAttach($sprint, $task);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $codes = array_column($result['errors'], 'code');
    expect($codes)->not->toContain('missing_estimation');
});

it('V-13: 2 estimated stories + 1 standalone task → no missing_estimation error', function () {
    $ctx = vspSetup('VP13');
    $sprint = vspSprint($ctx['project'], 'planned', 20, 'Goal');
    $s1 = vspStory($ctx['project'], ['story_points' => 5]);
    $s2 = vspStory($ctx['project'], ['story_points' => 3]);
    $task = Task::factory()->standalone()->create(['project_id' => $ctx['project']->id]);
    vspAttach($sprint, $s1);
    vspAttach($sprint, $s2);
    vspAttach($sprint, $task);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $codes = array_column($result['errors'], 'code');
    expect($codes)->not->toContain('missing_estimation');
});

// ============================================================
// RM-06 — blocking_dependency
// ============================================================

it('V-14: story blocked by open item → 1 error with blocking_identifier and blocking_status', function () {
    $ctx = vspSetup('VP14');
    $sprint = vspSprint($ctx['project'], 'planned', 20, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => 5, 'statut' => 'open']);
    $blocker = vspStory($ctx['project'], ['story_points' => 3, 'statut' => 'open']);
    vspAttach($sprint, $story);

    $depService = app(DependencyService::class);
    $depService->addDependency($story, $blocker);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $depErrors = array_filter($result['errors'], fn ($e) => $e['code'] === 'blocking_dependency');
    expect($depErrors)->toHaveCount(1);
    $err = reset($depErrors);
    expect($err['item_identifier'])->toBe($story->identifier);
    expect($err['blocking_identifier'])->toBe($blocker->identifier);
    expect($err['blocking_status'])->toBe('open');
});

it('V-15: story blocked by closed item → 0 blocking_dependency errors (dependency resolved)', function () {
    $ctx = vspSetup('VP15');
    $sprint = vspSprint($ctx['project'], 'planned', 20, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => 5, 'statut' => 'open']);
    $blocker = vspStory($ctx['project'], ['story_points' => 3, 'statut' => 'closed']);
    vspAttach($sprint, $story);

    $depService = app(DependencyService::class);
    $depService->addDependency($story, $blocker);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $depErrors = array_filter($result['errors'], fn ($e) => $e['code'] === 'blocking_dependency');
    expect($depErrors)->toBe([]);
});

it('V-16: story blocked by 2 items (1 closed, 1 open) → 1 error for the open one', function () {
    $ctx = vspSetup('VP16');
    $sprint = vspSprint($ctx['project'], 'planned', 20, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => 5, 'statut' => 'open']);
    $closedBlocker = vspStory($ctx['project'], ['story_points' => 3, 'statut' => 'closed']);
    $openBlocker = vspStory($ctx['project'], ['story_points' => 2, 'statut' => 'open']);
    vspAttach($sprint, $story);

    $depService = app(DependencyService::class);
    $depService->addDependency($story, $closedBlocker);
    $depService->addDependency($story, $openBlocker);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $depErrors = array_filter($result['errors'], fn ($e) => $e['code'] === 'blocking_dependency');
    expect($depErrors)->toHaveCount(1);
    $err = reset($depErrors);
    expect($err['blocking_identifier'])->toBe($openBlocker->identifier);
});

it('V-17: story blocked by item also in the sprint → error reported (QO-8)', function () {
    $ctx = vspSetup('VP17');
    $sprint = vspSprint($ctx['project'], 'planned', 20, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => 5, 'statut' => 'open']);
    $blocker = vspStory($ctx['project'], ['story_points' => 3, 'statut' => 'open']);
    vspAttach($sprint, $story);
    vspAttach($sprint, $blocker);

    $depService = app(DependencyService::class);
    $depService->addDependency($story, $blocker);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $depErrors = array_filter($result['errors'], fn ($e) => $e['code'] === 'blocking_dependency');
    expect($depErrors)->toHaveCount(1);
});

it('V-18: no dependencies → 0 blocking_dependency errors', function () {
    $ctx = vspSetup('VP18');
    $sprint = vspSprint($ctx['project'], 'planned', 20, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => 5, 'statut' => 'open']);
    vspAttach($sprint, $story);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $depErrors = array_filter($result['errors'], fn ($e) => $e['code'] === 'blocking_dependency');
    expect($depErrors)->toBe([]);
});

it('V-19: orphan dependency (blocking item deleted) → silently ignored', function () {
    $ctx = vspSetup('VP19');
    $sprint = vspSprint($ctx['project'], 'planned', 20, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => 5, 'statut' => 'open']);
    $blocker = vspStory($ctx['project'], ['story_points' => 3, 'statut' => 'open']);
    vspAttach($sprint, $story);

    $depService = app(DependencyService::class);
    $depService->addDependency($story, $blocker);

    // Delete the blocking story (orphan the dependency)
    $blockerArtifact = Artifact::where('artifactable_id', $blocker->id)
        ->where('artifactable_type', Story::class)
        ->first();
    $blockerArtifact?->delete();
    $blocker->delete();

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $depErrors = array_filter($result['errors'], fn ($e) => $e['code'] === 'blocking_dependency');
    expect($depErrors)->toBe([]);
});

// ============================================================
// RM-07 — over_capacity
// ============================================================

it('V-20: capacity=10, engaged=15 → warning over_capacity with engaged_points=15, capacity=10', function () {
    $ctx = vspSetup('VP20');
    $sprint = vspSprint($ctx['project'], 'planned', 10, 'Goal');
    $s1 = vspStory($ctx['project'], ['story_points' => 8]);
    $s2 = vspStory($ctx['project'], ['story_points' => 7]);
    vspAttach($sprint, $s1);
    vspAttach($sprint, $s2);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $capWarnings = array_filter($result['warnings'], fn ($w) => $w['code'] === 'over_capacity');
    expect($capWarnings)->toHaveCount(1);
    $w = reset($capWarnings);
    expect($w['engaged_points'])->toBe(15);
    expect($w['capacity'])->toBe(10);
});

it('V-21: capacity=10, engaged=10 → no over_capacity warning (equality is OK)', function () {
    $ctx = vspSetup('VP21');
    $sprint = vspSprint($ctx['project'], 'planned', 10, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => 10]);
    vspAttach($sprint, $story);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $capWarnings = array_filter($result['warnings'], fn ($w) => $w['code'] === 'over_capacity');
    expect($capWarnings)->toBe([]);
});

it('V-22: capacity=null, engaged=15 → no over_capacity warning', function () {
    $ctx = vspSetup('VP22');
    $sprint = vspSprint($ctx['project'], 'planned', null, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => 15]);
    vspAttach($sprint, $story);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $capWarnings = array_filter($result['warnings'], fn ($w) => $w['code'] === 'over_capacity');
    expect($capWarnings)->toBe([]);
});

it('V-23: capacity=0, engaged=15 → no over_capacity warning', function () {
    $ctx = vspSetup('VP23');
    $sprint = vspSprint($ctx['project'], 'planned', 0, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => 15]);
    vspAttach($sprint, $story);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $capWarnings = array_filter($result['warnings'], fn ($w) => $w['code'] === 'over_capacity');
    expect($capWarnings)->toBe([]);
});

it('V-24: story story_points=null (RM-05 error) + story story_points=20, capacity=10 → over_capacity warning with engaged_points=20', function () {
    $ctx = vspSetup('VP24');
    $sprint = vspSprint($ctx['project'], 'planned', 10, 'Goal');
    $s1 = vspStory($ctx['project'], ['story_points' => null]);
    $s2 = vspStory($ctx['project'], ['story_points' => 20]);
    vspAttach($sprint, $s1);
    vspAttach($sprint, $s2);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $capWarnings = array_filter($result['warnings'], fn ($w) => $w['code'] === 'over_capacity');
    expect($capWarnings)->toHaveCount(1);
    $w = reset($capWarnings);
    expect($w['engaged_points'])->toBe(20);
    expect($w['capacity'])->toBe(10);
});

// ============================================================
// RM-08 — missing_goal
// ============================================================

it('V-25: goal=null → warning missing_goal', function () {
    $ctx = vspSetup('VP25');
    $sprint = vspSprint($ctx['project'], 'planned', 10, null);
    $story = vspStory($ctx['project'], ['story_points' => 5]);
    vspAttach($sprint, $story);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $goalWarnings = array_filter($result['warnings'], fn ($w) => $w['code'] === 'missing_goal');
    expect($goalWarnings)->toHaveCount(1);
});

it('V-26: goal="   " (spaces only) → warning missing_goal', function () {
    $ctx = vspSetup('VP26');
    $sprint = vspSprint($ctx['project'], 'planned', 10, '   ');
    $story = vspStory($ctx['project'], ['story_points' => 5]);
    vspAttach($sprint, $story);

    // Manually set goal with spaces (bypassing trim in Sprint create)
    $sprint->goal = '   ';
    $sprint->save();

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $goalWarnings = array_filter($result['warnings'], fn ($w) => $w['code'] === 'missing_goal');
    expect($goalWarnings)->toHaveCount(1);
});

it('V-27: goal="Deliver feature X" → no missing_goal warning', function () {
    $ctx = vspSetup('VP27');
    $sprint = vspSprint($ctx['project'], 'planned', 10, 'Deliver feature X');
    $story = vspStory($ctx['project'], ['story_points' => 5]);
    vspAttach($sprint, $story);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $goalWarnings = array_filter($result['warnings'], fn ($w) => $w['code'] === 'missing_goal');
    expect($goalWarnings)->toBe([]);
});

// ============================================================
// Transverse tests
// ============================================================

it('V-28: sprint in status active → tool works', function () {
    $ctx = vspSetup('VP28');
    $sprint = vspSprint($ctx['project'], 'active', 10, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => 5]);
    vspAttach($sprint, $story);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result)->toHaveKey('ok');
    expect($result)->toHaveKey('sprint_identifier');
});

it('V-29: sprint in status completed → tool works', function () {
    $ctx = vspSetup('VP29');
    $sprint = vspSprint($ctx['project'], 'completed', 10, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => 5]);
    vspAttach($sprint, $story);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result)->toHaveKey('ok');
});

it('V-30: sprint in status cancelled → tool works', function () {
    $ctx = vspSetup('VP30');
    $sprint = vspSprint($ctx['project'], 'cancelled', 10, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => 5]);
    vspAttach($sprint, $story);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result)->toHaveKey('ok');
});

it('V-31: sprint with all 4 issues → 2 errors + 2 warnings, ok=false, stable order', function () {
    $ctx = vspSetup('VP31');
    $sprint = vspSprint($ctx['project'], 'planned', 10, null);

    // Story 1: missing estimation (RM-05)
    $s1 = vspStory($ctx['project'], ['story_points' => null, 'statut' => 'open']);
    vspAttach($sprint, $s1);

    // Story 2: blocking dependency unresolved (RM-06)
    $s2 = vspStory($ctx['project'], ['story_points' => 3, 'statut' => 'open']);
    $blocker = vspStory($ctx['project'], ['story_points' => 2, 'statut' => 'open']);
    vspAttach($sprint, $s2);
    $depService = app(DependencyService::class);
    $depService->addDependency($s2, $blocker);

    // capacity exceeded: 3 SP but capacity=10... we need more to exceed
    // Let's update the sprint capacity to be lower than 3
    $sprint->capacity = 2;
    $sprint->save();

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['ok'])->toBeFalse();

    $errorCodes = array_column($result['errors'], 'code');
    expect(in_array('missing_estimation', $errorCodes))->toBeTrue();
    expect(in_array('blocking_dependency', $errorCodes))->toBeTrue();

    $warningCodes = array_column($result['warnings'], 'code');
    expect(in_array('over_capacity', $warningCodes))->toBeTrue();
    expect(in_array('missing_goal', $warningCodes))->toBeTrue();

    // Stable order: errors first (RM-05 before RM-06), warnings (RM-07 before RM-08)
    $firstErrorIdx = array_search('missing_estimation', $errorCodes);
    $secondErrorIdx = array_search('blocking_dependency', $errorCodes);
    expect($firstErrorIdx)->toBeLessThan($secondErrorIdx);

    $firstWarningIdx = array_search('over_capacity', $warningCodes);
    $secondWarningIdx = array_search('missing_goal', $warningCodes);
    expect($firstWarningIdx)->toBeLessThan($secondWarningIdx);
});

it('V-32: idempotence — 2 successive calls return identical responses', function () {
    $ctx = vspSetup('VP32');
    $sprint = vspSprint($ctx['project'], 'planned', 10, 'Goal');
    $story = vspStory($ctx['project'], ['story_points' => null]);
    vspAttach($sprint, $story);

    $args = ['sprint_identifier' => $sprint->identifier];
    $r1 = vspOk(mcpVsp('validate_sprint_plan', $args, $ctx['manager_token']));
    $r2 = vspOk(mcpVsp('validate_sprint_plan', $args, $ctx['manager_token']));

    expect($r1)->toBe($r2);
});

it('V-33: summary.items_count and summary.engaged_points are coherent', function () {
    $ctx = vspSetup('VP33');
    $sprint = vspSprint($ctx['project'], 'planned', 50, 'Goal');
    $s1 = vspStory($ctx['project'], ['story_points' => 8]);
    $s2 = vspStory($ctx['project'], ['story_points' => 5]);
    $task = Task::factory()->standalone()->create(['project_id' => $ctx['project']->id]);
    vspAttach($sprint, $s1);
    vspAttach($sprint, $s2);
    vspAttach($sprint, $task);

    $result = vspOk(mcpVsp('validate_sprint_plan', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['summary']['items_count'])->toBe(3);
    expect($result['summary']['engaged_points'])->toBe(13); // tasks excluded from points
    expect($result['summary']['capacity'])->toBe(50);
});

it('V-34: tools/list includes validate_sprint_plan when scrum module is active', function () {
    $ctx = vspSetup('VP34');

    $response = test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/list',
        'params' => ['project_code' => 'VP34'],
    ], ['Authorization' => 'Bearer '.$ctx['manager_token']]);

    $response->assertOk();
    $names = array_column($response->json('result.tools'), 'name');
    expect($names)->toContain('validate_sprint_plan');
    // Scrum module now has 20 tools (19 from POIESIS-2..8 + validate_sprint_plan)
    $scrumToolNames = [
        'create_sprint', 'list_sprints', 'get_sprint', 'update_sprint', 'delete_sprint',
        'start_sprint', 'close_sprint', 'cancel_sprint',
        'add_to_sprint', 'remove_from_sprint', 'list_sprint_items',
        'list_backlog', 'reorder_backlog',
        'estimate_story', 'mark_ready', 'mark_unready',
        'start_planning', 'add_to_planning', 'remove_from_planning',
        'validate_sprint_plan',
    ];
    foreach ($scrumToolNames as $toolName) {
        expect($names)->toContain($toolName);
    }
});
