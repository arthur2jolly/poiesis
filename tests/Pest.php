<?php

use App\Core\Models\ApiToken;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Tenant;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Create an active tenant.
 */
function createTenant(array $attrs = []): Tenant
{
    return Tenant::factory()->create($attrs);
}

/**
 * Create an authenticated user with a Bearer token, scoped to a tenant.
 *
 * @return array{user: User, token: string, tenant: Tenant}
 */
function createAuth(?Tenant $tenant = null): array
{
    $tenant ??= createTenant();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $raw = ApiToken::generateRaw();
    $user->apiTokens()->create([
        'name' => 'test',
        'token' => $raw['hash'],
        'tenant_id' => $tenant->id,
    ]);

    return ['user' => $user, 'token' => $raw['raw'], 'tenant' => $tenant];
}

/**
 * Build Authorization header.
 */
function authHeader(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/**
 * Create a project owned by the given auth user, scoped to the auth tenant.
 */
function setupProject(array $auth, array $attrs = []): Project
{
    $project = Project::factory()->create(array_merge(
        ['tenant_id' => $auth['tenant']->id],
        $attrs
    ));
    ProjectMember::create([
        'project_id' => $project->id,
        'user_id' => $auth['user']->id,
        'position' => 'owner',
    ]);

    return $project;
}
