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
use App\Modules\Scrum\Models\Sprint;
use App\Modules\Scrum\Models\SprintItem;
use Illuminate\Testing\TestResponse;

// ============================================================
// Setup helpers
// ============================================================

/**
 * @return array{tenant: Tenant, project: Project, manager_token: string, viewer_token: string}
 */
function backlogSetup(string $code = 'BLG'): array
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

function backlogEpic(Project $project): Epic
{
    return Epic::factory()->create(['project_id' => $project->id]);
}

function backlogStory(Project $project, array $overrides = []): Story
{
    $epic = $overrides['epic_id'] ?? null
        ? null
        : backlogEpic($project);

    $base = $epic ? ['epic_id' => $epic->id] : [];

    return Story::factory()->create(array_merge($base, $overrides));
}

function backlogSprint(Project $project, string $status = 'planned'): Sprint
{
    return Sprint::create([
        'tenant_id' => $project->tenant_id,
        'project_id' => $project->id,
        'name' => 'Sprint BLG',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-15',
        'status' => $status,
    ]);
}

function attachToSprint(Sprint $sprint, Story $story): void
{
    $artifactRow = Artifact::where('artifactable_id', $story->id)
        ->where('artifactable_type', Story::class)
        ->firstOrFail();

    SprintItem::create([
        'sprint_id' => $sprint->id,
        'artifact_id' => $artifactRow->id,
        'position' => 0,
    ]);
}

function mcpBacklog(string $tool, array $args, string $token): TestResponse
{
    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $args],
    ], ['Authorization' => 'Bearer '.$token]);
}

function backlogOk(TestResponse $response): mixed
{
    $response->assertOk();
    $data = $response->json();
    expect($data)->not->toHaveKey('error');

    return json_decode($data['result']['content'][0]['text'], true);
}

function backlogErr(TestResponse $response, string $contains): void
{
    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveKey('error');
    expect($data['error']['message'])->toContain($contains);
}

// ============================================================
// T-01 — list_backlog happy path
// ============================================================

it('T-01: list_backlog returns stories in rank order', function () {
    $ctx = backlogSetup('BL01');
    $epic = backlogEpic($ctx['project']);

    Story::factory()->count(5)->create([
        'epic_id' => $epic->id,
        'statut' => 'open',
    ]);

    $result = backlogOk(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
    ], $ctx['manager_token']));

    expect($result['data'])->toHaveCount(5);
    expect($result['meta']['total'])->toBe(5);
    expect($result['data'][0])->toHaveKeys(['identifier', 'titre', 'statut', 'priorite', 'rank', 'epic_identifier']);
});

it('T-01b: list_backlog excludes closed stories from the Scrum backlog', function () {
    $ctx = backlogSetup('BL01B');
    $epic = backlogEpic($ctx['project']);

    Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);
    $closed = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'closed']);

    $result = backlogOk(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
    ], $ctx['manager_token']));

    expect($result['meta']['total'])->toBe(1);
    expect(array_column($result['data'], 'identifier'))->not->toContain($closed->identifier);
});

// ============================================================
// T-02 — reorder_backlog happy path
// ============================================================

it('T-02: reorder_backlog rewrites ranks 0, 1, 2', function () {
    $ctx = backlogSetup('BL02');
    $epic = backlogEpic($ctx['project']);

    $s1 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);
    $s2 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);
    $s3 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'draft']);

    $ordered = [$s3->identifier, $s1->identifier, $s2->identifier];

    $result = backlogOk(mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => $ordered,
    ], $ctx['manager_token']));

    expect($result['message'])->toBe('Backlog reordered.');
    expect($result['count'])->toBe(3);
    expect($result['data'])->toHaveCount(3);

    expect($s3->fresh()->rank)->toBe(0);
    expect($s1->fresh()->rank)->toBe(1);
    expect($s2->fresh()->rank)->toBe(2);
});

// ============================================================
// T-03 — reorder_backlog sans rôle CRUD
// ============================================================

