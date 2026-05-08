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
use Illuminate\Testing\TestResponse;

// ============================================================
// Setup helpers (POIESIS-107 — start/unstart tools + auto started_at hook)
// ============================================================

/**
 * @return array{tenant: Tenant, project: Project, manager_token: string, viewer_token: string}
 */
function startSetup(string $code = 'STR'): array
{
    $tenant = Tenant::factory()->create();
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

    $project = Project::factory()->create(['code' => $code, 'tenant_id' => $tenant->id]);
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $manager->id, 'position' => 'owner']);
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $viewer->id, 'position' => 'member']);

    return [
        'tenant' => $tenant,
        'project' => $project,
        'manager_token' => $managerRaw['raw'],
        'viewer_token' => $viewerRaw['raw'],
    ];
}

function mcpStartCall(string $tool, array $args, string $token): TestResponse
{
    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $args],
    ], ['Authorization' => 'Bearer '.$token]);
}

function startSuccess(TestResponse $response): array
{
    $response->assertOk();
    $data = $response->json();
    expect($data)->not->toHaveKey('error');

    return json_decode($data['result']['content'][0]['text'], true);
}

function startError(TestResponse $response, string $contains): void
{
    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveKey('error');
    expect($data['error']['message'])->toContain($contains);
}

// ============================================================
// Hook: auto started_at on transition to `open`
// ============================================================

it('auto-fills started_at on Task transition to open', function () {
    $ctx = startSetup();
    $story = Story::factory()->create([
        'epic_id' => Epic::factory()->create(['project_id' => $ctx['project']->id])->id,
    ]);
    $task = Task::factory()->create([
        'project_id' => $ctx['project']->id,
        'story_id' => $story->id,
        'statut' => 'draft',
    ]);
    expect($task->started_at)->toBeNull();

    $task->statut = 'open';
    $task->save();

    expect($task->fresh()->started_at)->not->toBeNull();
});

it('auto-fills started_at on Story transition to open', function () {
    $ctx = startSetup();
    $story = Story::factory()->create([
        'epic_id' => Epic::factory()->create(['project_id' => $ctx['project']->id])->id,
        'statut' => 'draft',
    ]);
    expect($story->started_at)->toBeNull();

    $story->statut = 'open';
    $story->save();

    expect($story->fresh()->started_at)->not->toBeNull();
});

it('does not overwrite started_at on subsequent status changes', function () {
    $ctx = startSetup();
    $story = Story::factory()->create([
        'epic_id' => Epic::factory()->create(['project_id' => $ctx['project']->id])->id,
        'statut' => 'open',
    ]);
    $story->statut = 'open';
    $story->save();
    $first = $story->fresh()->started_at;
    expect($first)->not->toBeNull();

    // Move closed then back to open.
    $story->statut = 'closed';
    $story->save();
    $story->statut = 'open';
    $story->save();

    expect($story->fresh()->started_at?->toIso8601String())
        ->toBe($first->toIso8601String());
});

it('does not set started_at on direct draft -> closed', function () {
    $ctx = startSetup();
    $story = Story::factory()->create([
        'epic_id' => Epic::factory()->create(['project_id' => $ctx['project']->id])->id,
        'statut' => 'draft',
    ]);

    $story->statut = 'closed';
    $story->save();

    expect($story->fresh()->started_at)->toBeNull();
});

// ============================================================
// start_task / unstart_task tools
// ============================================================

it('start_task sets started_at on a draft task', function () {
    $ctx = startSetup();
    $story = Story::factory()->create([
        'epic_id' => Epic::factory()->create(['project_id' => $ctx['project']->id])->id,
    ]);
    $task = Task::factory()->create([
        'project_id' => $ctx['project']->id,
        'story_id' => $story->id,
        'statut' => 'draft',
    ]);
    expect($task->started_at)->toBeNull();

    $result = startSuccess(mcpStartCall('start_task', ['identifier' => $task->identifier], $ctx['manager_token']));

    expect($result['is_started'])->toBeTrue();
    expect($result['started_at'])->not->toBeNull();
    expect($task->fresh()->statut)->toBe('draft'); // statut unchanged
});

it('start_task is idempotent (second call keeps the original timestamp)', function () {
    $ctx = startSetup();
    $story = Story::factory()->create([
        'epic_id' => Epic::factory()->create(['project_id' => $ctx['project']->id])->id,
    ]);
    $task = Task::factory()->create([
        'project_id' => $ctx['project']->id,
        'story_id' => $story->id,
        'statut' => 'draft',
    ]);

    startSuccess(mcpStartCall('start_task', ['identifier' => $task->identifier], $ctx['manager_token']));
    $first = $task->fresh()->started_at;

    sleep(1); // ensure clock would tick
    startSuccess(mcpStartCall('start_task', ['identifier' => $task->identifier], $ctx['manager_token']));
    $second = $task->fresh()->started_at;

    expect($second?->toIso8601String())->toBe($first?->toIso8601String());
});

