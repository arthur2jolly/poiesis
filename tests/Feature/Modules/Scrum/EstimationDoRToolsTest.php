<?php

declare(strict_types=1);

use App\Core\Models\ApiToken;
use App\Core\Models\Artifact;
use App\Core\Models\Epic;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Tenant;
use App\Core\Models\User;
use App\Core\Services\TenantManager;
use Illuminate\Testing\TestResponse;

// ============================================================
// Setup helpers
// ============================================================

/**
 * @return array{tenant: Tenant, project: Project, manager_token: string, viewer_token: string}
 */
function dorSetup(string $code = 'DOR'): array
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

function dorEpic(Project $project): Epic
{
    return Epic::factory()->create(['project_id' => $project->id]);
}

function dorStory(Project $project, array $overrides = []): Story
{
    $epicId = $overrides['epic_id'] ?? dorEpic($project)->id;
    unset($overrides['epic_id']);

    return Story::factory()->create(array_merge(['epic_id' => $epicId], $overrides));
}

function mcpDor(string $tool, array $args, string $token): TestResponse
{
    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $args],
    ], ['Authorization' => 'Bearer '.$token]);
}

function dorOk(TestResponse $response): mixed
{
    $response->assertOk();
    $data = $response->json();
    expect($data)->not->toHaveKey('error');

    return json_decode($data['result']['content'][0]['text'], true);
}

function dorErr(TestResponse $response, string $contains): void
{
    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveKey('error');
    expect($data['error']['message'])->toContain($contains);
}

function storyIdentifier(Story $story): string
{
    return Artifact::where('artifactable_id', $story->id)
        ->where('artifactable_type', Story::class)
        ->value('identifier');
}

// ============================================================
// estimate_story
// ============================================================

it('T-01: estimate_story sets story_points on a story without estimation', function () {
    $ctx = dorSetup('D01');
    $story = dorStory($ctx['project'], ['story_points' => null]);
    $identifier = storyIdentifier($story);

    $result = dorOk(mcpDor('estimate_story', [
        'story_identifier' => $identifier,
        'story_points' => 5,
    ], $ctx['manager_token']));

    expect($result['story_points'])->toBe(5);
    expect($result['ready'])->toBe(false);
    expect($story->fresh()->story_points)->toBe(5);
});

it('T-02: estimate_story re-estimates an already estimated story', function () {
    $ctx = dorSetup('D02');
    $story = dorStory($ctx['project'], ['story_points' => 5]);
    $identifier = storyIdentifier($story);

    $result = dorOk(mcpDor('estimate_story', [
        'story_identifier' => $identifier,
        'story_points' => 8,
    ], $ctx['manager_token']));

    expect($result['story_points'])->toBe(8);
    expect($story->fresh()->story_points)->toBe(8);
});

it('T-03: estimate_story accepts story_points = 0', function () {
    $ctx = dorSetup('D03');
    $story = dorStory($ctx['project']);
    $identifier = storyIdentifier($story);

    $result = dorOk(mcpDor('estimate_story', [
        'story_identifier' => $identifier,
        'story_points' => 0,
    ], $ctx['manager_token']));

    expect($result['story_points'])->toBe(0);
    expect($story->fresh()->story_points)->toBe(0);
});

it('T-04: estimate_story accepts string numeric story_points', function () {
    $ctx = dorSetup('D04');
    $story = dorStory($ctx['project']);
    $identifier = storyIdentifier($story);

    $result = dorOk(mcpDor('estimate_story', [
        'story_identifier' => $identifier,
        'story_points' => '7',
    ], $ctx['manager_token']));

    expect($result['story_points'])->toBe(7);
    expect($story->fresh()->story_points)->toBe(7);
});

it('T-05: estimate_story rejects negative story_points', function () {
    $ctx = dorSetup('D05');
    $story = dorStory($ctx['project']);
    $identifier = storyIdentifier($story);

    dorErr(mcpDor('estimate_story', [
        'story_identifier' => $identifier,
        'story_points' => -1,
    ], $ctx['manager_token']), 'story_points must be a non-negative integer');
});

it('T-06: estimate_story rejects absent story_points', function () {
    $ctx = dorSetup('D06');
    $story = dorStory($ctx['project']);
    $identifier = storyIdentifier($story);

    dorErr(mcpDor('estimate_story', [
        'story_identifier' => $identifier,
    ], $ctx['manager_token']), 'story_points must be a non-negative integer');
});

it('T-07: estimate_story rejects float story_points', function () {
    $ctx = dorSetup('D07');
    $story = dorStory($ctx['project']);
    $identifier = storyIdentifier($story);

    dorErr(mcpDor('estimate_story', [
        'story_identifier' => $identifier,
        'story_points' => 5.5,
    ], $ctx['manager_token']), 'story_points must be a non-negative integer');
});

