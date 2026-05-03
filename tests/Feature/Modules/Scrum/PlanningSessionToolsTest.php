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
function planSetup(string $code = 'PLN'): array
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

function planSprint(Project $project, string $status = 'planned', ?int $capacity = null): Sprint
{
    return Sprint::create([
        'tenant_id' => $project->tenant_id,
        'project_id' => $project->id,
        'name' => 'Planning Sprint',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-15',
        'status' => $status,
        'capacity' => $capacity,
    ]);
}

function planStory(Project $project, array $overrides = []): Story
{
    $epic = Epic::factory()->create(['project_id' => $project->id]);

    return Story::factory()->create(array_merge(['epic_id' => $epic->id], $overrides));
}

function planReadyStory(Project $project, array $overrides = []): Story
{
    $epic = Epic::factory()->create(['project_id' => $project->id]);

    return Story::factory()->create(array_merge([
        'epic_id' => $epic->id,
        'story_points' => 3,
        'description' => 'A well-described story',
        'ready' => true,
        'statut' => 'open',
    ], $overrides));
}

function mcpPlan(string $tool, array $args, string $token): TestResponse
{
    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $args],
    ], ['Authorization' => 'Bearer '.$token]);
}

function planOk(TestResponse $response): mixed
{
    $response->assertOk();
    $data = $response->json();
    expect($data)->not->toHaveKey('error');

    return json_decode($data['result']['content'][0]['text'], true);
}

function planErr(TestResponse $response, string $contains): void
{
    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveKey('error');
    expect($data['error']['message'])->toContain($contains);
}

// ============================================================
// start_planning — happy path
// ============================================================

it('P-01: start_planning returns snapshot with 0 engaged items and ready backlog', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'planned', 20);
    planReadyStory($ctx['project']);
    planReadyStory($ctx['project']);

    $result = planOk(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['sprint']['identifier'])->toBe($sprint->identifier);
    expect($result['capacity'])->toBe(20);
    expect($result['engaged_points'])->toBe(0);
    expect($result['ratio_engaged'])->toBe(0);
    expect($result['engaged_items'])->toBe([]);
    expect(count($result['ready_backlog']))->toBe(2);
});

it('P-02: start_planning computes engaged_points from stories only (tasks excluded)', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'planned', 20);
    $story = planReadyStory($ctx['project'], ['story_points' => 5]);

    // Attach via add_to_sprint (no DoR guard) — puts a story in sprint
    $token = $ctx['manager_token'];
    planOk(mcpPlan('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $token));

    // Also attach a standalone task
    $task = Task::factory()->standalone()->create(['project_id' => $ctx['project']->id]);
    planOk(mcpPlan('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $task->identifier,
    ], $token));

    $result = planOk(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $token));

    expect($result['engaged_points'])->toBe(5);
    expect($result['ratio_engaged'])->toBe(0.25);
    expect(count($result['engaged_items']))->toBe(2); // story + task both shown
});

it('P-03: start_planning counts story without story_points as 0', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'planned', 10);
    $story = planStory($ctx['project'], ['statut' => 'open', 'story_points' => null]);

    // Force attach via add_to_sprint (permissive)
    planOk(mcpPlan('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    $result = planOk(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['engaged_points'])->toBe(0);
});

it('P-04: start_planning returns ratio_engaged=null when capacity is null', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'planned', null);

    $result = planOk(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['capacity'])->toBeNull();
    expect($result['ratio_engaged'])->toBeNull();
});

it('P-05: start_planning returns ratio_engaged=null when capacity is 0', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'planned', 0);

    $result = planOk(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['capacity'])->toBe(0);
    expect($result['ratio_engaged'])->toBeNull();
});

it('P-06: start_planning rejects sprint in status active', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'active');

    planErr(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']), "Cannot start planning on a sprint in status 'active'");
});

it('P-07: start_planning rejects sprint in status completed', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'completed');

    planErr(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']), "Cannot start planning on a sprint in status 'completed'");
});