it('T-03: viewer cannot reorder backlog', function () {
    $ctx = backlogSetup('BL03');

    backlogErr(mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => [],
    ], $ctx['viewer_token']), 'You do not have permission to manage sprints.');
});

// ============================================================
// T-04 — module non activé
// ============================================================

it('T-04: backlog tools absent from tools/list when scrum module is not active', function () {
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
        'code' => 'NOSCR',
        'tenant_id' => $tenant->id,
        'modules' => [],
    ]);
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'position' => 'owner']);

    $response = test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/list',
        'params' => ['project_code' => 'NOSCR'],
    ], ['Authorization' => 'Bearer '.$raw['raw']]);

    $response->assertOk();
    $names = array_column($response->json('result.tools'), 'name');
    expect($names)->not->toContain('list_backlog');
    expect($names)->not->toContain('reorder_backlog');
});

it('T-04b: tools/call returns Unknown tool when scrum module is not active', function () {
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
        'code' => 'NOSCR2',
        'tenant_id' => $tenant->id,
        'modules' => [],
    ]);
    ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'position' => 'owner']);

    $response = test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => '1',
        'method' => 'tools/call',
        'params' => ['name' => 'list_backlog', 'arguments' => ['project_code' => 'NOSCR2']],
    ], ['Authorization' => 'Bearer '.$raw['raw']]);

    $response->assertOk();
    $msg = $response->json('error.message');
    expect($msg)->toMatch('/Unknown tool|is not active/');
});

// ============================================================
// T-05 — utilisateur non-membre
// ============================================================

it('T-05: non-member gets Access denied on list_backlog', function () {
    $ctx = backlogSetup('BL05');

    $outsider = User::factory()->manager()->create(['tenant_id' => $ctx['tenant']->id]);
    $outsiderRaw = ApiToken::generateRaw();
    $outsider->apiTokens()->create([
        'name' => 'outsider',
        'token' => $outsiderRaw['hash'],
        'tenant_id' => $ctx['tenant']->id,
    ]);

    backlogErr(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
    ], $outsiderRaw['raw']), 'Access denied.');
});

it('T-05b: non-member gets Access denied on reorder_backlog', function () {
    $ctx = backlogSetup('BL05B');

    $outsider = User::factory()->manager()->create(['tenant_id' => $ctx['tenant']->id]);
    $outsiderRaw = ApiToken::generateRaw();
    $outsider->apiTokens()->create([
        'name' => 'outsider',
        'token' => $outsiderRaw['hash'],
        'tenant_id' => $ctx['tenant']->id,
    ]);

    backlogErr(mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => [],
    ], $outsiderRaw['raw']), 'Access denied.');
});

// ============================================================
// T-06 — tri: rank=0, rank=2, rank=NULL ancienne, rank=NULL récente
// ============================================================

it('T-06: backlog sort order is rank ASC NULLS LAST then created_at ASC', function () {
    $ctx = backlogSetup('BL06');
    $epic = backlogEpic($ctx['project']);

    $sOld = Story::factory()->create(['epic_id' => $epic->id, 'rank' => null, 'created_at' => now()->subDays(2)]);
    $sNew = Story::factory()->create(['epic_id' => $epic->id, 'rank' => null, 'created_at' => now()->subDays(1)]);
    $s0 = Story::factory()->create(['epic_id' => $epic->id, 'rank' => 0]);
    $s2 = Story::factory()->create(['epic_id' => $epic->id, 'rank' => 2]);

    $result = backlogOk(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
    ], $ctx['manager_token']));

    $identifiers = array_column($result['data'], 'identifier');
    expect($identifiers[0])->toBe($s0->identifier);
    expect($identifiers[1])->toBe($s2->identifier);
    expect($identifiers[2])->toBe($sOld->identifier);
    expect($identifiers[3])->toBe($sNew->identifier);
});

// ============================================================
// T-07 — filtre status=open
// ============================================================