it('T-08: estimate_story does not alter ready flag', function () {
    $ctx = dorSetup('D08');
    $story = dorStory($ctx['project'], [
        'story_points' => 5,
        'description' => 'Some description',
        'ready' => true,
    ]);
    $identifier = storyIdentifier($story);

    $result = dorOk(mcpDor('estimate_story', [
        'story_identifier' => $identifier,
        'story_points' => 13,
    ], $ctx['manager_token']));

    expect($result['ready'])->toBe(true);
    expect($story->fresh()->ready)->toBe(true);
});

it('T-09: estimate_story rejects user without crud permission', function () {
    $ctx = dorSetup('D09');
    $story = dorStory($ctx['project']);
    $identifier = storyIdentifier($story);

    dorErr(mcpDor('estimate_story', [
        'story_identifier' => $identifier,
        'story_points' => 5,
    ], $ctx['viewer_token']), 'You do not have permission to manage sprints');
});

it('T-10: estimate_story rejects story from another project (cross-project)', function () {
    $ctx = dorSetup('D10');

    // Create a second project with membership
    $tenant = $ctx['tenant'];
    app(TenantManager::class)->setTenant($tenant);

    $otherProject = Project::factory()->create([
        'code' => 'D10X',
        'tenant_id' => $tenant->id,
        'modules' => ['scrum'],
    ]);

    $otherStory = dorStory($otherProject);
    $identifier = storyIdentifier($otherStory);

    dorErr(mcpDor('estimate_story', [
        'story_identifier' => $identifier,
        'story_points' => 5,
    ], $ctx['manager_token']), 'Story not found in this project');
});

it('T-11: estimate_story rejects malformed identifier', function () {
    $ctx = dorSetup('D11');

    dorErr(mcpDor('estimate_story', [
        'story_identifier' => 'INVALID',
        'story_points' => 5,
    ], $ctx['manager_token']), 'Invalid story identifier format');

    dorErr(mcpDor('estimate_story', [
        'story_identifier' => 'D11-S1',
        'story_points' => 5,
    ], $ctx['manager_token']), 'Invalid story identifier format');
});

// ============================================================
// mark_ready
// ============================================================

it('T-12: mark_ready sets ready=true when DoR is satisfied', function () {
    $ctx = dorSetup('D12');
    $story = dorStory($ctx['project'], [
        'story_points' => 5,
        'description' => 'A valid description.',
    ]);
    $identifier = storyIdentifier($story);

    $result = dorOk(mcpDor('mark_ready', [
        'story_identifier' => $identifier,
    ], $ctx['manager_token']));

    expect($result['ready'])->toBe(true);
    expect($story->fresh()->ready)->toBe(true);
});

it('T-13: mark_ready fails when story_points is null', function () {
    $ctx = dorSetup('D13');
    $story = dorStory($ctx['project'], [
        'story_points' => null,
        'description' => 'A valid description.',
    ]);
    $identifier = storyIdentifier($story);

    dorErr(mcpDor('mark_ready', [
        'story_identifier' => $identifier,
    ], $ctx['manager_token']), 'Story is not ready. Missing: story_points.');
});

it('T-14: mark_ready fails when description is empty', function () {
    $ctx = dorSetup('D14');
    $story = dorStory($ctx['project'], [
        'story_points' => 5,
        'description' => '',
    ]);
    $identifier = storyIdentifier($story);

    dorErr(mcpDor('mark_ready', [
        'story_identifier' => $identifier,
    ], $ctx['manager_token']), 'Story is not ready. Missing: description.');
});

it('T-15: mark_ready fails listing both missing criteria', function () {
    $ctx = dorSetup('D15');
    $story = dorStory($ctx['project'], [
        'story_points' => null,
        'description' => null,
    ]);
    $identifier = storyIdentifier($story);

    dorErr(mcpDor('mark_ready', [
        'story_identifier' => $identifier,
    ], $ctx['manager_token']), 'Story is not ready. Missing: story_points, description.');
});

it('T-16: mark_ready fails when description is only whitespace', function () {
    $ctx = dorSetup('D16');
    $story = dorStory($ctx['project'], [
        'story_points' => 5,
        'description' => "   \n\t",
    ]);
    $identifier = storyIdentifier($story);

    dorErr(mcpDor('mark_ready', [
        'story_identifier' => $identifier,
    ], $ctx['manager_token']), 'Story is not ready. Missing: description.');
});

it('T-17: mark_ready is idempotent when story already ready', function () {
    $ctx = dorSetup('D17');
    $story = dorStory($ctx['project'], [
        'story_points' => 5,
        'description' => 'Ready story.',
        'ready' => true,
    ]);
    $identifier = storyIdentifier($story);

    $result1 = dorOk(mcpDor('mark_ready', ['story_identifier' => $identifier], $ctx['manager_token']));
    $result2 = dorOk(mcpDor('mark_ready', ['story_identifier' => $identifier], $ctx['manager_token']));
    $result3 = dorOk(mcpDor('mark_ready', ['story_identifier' => $identifier], $ctx['manager_token']));

    expect($result1['ready'])->toBe(true);
    expect($result2['ready'])->toBe(true);
    expect($result3['ready'])->toBe(true);
    expect($story->fresh()->ready)->toBe(true);
});