it('start_task refuses a closed task with task.cannot_start_closed', function () {
    $ctx = startSetup();
    $story = Story::factory()->create([
        'epic_id' => Epic::factory()->create(['project_id' => $ctx['project']->id])->id,
    ]);
    $task = Task::factory()->create([
        'project_id' => $ctx['project']->id,
        'story_id' => $story->id,
        'statut' => 'closed',
    ]);

    startError(mcpStartCall('start_task', ['identifier' => $task->identifier], $ctx['manager_token']),
        'task.cannot_start_closed');
});

it('unstart_task clears started_at without changing statut', function () {
    $ctx = startSetup();
    $story = Story::factory()->create([
        'epic_id' => Epic::factory()->create(['project_id' => $ctx['project']->id])->id,
    ]);
    $task = Task::factory()->create([
        'project_id' => $ctx['project']->id,
        'story_id' => $story->id,
        'statut' => 'open',
        'started_at' => now(),
    ]);

    $result = startSuccess(mcpStartCall('unstart_task', ['identifier' => $task->identifier], $ctx['manager_token']));

    expect($result['started_at'])->toBeNull();
    expect($result['is_started'])->toBeFalse();
    expect($task->fresh()->statut)->toBe('open');
});

it('unstart_task is idempotent on a never-started task', function () {
    $ctx = startSetup();
    $story = Story::factory()->create([
        'epic_id' => Epic::factory()->create(['project_id' => $ctx['project']->id])->id,
    ]);
    $task = Task::factory()->create([
        'project_id' => $ctx['project']->id,
        'story_id' => $story->id,
        'statut' => 'draft',
    ]);

    $result = startSuccess(mcpStartCall('unstart_task', ['identifier' => $task->identifier], $ctx['manager_token']));

    expect($result['started_at'])->toBeNull();
});

it('start_task is forbidden for viewers', function () {
    $ctx = startSetup();
    $story = Story::factory()->create([
        'epic_id' => Epic::factory()->create(['project_id' => $ctx['project']->id])->id,
    ]);
    $task = Task::factory()->create([
        'project_id' => $ctx['project']->id,
        'story_id' => $story->id,
        'statut' => 'draft',
    ]);

    startError(mcpStartCall('start_task', ['identifier' => $task->identifier], $ctx['viewer_token']),
        'permission');
});

// ============================================================
// start_story / unstart_story tools (mirror of task tools)
// ============================================================

it('start_story sets started_at on a draft story', function () {
    $ctx = startSetup();
    $story = Story::factory()->create([
        'epic_id' => Epic::factory()->create(['project_id' => $ctx['project']->id])->id,
        'statut' => 'draft',
    ]);

    $result = startSuccess(mcpStartCall('start_story', ['identifier' => $story->identifier], $ctx['manager_token']));

    expect($result['is_started'])->toBeTrue();
    expect($story->fresh()->statut)->toBe('draft');
});

it('start_story refuses a closed story with story.cannot_start_closed', function () {
    $ctx = startSetup();
    $story = Story::factory()->create([
        'epic_id' => Epic::factory()->create(['project_id' => $ctx['project']->id])->id,
        'statut' => 'closed',
    ]);

    startError(mcpStartCall('start_story', ['identifier' => $story->identifier], $ctx['manager_token']),
        'story.cannot_start_closed');
});

it('unstart_story clears started_at on a started story', function () {
    $ctx = startSetup();
    $story = Story::factory()->create([
        'epic_id' => Epic::factory()->create(['project_id' => $ctx['project']->id])->id,
        'statut' => 'open',
        'started_at' => now(),
    ]);

    $result = startSuccess(mcpStartCall('unstart_story', ['identifier' => $story->identifier], $ctx['manager_token']));

    expect($result['started_at'])->toBeNull();
});

// ============================================================
// format() exposes started_at and is_started
// ============================================================

it('Task::format() exposes started_at and is_started', function () {
    $ctx = startSetup();
    $story = Story::factory()->create([
        'epic_id' => Epic::factory()->create(['project_id' => $ctx['project']->id])->id,
    ]);
    $started = Task::factory()->create([
        'project_id' => $ctx['project']->id,
        'story_id' => $story->id,
        'statut' => 'open',
        'started_at' => now(),
    ]);
    $idle = Task::factory()->create([
        'project_id' => $ctx['project']->id,
        'story_id' => $story->id,
        'statut' => 'open',
    ]);

    expect($started->format())->toHaveKey('started_at');
    expect($started->format()['is_started'])->toBeTrue();
    expect($idle->format()['is_started'])->toBeFalse();
});