it('T-07: status=open filter excludes draft and closed stories', function () {
    $ctx = backlogSetup('BL07');
    $epic = backlogEpic($ctx['project']);

    Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);
    Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'draft']);
    Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'closed']);

    $result = backlogOk(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
        'status' => 'open',
    ], $ctx['manager_token']));

    expect($result['meta']['total'])->toBe(1);
    expect($result['data'][0]['statut'])->toBe('open');
});

it('T-07b: status=closed is not a valid Scrum backlog filter', function () {
    $ctx = backlogSetup('BL07B');

    backlogErr(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
        'status' => 'closed',
    ], $ctx['manager_token']), 'Invalid story status.');
});

// ============================================================
// T-08 — filtre priority=haute
// ============================================================

it('T-08: priority filter returns only matching stories', function () {
    $ctx = backlogSetup('BL08');
    $epic = backlogEpic($ctx['project']);

    Story::factory()->create(['epic_id' => $epic->id, 'priorite' => 'haute']);
    Story::factory()->create(['epic_id' => $epic->id, 'priorite' => 'basse']);
    Story::factory()->create(['epic_id' => $epic->id, 'priorite' => 'haute']);

    $result = backlogOk(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
        'priority' => 'haute',
    ], $ctx['manager_token']));

    expect($result['meta']['total'])->toBe(2);
    foreach ($result['data'] as $item) {
        expect($item['priorite'])->toBe('haute');
    }
});

// ============================================================
// T-09 — filtre tags AND strict
// ============================================================

it('T-09: tags filter is AND strict', function () {
    $ctx = backlogSetup('BL09');
    $epic = backlogEpic($ctx['project']);

    Story::factory()->create(['epic_id' => $epic->id, 'tags' => ['frontend', 'urgent']]);
    Story::factory()->create(['epic_id' => $epic->id, 'tags' => ['frontend']]);
    Story::factory()->create(['epic_id' => $epic->id, 'tags' => ['urgent']]);

    $result = backlogOk(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
        'tags' => ['frontend', 'urgent'],
    ], $ctx['manager_token']));

    expect($result['meta']['total'])->toBe(1);
    expect($result['data'][0]['tags'])->toContain('frontend');
    expect($result['data'][0]['tags'])->toContain('urgent');
});

// ============================================================
// T-10 — filtre epic_identifier
// ============================================================

it('T-10: epic_identifier filter excludes stories from other epics', function () {
    $ctx = backlogSetup('BL10');
    $epic1 = backlogEpic($ctx['project']);
    $epic2 = backlogEpic($ctx['project']);

    Story::factory()->create(['epic_id' => $epic1->id]);
    Story::factory()->create(['epic_id' => $epic1->id]);
    Story::factory()->create(['epic_id' => $epic2->id]);

    $result = backlogOk(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
        'epic_identifier' => $epic1->identifier,
    ], $ctx['manager_token']));

    expect($result['meta']['total'])->toBe(2);
    foreach ($result['data'] as $item) {
        expect($item['epic_identifier'])->toBe($epic1->identifier);
    }
});

// ============================================================
// T-11 — filtre epic_identifier cross-projet
// ============================================================

it('T-11: epic_identifier from another project returns ValidationException', function () {
    $ctx = backlogSetup('BL11');

    $otherProject = Project::factory()->create([
        'code' => 'OTHER',
        'tenant_id' => $ctx['tenant']->id,
        'modules' => ['scrum'],
    ]);
    $otherEpic = Epic::factory()->create(['project_id' => $otherProject->id]);

    backlogErr(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
        'epic_identifier' => $otherEpic->identifier,
    ], $ctx['manager_token']), 'Epic not found in this project.');
});

// ============================================================
// T-12 — filtre epic_identifier malformé
// ============================================================

it('T-12: malformed epic_identifier returns Invalid epic identifier format', function () {
    $ctx = backlogSetup('BL12');

    backlogErr(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
        'epic_identifier' => 'not-valid',
    ], $ctx['manager_token']), 'Invalid epic identifier format.');
});

// ============================================================
// T-13 — filtre in_sprint=true
// ============================================================