it('T-18: mark_ready is allowed on a closed story that satisfies DoR', function () {
    $ctx = dorSetup('D18');
    $story = dorStory($ctx['project'], [
        'story_points' => 3,
        'description' => 'Closed but valid.',
        'statut' => 'closed',
    ]);
    $identifier = storyIdentifier($story);

    $result = dorOk(mcpDor('mark_ready', [
        'story_identifier' => $identifier,
    ], $ctx['manager_token']));

    expect($result['ready'])->toBe(true);
});

// ============================================================
// mark_unready
// ============================================================

it('T-19: mark_unready sets ready=false', function () {
    $ctx = dorSetup('D19');
    $story = dorStory($ctx['project'], [
        'story_points' => 5,
        'description' => 'Ready.',
        'ready' => true,
    ]);
    $identifier = storyIdentifier($story);

    $result = dorOk(mcpDor('mark_unready', [
        'story_identifier' => $identifier,
    ], $ctx['manager_token']));

    expect($result['ready'])->toBe(false);
    expect($story->fresh()->ready)->toBe(false);
});

it('T-20: mark_unready is idempotent when story already not ready', function () {
    $ctx = dorSetup('D20');
    $story = dorStory($ctx['project'], ['ready' => false]);
    $identifier = storyIdentifier($story);

    $result1 = dorOk(mcpDor('mark_unready', ['story_identifier' => $identifier], $ctx['manager_token']));
    $result2 = dorOk(mcpDor('mark_unready', ['story_identifier' => $identifier], $ctx['manager_token']));

    expect($result1['ready'])->toBe(false);
    expect($result2['ready'])->toBe(false);
});

it('T-21: mark_unready succeeds even without story_points or description', function () {
    $ctx = dorSetup('D21');
    $story = dorStory($ctx['project'], [
        'story_points' => null,
        'description' => null,
        'ready' => false,
    ]);
    $identifier = storyIdentifier($story);

    $result = dorOk(mcpDor('mark_unready', [
        'story_identifier' => $identifier,
    ], $ctx['manager_token']));

    expect($result['ready'])->toBe(false);
});

// ============================================================
// Transverse tests
// ============================================================

it('T-22: tools/list without scrum module does not expose the 3 new tools', function () {
    $tenant = createTenant();
    app(TenantManager::class)->setTenant($tenant);

    $user = User::factory()->manager()->create(['tenant_id' => $tenant->id]);
    $raw = ApiToken::generateRaw();
    $user->apiTokens()->create([
        'name' => 'tok',
        'token' => $raw['hash'],
        'tenant_id' => $tenant->id,
    ]);

    $project = Project::factory()->create([
        'code' => 'D22',
        'tenant_id' => $tenant->id,
        'modules' => [],
    ]);
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'position' => 'owner']);

    $response = test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/list',
        'params' => ['project_code' => $project->code],
    ], ['Authorization' => 'Bearer '.$raw['raw']]);

    $response->assertOk();
    $names = array_column($response->json('result.tools'), 'name');

    expect($names)->not->toContain('estimate_story');
    expect($names)->not->toContain('mark_ready');
    expect($names)->not->toContain('mark_unready');
});

it('T-23: estimate_story returns Story not found when user is not a member of the story project', function () {
    $ctx = dorSetup('D23');

    // Create a story in a project where the manager is NOT a member
    $tenant = $ctx['tenant'];
    app(TenantManager::class)->setTenant($tenant);

    $otherProject = Project::factory()->create([
        'code' => 'D23X',
        'tenant_id' => $tenant->id,
        'modules' => ['scrum'],
    ]);

    $otherStory = dorStory($otherProject);
    $identifier = storyIdentifier($otherStory);

    dorErr(mcpDor('estimate_story', [
        'story_identifier' => $identifier,
        'story_points' => 5,
    ], $ctx['manager_token']), 'Story not found in this project');
});

it('T-24: after mark_ready list_backlog returns ready as boolean true not integer 1', function () {
    $ctx = dorSetup('D24');
    $story = dorStory($ctx['project'], [
        'story_points' => 5,
        'description' => 'Serialization check.',
    ]);
    $identifier = storyIdentifier($story);

    dorOk(mcpDor('mark_ready', ['story_identifier' => $identifier], $ctx['manager_token']));

    $listResult = dorOk(mcpDor('list_backlog', [
        'project_code' => $ctx['project']->code,
    ], $ctx['manager_token']));

    $found = collect($listResult['data'])->firstWhere('identifier', $identifier);
    expect($found)->not->toBeNull();
    expect($found['ready'])->toBe(true);
    expect($found['ready'])->not->toBe(1);
    expect($found['ready'])->not->toBe('1');
});