it('P-08: start_planning rejects sprint in status cancelled', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'cancelled');

    planErr(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']), "Cannot start planning on a sprint in status 'cancelled'");
});

it('P-09: start_planning excludes stories with ready=false from ready_backlog', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    planReadyStory($ctx['project']); // ready=true
    planStory($ctx['project'], ['statut' => 'open', 'ready' => false]); // ready=false

    $result = planOk(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect(count($result['ready_backlog']))->toBe(1);
    expect($result['ready_backlog'][0]['ready'])->toBeTrue();
});

it('P-10: start_planning excludes closed stories from ready_backlog', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    planReadyStory($ctx['project'], ['statut' => 'closed']); // ready but closed

    $result = planOk(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['ready_backlog'])->toBe([]);
});

it('P-11: start_planning excludes stories already in another planned sprint from ready_backlog', function () {
    $ctx = planSetup();
    $sprint1 = planSprint($ctx['project']);
    $sprint2 = planSprint($ctx['project']);
    $story = planReadyStory($ctx['project']);

    // Attach story to sprint1
    planOk(mcpPlan('add_to_sprint', [
        'sprint_identifier' => $sprint1->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    // sprint2 ready_backlog should not include the story
    $result = planOk(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint2->identifier,
    ], $ctx['manager_token']));

    $identifiers = array_column($result['ready_backlog'], 'identifier');
    expect($identifiers)->not->toContain($story->identifier);
});

it('P-12: start_planning is accessible to viewer (read-only)', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);

    $result = planOk(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['viewer_token']));

    expect($result['sprint']['identifier'])->toBe($sprint->identifier);
});

it('P-13: start_planning rejects non-member with Sprint not found', function () {
    $ctxA = planSetup('PLNA');
    $ctxB = planSetup('PLNB');
    $sprintB = planSprint($ctxB['project']);
    $sprintBId = $sprintB->identifier;

    app(TenantManager::class)->setTenant($ctxA['tenant']);

    planErr(mcpPlan('start_planning', [
        'sprint_identifier' => $sprintBId,
    ], $ctxA['manager_token']), 'Sprint not found.');
});

it('P-14: start_planning rejects malformed sprint identifier', function () {
    $ctx = planSetup();

    planErr(mcpPlan('start_planning', [
        'sprint_identifier' => 'invalid',
    ], $ctx['manager_token']), 'Invalid sprint identifier format.');
});

it('P-15: start_planning excludes draft stories even when they are ready', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $openStory = planReadyStory($ctx['project']);
    $draftStory = planReadyStory($ctx['project'], ['statut' => 'draft']);

    $result = planOk(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    $identifiers = array_column($result['ready_backlog'], 'identifier');
    expect($identifiers)->toContain($openStory->identifier);
    expect($identifiers)->not->toContain($draftStory->identifier);
});

it('P-16: start_planning limits ready_backlog to 100 stories', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);

    for ($i = 0; $i < 101; $i++) {
        planReadyStory($ctx['project'], ['rank' => $i]);
    }

    $result = planOk(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    expect($result['ready_backlog'])->toHaveCount(100);
});

// ============================================================
// add_to_planning — happy path
// ============================================================

it('P-17: add_to_planning happy path: 3 ready stories engaged, summary updated', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'planned', 20);
    $s1 = planReadyStory($ctx['project'], ['story_points' => 3]);
    $s2 = planReadyStory($ctx['project'], ['story_points' => 5]);
    $s3 = planReadyStory($ctx['project'], ['story_points' => 2]);

    $result = planOk(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $s2->identifier, $s3->identifier],
    ], $ctx['manager_token']));

    expect($result['message'])->toBe('Stories added to planning.');
    expect($result['added_count'])->toBe(3);
    expect(count($result['added_items']))->toBe(3);
    expect($result['engaged_points'])->toBe(10);
    expect($result['ratio_engaged'])->toBe(0.5);
    expect(SprintItem::count())->toBe(3);
});