it('T-13: in_sprint=true returns only stories in planned or active sprint', function () {
    $ctx = backlogSetup('BL13');
    $epic = backlogEpic($ctx['project']);

    $s1 = Story::factory()->create(['epic_id' => $epic->id]);
    $s2 = Story::factory()->create(['epic_id' => $epic->id]);
    $s3 = Story::factory()->create(['epic_id' => $epic->id]);
    $s4 = Story::factory()->create(['epic_id' => $epic->id]);

    $activeSpint = backlogSprint($ctx['project'], 'active');
    $plannedSprint = backlogSprint($ctx['project'], 'planned');
    $completedSprint = backlogSprint($ctx['project'], 'completed');

    attachToSprint($activeSpint, $s1);
    attachToSprint($plannedSprint, $s2);
    attachToSprint($completedSprint, $s3);
    // s4 not in any sprint

    $result = backlogOk(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
        'in_sprint' => true,
    ], $ctx['manager_token']));

    expect($result['meta']['total'])->toBe(2);
    $ids = array_column($result['data'], 'identifier');
    expect($ids)->toContain($s1->identifier);
    expect($ids)->toContain($s2->identifier);
    expect($ids)->not->toContain($s3->identifier);
    expect($ids)->not->toContain($s4->identifier);
});

// ============================================================
// T-14 — filtre in_sprint=false
// ============================================================

it('T-14: in_sprint=false returns stories not in planned/active sprint', function () {
    $ctx = backlogSetup('BL14');
    $epic = backlogEpic($ctx['project']);

    $s1 = Story::factory()->create(['epic_id' => $epic->id]);
    $s2 = Story::factory()->create(['epic_id' => $epic->id]);
    $s3 = Story::factory()->create(['epic_id' => $epic->id]);
    $s4 = Story::factory()->create(['epic_id' => $epic->id]);

    $activeSprint = backlogSprint($ctx['project'], 'active');
    $plannedSprint = backlogSprint($ctx['project'], 'planned');
    $completedSprint = backlogSprint($ctx['project'], 'completed');

    attachToSprint($activeSprint, $s1);
    attachToSprint($plannedSprint, $s2);
    attachToSprint($completedSprint, $s3);
    // s4 not in any sprint

    $result = backlogOk(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
        'in_sprint' => false,
    ], $ctx['manager_token']));

    expect($result['meta']['total'])->toBe(2);
    $ids = array_column($result['data'], 'identifier');
    expect($ids)->toContain($s3->identifier);
    expect($ids)->toContain($s4->identifier);
    expect($ids)->not->toContain($s1->identifier);
    expect($ids)->not->toContain($s2->identifier);
});

// ============================================================
// T-15 — in_sprint absent = tous
// ============================================================

it('T-15: in_sprint absent returns all 4 stories', function () {
    $ctx = backlogSetup('BL15');
    $epic = backlogEpic($ctx['project']);

    $s1 = Story::factory()->create(['epic_id' => $epic->id]);
    $s2 = Story::factory()->create(['epic_id' => $epic->id]);
    $s3 = Story::factory()->create(['epic_id' => $epic->id]);
    $s4 = Story::factory()->create(['epic_id' => $epic->id]);

    $sprint = backlogSprint($ctx['project'], 'active');
    attachToSprint($sprint, $s1);

    $result = backlogOk(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
    ], $ctx['manager_token']));

    expect($result['meta']['total'])->toBe(4);
});

// ============================================================
// T-16 — status invalide
// ============================================================

it('T-16: invalid status returns Invalid story status', function () {
    $ctx = backlogSetup('BL16');

    backlogErr(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
        'status' => 'invalid_status',
    ], $ctx['manager_token']), 'Invalid story status.');
});

// ============================================================
// T-17 — priority invalide
// ============================================================

it('T-17: invalid priority returns Invalid story priority', function () {
    $ctx = backlogSetup('BL17');

    backlogErr(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
        'priority' => 'super_high',
    ], $ctx['manager_token']), 'Invalid story priority.');
});

// ============================================================
// T-18 — per_page clampé à 100
// ============================================================

