<?php

use App\Core\Models\ApiToken;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Create an authenticated user with a Bearer token.
 *
 * @return array{user: User, token: string}
 */
function createAuth(): array
{
    $user = User::factory()->create();
    $raw = ApiToken::generateRaw();
    $user->apiTokens()->create(['name' => 'test', 'token' => $raw['hash']]);

    return ['user' => $user, 'token' => $raw['raw']];
}

/**
 * Build Authorization header.
 */
function authHeader(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/**
 * Create a project owned by the given auth user.
 */
function setupProject(array $auth, array $attrs = []): Project
{
    $project = Project::factory()->create($attrs);
    ProjectMember::create([
        'project_id' => $project->id,
        'user_id' => $auth['user']->id,
        'role' => 'owner',
    ]);

    return $project;
}