it('P-18: add_to_planning refuses batch if one story is not ready', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);
    $s2 = planReadyStory($ctx['project']);
    $s3 = planStory($ctx['project'], ['statut' => 'open', 'ready' => false, 'story_points' => null]);

    $countBefore = SprintItem::count();

    planErr(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $s2->identifier, $s3->identifier],
    ], $ctx['manager_token']), 'not ready');

    expect(SprintItem::count())->toBe($countBefore);
});

it('P-19: add_to_planning refuses batch if one story belongs to another project', function () {
    $ctxA = planSetup('PJNA');
    $sprint = planSprint($ctxA['project']);
    $s1 = planReadyStory($ctxA['project']);

    // Create project B within same tenant but different project
    $projectB = Project::factory()->create([
        'code' => 'PJNB',
        'tenant_id' => $ctxA['tenant']->id,
        'modules' => ['scrum'],
    ]);
    $storyB = planReadyStory($projectB);

    $countBefore = SprintItem::count();

    planErr(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $storyB->identifier],
    ], $ctxA['manager_token']), 'not found in this project');

    expect(SprintItem::count())->toBe($countBefore);
});

it('P-20: add_to_planning refuses batch if one story already in another sprint with mention', function () {
    $ctx = planSetup();
    $sprint1 = planSprint($ctx['project']);
    $sprint2 = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);
    $s2 = planReadyStory($ctx['project']);

    // Attach s2 to sprint1 via add_to_sprint
    planOk(mcpPlan('add_to_sprint', [
        'sprint_identifier' => $sprint1->identifier,
        'item_identifier' => $s2->identifier,
    ], $ctx['manager_token']));

    $countBefore = SprintItem::count();

    $response = mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint2->identifier,
        'story_identifiers' => [$s1->identifier, $s2->identifier],
    ], $ctx['manager_token']);

    planErr($response, $sprint1->identifier);
    expect(SprintItem::count())->toBe($countBefore);
});

it('P-21: add_to_planning refuses batch if one story already in this sprint', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);
    $s2 = planReadyStory($ctx['project']);

    // Attach s1 first
    planOk(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier],
    ], $ctx['manager_token']));

    $countBefore = SprintItem::count();

    planErr(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s2->identifier, $s1->identifier],
    ], $ctx['manager_token']), 'already in this sprint');

    expect(SprintItem::count())->toBe($countBefore);
});

it('P-22: add_to_planning refuses batch if one identifier resolves to a Task', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);
    $task = Task::factory()->standalone()->create(['project_id' => $ctx['project']->id]);

    $countBefore = SprintItem::count();

    planErr(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $task->identifier],
    ], $ctx['manager_token']), 'not a story');

    expect(SprintItem::count())->toBe($countBefore);
});

it('P-23: add_to_planning refuses batch if one identifier resolves to an Epic', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);
    $epic = Epic::factory()->create(['project_id' => $ctx['project']->id]);

    $countBefore = SprintItem::count();

    planErr(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $epic->identifier],
    ], $ctx['manager_token']), 'not a story');

    expect(SprintItem::count())->toBe($countBefore);
});

it('P-24: add_to_planning refuses batch if one story is closed', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);
    $closed = planReadyStory($ctx['project'], ['statut' => 'closed']);

    $countBefore = SprintItem::count();

    planErr(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $closed->identifier],
    ], $ctx['manager_token']), 'cannot plan a closed story');

    expect(SprintItem::count())->toBe($countBefore);
});

it('P-25: add_to_planning rejects duplicate identifiers', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);

    planErr(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $s1->identifier],
    ], $ctx['manager_token']), 'Duplicate identifier in story_identifiers');
});

it('P-26: add_to_planning rejects empty story_identifiers', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);

    planErr(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [],
    ], $ctx['manager_token']), 'story_identifiers cannot be empty');
});