it('T-18: per_page=500 is clamped to 100', function () {
    $ctx = backlogSetup('BL18');
    $epic = backlogEpic($ctx['project']);
    Story::factory()->count(3)->create(['epic_id' => $epic->id]);

    $result = backlogOk(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
        'per_page' => 500,
    ], $ctx['manager_token']));

    expect($result['meta']['per_page'])->toBe(100);
});

// ============================================================
// T-19 — projet sans story (CL-01)
// ============================================================

it('T-19: empty project returns data=[] meta.total=0', function () {
    $ctx = backlogSetup('BL19');

    $result = backlogOk(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
    ], $ctx['manager_token']));

    expect($result['data'])->toBe([]);
    expect($result['meta']['total'])->toBe(0);
});

// ============================================================
// T-20 — tags=[] équivalent absent (CL-06)
// ============================================================

it('T-20: tags=[] applies no filter', function () {
    $ctx = backlogSetup('BL20');
    $epic = backlogEpic($ctx['project']);
    Story::factory()->count(3)->create(['epic_id' => $epic->id]);

    $result = backlogOk(mcpBacklog('list_backlog', [
        'project_code' => $ctx['project']->code,
        'tags' => [],
    ], $ctx['manager_token']));

    expect($result['meta']['total'])->toBe(3);
});

// ============================================================
// T-21 — couverture incomplète
// ============================================================

it('T-21: incomplete coverage returns Reorder coverage mismatch with missing identifier', function () {
    $ctx = backlogSetup('BL21');
    $epic = backlogEpic($ctx['project']);

    $s1 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);
    $s2 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);
    $s3 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);

    $response = mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => [$s1->identifier, $s2->identifier],
    ], $ctx['manager_token']);

    backlogErr($response, 'Reorder coverage mismatch.');
    expect($response->json('error.message'))->toContain($s3->identifier);
});

// ============================================================
// T-22 — story closed envoyée = unexpected
// ============================================================

it('T-22: sending a closed story is rejected as unexpected in coverage', function () {
    $ctx = backlogSetup('BL22');
    $epic = backlogEpic($ctx['project']);

    $s1 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);
    $s2 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);
    $sClosed = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'closed']);

    $response = mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => [$s1->identifier, $s2->identifier, $sClosed->identifier],
    ], $ctx['manager_token']);

    backlogErr($response, 'Reorder coverage mismatch.');
    expect($response->json('error.message'))->toContain($sClosed->identifier);
});

// ============================================================
// T-23 — identifier d'un autre projet
// ============================================================

it('T-23: identifier from another project is rejected', function () {
    $ctx = backlogSetup('BL23');
    $epic = backlogEpic($ctx['project']);

    $s1 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);

    $otherProject = Project::factory()->create([
        'code' => 'OTHBL',
        'tenant_id' => $ctx['tenant']->id,
        'modules' => ['scrum'],
    ]);
    $otherEpic = Epic::factory()->create(['project_id' => $otherProject->id]);
    $sOther = Story::factory()->create(['epic_id' => $otherEpic->id, 'statut' => 'open']);

    $response = mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => [$s1->identifier, $sOther->identifier],
    ], $ctx['manager_token']);

    backlogErr($response, "does not belong to project '{$ctx['project']->code}'");
});

// ============================================================
// T-24 — identifier malformé
// ============================================================

it('T-24: malformed identifier is rejected with does not belong message', function () {
    $ctx = backlogSetup('BL24');
    $epic = backlogEpic($ctx['project']);
    $s1 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);

    $response = mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => [$s1->identifier, 'not-valid'],
    ], $ctx['manager_token']);

    backlogErr($response, "does not belong to project '{$ctx['project']->code}'");
});

// ============================================================
// T-25 — doublon dans la liste
// ============================================================

it('T-25: duplicate identifier in ordered_identifiers is rejected', function () {
    $ctx = backlogSetup('BL25');
    $epic = backlogEpic($ctx['project']);
    $s1 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);

    backlogErr(mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => [$s1->identifier, $s1->identifier],
    ], $ctx['manager_token']), "Duplicate identifier in ordered_identifiers: '{$s1->identifier}'");
});

// ============================================================
// T-26 — liste vide
// ============================================================

it('T-26: empty ordered_identifiers is rejected', function () {
    $ctx = backlogSetup('BL26');

    backlogErr(mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => [],
    ], $ctx['manager_token']), 'ordered_identifiers cannot be empty.');
});

// ============================================================
// T-27 — identifier inexistant
// ============================================================

it('T-27: non-existent identifier returns Story not found', function () {
    $ctx = backlogSetup('BL27');
    $epic = backlogEpic($ctx['project']);
    $s1 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);

    // We need to set the identifer to match project code but not exist
    // First get the actual story count to craft a non-existing one
    $fakeId = $ctx['project']->code.'-99999';

    $response = mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => [$s1->identifier, $fakeId],
    ], $ctx['manager_token']);

    backlogErr($response, "Story '{$fakeId}' not found in this project.");
});

// ============================================================
// T-28 — closed exclue du périmètre, reorder des ouvertes = succès
// ============================================================

it('T-28: closed story excluded from reorder coverage, only open ones required', function () {
    $ctx = backlogSetup('BL28');
    $epic = backlogEpic($ctx['project']);

    $s1 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open', 'rank' => 5]);
    $s2 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open', 'rank' => 10]);
    $sClosed = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'closed', 'rank' => 99]);

    $result = backlogOk(mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => [$s2->identifier, $s1->identifier],
    ], $ctx['manager_token']));

    expect($result['message'])->toBe('Backlog reordered.');
    expect($s2->fresh()->rank)->toBe(0);
    expect($s1->fresh()->rank)->toBe(1);
    // Closed story rank untouched
    expect($sClosed->fresh()->rank)->toBe(99);
});

// ============================================================
// T-29 — idempotence
// ============================================================

it('T-29: calling reorder twice with same sequence yields same result', function () {
    $ctx = backlogSetup('BL29');
    $epic = backlogEpic($ctx['project']);

    $s1 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);
    $s2 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);
    $s3 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);

    $ordered = [$s3->identifier, $s1->identifier, $s2->identifier];

    backlogOk(mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => $ordered,
    ], $ctx['manager_token']));

    backlogOk(mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => $ordered,
    ], $ctx['manager_token']));

    expect($s3->fresh()->rank)->toBe(0);
    expect($s1->fresh()->rank)->toBe(1);
    expect($s2->fresh()->rank)->toBe(2);
});

// ============================================================
// T-30 — réponse contient message, count, data avec ranks
// ============================================================

it('T-30: reorder response contains message, count and data with updated ranks', function () {
    $ctx = backlogSetup('BL30');
    $epic = backlogEpic($ctx['project']);

    $s1 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);
    $s2 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);

    $result = backlogOk(mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => [$s2->identifier, $s1->identifier],
    ], $ctx['manager_token']));

    expect($result)->toHaveKey('message');
    expect($result)->toHaveKey('count');
    expect($result)->toHaveKey('data');
    expect($result['message'])->toBe('Backlog reordered.');
    expect($result['count'])->toBe(2);
    expect($result['data'])->toHaveCount(2);
    expect($result['data'][0]['rank'])->toBe(0);
    expect($result['data'][1]['rank'])->toBe(1);
    expect($result['data'][0]['identifier'])->toBe($s2->identifier);
});

// ============================================================
// T-31 — reorder 1 seul identifier (CL-18)
// ============================================================

it('T-31: reorder with single identifier succeeds with rank=0', function () {
    $ctx = backlogSetup('BL31');
    $epic = backlogEpic($ctx['project']);

    $s1 = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);

    $result = backlogOk(mcpBacklog('reorder_backlog', [
        'project_code' => $ctx['project']->code,
        'ordered_identifiers' => [$s1->identifier],
    ], $ctx['manager_token']));

    expect($result['count'])->toBe(1);
    expect($s1->fresh()->rank)->toBe(0);
    expect($result['data'][0]['rank'])->toBe(0);
});