it('P-27: add_to_planning rejects sprint in status active', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'active');
    $s1 = planReadyStory($ctx['project']);

    planErr(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier],
    ], $ctx['manager_token']), "Cannot add to planning on a sprint in status 'active'");
});

it('P-28: add_to_planning rejects sprint in status completed', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'completed');
    $s1 = planReadyStory($ctx['project']);

    planErr(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier],
    ], $ctx['manager_token']), "Cannot add to planning on a sprint in status 'completed'");
});

it('P-29: add_to_planning is atomic: 5 valid + 1 invalid → DB unchanged', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $stories = [];
    for ($i = 0; $i < 5; $i++) {
        $stories[] = planReadyStory($ctx['project']);
    }
    $badStory = planStory($ctx['project'], ['statut' => 'open', 'ready' => false]);

    $countBefore = SprintItem::count();

    $identifiers = array_map(fn (Story $s) => $s->identifier, $stories);
    $identifiers[] = $badStory->identifier;

    planErr(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => $identifiers,
    ], $ctx['manager_token']), 'not ready');

    expect(SprintItem::count())->toBe($countBefore);
});

it('P-30: add_to_planning rejects user without manage permission', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);

    planErr(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier],
    ], $ctx['viewer_token']), 'You do not have permission to manage sprints.');
});

it('P-31: add_to_planning accepts story with story_points=0 (spike)', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'planned', 10);
    $s1 = planReadyStory($ctx['project'], ['story_points' => 0]);

    $result = planOk(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier],
    ], $ctx['manager_token']));

    expect($result['added_count'])->toBe(1);
    expect($result['engaged_points'])->toBe(0);
});

it('P-32: add_to_planning lists all violations in a single error message', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $notReady1 = planStory($ctx['project'], ['statut' => 'open', 'ready' => false, 'story_points' => null]);
    $notReady2 = planStory($ctx['project'], ['statut' => 'open', 'ready' => false, 'story_points' => null]);
    $closed = planReadyStory($ctx['project'], ['statut' => 'closed']);

    $response = mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$notReady1->identifier, $notReady2->identifier, $closed->identifier],
    ], $ctx['manager_token']);

    $data = $response->json();
    expect($data)->toHaveKey('error');
    $msg = $data['error']['message'];
    expect($msg)->toContain($notReady1->identifier);
    expect($msg)->toContain($notReady2->identifier);
    expect($msg)->toContain($closed->identifier);
    expect($msg)->toContain('Cannot add stories to planning. Violations:');
});

it('P-33: add_to_planning appends positions starting at max+1', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);
    $s2 = planReadyStory($ctx['project']);
    $s3 = planReadyStory($ctx['project']);

    $result = planOk(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $s2->identifier, $s3->identifier],
    ], $ctx['manager_token']));

    $positions = array_column($result['added_items'], 'position');
    sort($positions);
    expect($positions)->toBe([0, 1, 2]);
});

it('P-34: add_to_planning refuses a draft story even when ready=true', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $story = planReadyStory($ctx['project'], ['statut' => 'draft']);

    planErr(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$story->identifier],
    ], $ctx['manager_token']), 'must be open to plan');
});

it('P-35: add_to_planning accepts a story from a cancelled sprint', function () {
    $ctx = planSetup();
    $cancelledSprint = planSprint($ctx['project']);
    $targetSprint = planSprint($ctx['project']);
    $story = planReadyStory($ctx['project']);

    planOk(mcpPlan('add_to_sprint', [
        'sprint_identifier' => $cancelledSprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));
    planOk(mcpPlan('cancel_sprint', [
        'identifier' => $cancelledSprint->identifier,
    ], $ctx['manager_token']));

    $snapshot = planOk(mcpPlan('start_planning', [
        'sprint_identifier' => $targetSprint->identifier,
    ], $ctx['manager_token']));
    $readyIdentifiers = array_column($snapshot['ready_backlog'], 'identifier');
    expect($readyIdentifiers)->toContain($story->identifier);

    $result = planOk(mcpPlan('add_to_planning', [
        'sprint_identifier' => $targetSprint->identifier,
        'story_identifiers' => [$story->identifier],
    ], $ctx['manager_token']));

    expect($result['added_count'])->toBe(1);
    expect(SprintItem::count())->toBe(1);
    expect(SprintItem::first()?->sprint_id)->toBe($targetSprint->id);
});

// ============================================================
// remove_from_planning — happy path
// ============================================================

it('P-36: remove_from_planning happy path: 2 attached stories removed', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'planned', 20);
    $s1 = planReadyStory($ctx['project'], ['story_points' => 5]);
    $s2 = planReadyStory($ctx['project'], ['story_points' => 3]);

    planOk(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $s2->identifier],
    ], $ctx['manager_token']));

    expect(SprintItem::count())->toBe(2);

    $result = planOk(mcpPlan('remove_from_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $s2->identifier],
    ], $ctx['manager_token']));

    expect($result['message'])->toBe('Stories removed from planning.');
    expect($result['removed_count'])->toBe(2);
    expect($result['engaged_points'])->toBe(0);
    expect(SprintItem::count())->toBe(0);
});

it('P-37: remove_from_planning refuses batch if one story not attached', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);
    $s2 = planReadyStory($ctx['project']); // not in sprint

    planOk(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier],
    ], $ctx['manager_token']));

    $countBefore = SprintItem::count();

    planErr(mcpPlan('remove_from_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $s2->identifier],
    ], $ctx['manager_token']), 'not in this sprint');

    expect(SprintItem::count())->toBe($countBefore);
});

it('P-38: remove_from_planning refuses if story attached to another sprint', function () {
    $ctx = planSetup();
    $sprint1 = planSprint($ctx['project']);
    $sprint2 = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);

    planOk(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint1->identifier,
        'story_identifiers' => [$s1->identifier],
    ], $ctx['manager_token']));

    $countBefore = SprintItem::count();

    planErr(mcpPlan('remove_from_planning', [
        'sprint_identifier' => $sprint2->identifier,
        'story_identifiers' => [$s1->identifier],
    ], $ctx['manager_token']), 'not in this sprint');

    expect(SprintItem::count())->toBe($countBefore);
});

it('P-39: remove_from_planning rejects sprint in status active', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'active');
    $s1 = planReadyStory($ctx['project']);

    planErr(mcpPlan('remove_from_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier],
    ], $ctx['manager_token']), "Cannot remove from planning on a sprint in status 'active'");
});

it('P-40: remove_from_planning has no DoR check: story without story_points can be removed', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    // Force attach via add_to_sprint (permissive, no DoR)
    $story = planStory($ctx['project'], ['statut' => 'open', 'story_points' => null, 'ready' => false]);

    planOk(mcpPlan('add_to_sprint', [
        'sprint_identifier' => $sprint->identifier,
        'item_identifier' => $story->identifier,
    ], $ctx['manager_token']));

    expect(SprintItem::count())->toBe(1);

    $result = planOk(mcpPlan('remove_from_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$story->identifier],
    ], $ctx['manager_token']));

    expect($result['removed_count'])->toBe(1);
    expect(SprintItem::count())->toBe(0);
});

it('P-41: remove_from_planning is atomic: 3 valid + 1 invalid → DB unchanged', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);
    $s2 = planReadyStory($ctx['project']);
    $s3 = planReadyStory($ctx['project']);
    $notInSprint = planReadyStory($ctx['project']);

    planOk(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $s2->identifier, $s3->identifier],
    ], $ctx['manager_token']));

    $countBefore = SprintItem::count();

    planErr(mcpPlan('remove_from_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $s2->identifier, $s3->identifier, $notInSprint->identifier],
    ], $ctx['manager_token']), 'not in this sprint');

    expect(SprintItem::count())->toBe($countBefore);
});

it('P-42: remove_from_planning rejects duplicate identifiers', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);

    planOk(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier],
    ], $ctx['manager_token']));

    planErr(mcpPlan('remove_from_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $s1->identifier],
    ], $ctx['manager_token']), 'Duplicate identifier in story_identifiers');
});

it('P-43: remove_from_planning rejects empty story_identifiers', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);

    planErr(mcpPlan('remove_from_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [],
    ], $ctx['manager_token']), 'story_identifiers cannot be empty');
});

// ============================================================
// Cross-tool integration
// ============================================================

it('P-44: start_planning then add_to_planning then start_planning reflects updated engaged_points', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project'], 'planned', 20);
    $s1 = planReadyStory($ctx['project'], ['story_points' => 5]);
    $s2 = planReadyStory($ctx['project'], ['story_points' => 3]);

    $before = planOk(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));
    expect($before['engaged_points'])->toBe(0);

    planOk(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier, $s2->identifier],
    ], $ctx['manager_token']));

    $after = planOk(mcpPlan('start_planning', [
        'sprint_identifier' => $sprint->identifier,
    ], $ctx['manager_token']));
    expect($after['engaged_points'])->toBe(8);
    expect($after['ratio_engaged'])->toBe(0.4);
});

it('P-45: add_to_planning then start_sprint then add_to_planning fails (sprint now active)', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);
    $s2 = planReadyStory($ctx['project']);

    planOk(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier],
    ], $ctx['manager_token']));

    // Transition to active
    planOk(mcpPlan('start_sprint', [
        'identifier' => $sprint->identifier,
    ], $ctx['manager_token']));

    planErr(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s2->identifier],
    ], $ctx['manager_token']), "Cannot add to planning on a sprint in status 'active'");
});

it('P-46: mark_unready on engaged story creates accepted drift, no auto-removal', function () {
    $ctx = planSetup();
    $sprint = planSprint($ctx['project']);
    $s1 = planReadyStory($ctx['project']);

    planOk(mcpPlan('add_to_planning', [
        'sprint_identifier' => $sprint->identifier,
        'story_identifiers' => [$s1->identifier],
    ], $ctx['manager_token']));

    expect(SprintItem::count())->toBe(1);

    planOk(mcpPlan('mark_unready', [
        'story_identifier' => $s1->identifier,
    ], $ctx['manager_token']));

    // Story still in sprint after mark_unready
    expect(SprintItem::count())->toBe(1);
    expect($s1->fresh()->ready)->toBeFalse();
});

// ============================================================
// Module activation
// ============================================================

it('P-47: planning tools absent from tools/list when module not active', function () {
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
        'code' => 'NOPLN',
        'tenant_id' => $tenant->id,
        'modules' => [],
    ]);
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'position' => 'owner']);

    $response = test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/list',
        'params' => ['project_code' => 'NOPLN'],
    ], ['Authorization' => 'Bearer '.$raw['raw']]);

    $response->assertOk();
    $names = array_column($response->json('result.tools'), 'name');
    expect($names)->not->toContain('start_planning');
    expect($names)->not->toContain('add_to_planning');
    expect($names)->not->toContain('remove_from_planning');
});

it('P-48: tools/list returns 19 tools when scrum module is active', function () {
    $ctx = planSetup('PACT');

    $response = test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/list',
        'params' => ['project_code' => 'PACT'],
    ], ['Authorization' => 'Bearer '.$ctx['manager_token']]);

    $response->assertOk();
    $names = array_column($response->json('result.tools'), 'name');
    expect($names)->toContain('start_planning');
    expect($names)->toContain('add_to_planning');
    expect($names)->toContain('remove_from_planning');
    expect(count($names))->toBeGreaterThanOrEqual(19);
});
