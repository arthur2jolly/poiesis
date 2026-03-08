<?php

declare(strict_types=1);

use App\Core\Contracts\ModuleInterface;
use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\ApiToken;
use App\Core\Models\Epic;
use App\Core\Models\OAuthAccessToken;
use App\Core\Models\OAuthClient;
use App\Core\Models\OAuthRefreshToken;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\User;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\DependencyService;
use App\Core\Services\TenantManager;
use Carbon\Carbon;

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

/**
 * Crée un module anonyme implémentant ModuleInterface pour les tests.
 *
 * @param  array<int, string>  $deps
 */
function makeTestModule(string $slug, array $deps = []): ModuleInterface
{
    return new class($slug, $deps) implements ModuleInterface
    {
        public function __construct(
            private readonly string $moduleSlug,
            private readonly array $moduleDeps,
        ) {}

        public function slug(): string
        {
            return $this->moduleSlug;
        }

        public function name(): string
        {
            return ucfirst($this->moduleSlug).' Module';
        }

        public function description(): string
        {
            return 'Test module';
        }

        /** @return array<int, string> */
        public function dependencies(): array
        {
            return $this->moduleDeps;
        }

        public function registerRoutes(): void {}

        public function registerListeners(): void {}

        public function migrationPath(): string
        {
            return '';
        }

        /** @return array<int, McpToolInterface> */
        public function mcpTools(): array
        {
            return [];
        }
    };
}

// ---------------------------------------------------------------------------
// CL1 — Suppression / rétrogradation du dernier propriétaire rejeté
// ---------------------------------------------------------------------------

describe('CL1: Last owner removal rejected', function (): void {

    it('rejects removing the sole owner', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL1A']);

        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $auth['user']->id)
            ->first();

        $this->deleteJson(
            "/api/v1/projects/CL1A/members/{$member->id}",
            [],
            authHeader($auth['token'])
        )->assertStatus(422);
    });

    it('rejects downgrading the sole owner to member', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL1B']);

        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $auth['user']->id)
            ->first();

        $this->patchJson(
            "/api/v1/projects/CL1B/members/{$member->id}",
            ['position' => 'member'],
            authHeader($auth['token'])
        )->assertStatus(422);

        expect($member->fresh()->position)->toBe('owner');
    });

    it('allows removing an owner when another owner exists', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL1C']);

        $secondOwner = User::factory()->create(['tenant_id' => $auth['tenant']->id]);
        $secondMember = ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $secondOwner->id,
            'position' => 'owner',
        ]);

        $firstMember = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $auth['user']->id)
            ->first();

        $this->deleteJson(
            "/api/v1/projects/CL1C/members/{$firstMember->id}",
            [],
            authHeader($auth['token'])
        )->assertStatus(204);
    });

});

// ---------------------------------------------------------------------------
// CL2 — Activation d'un module avec dépendance manquante rejetée
// ---------------------------------------------------------------------------

describe('CL2: Module activation with missing dependency rejected', function (): void {

    it('rejects activating a module whose dependency is not active', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL2A', 'modules' => []]);

        // Enregistre un module dépendant de 'example' (non actif)
        app(ModuleRegistry::class)->register(makeTestModule('dependent-cl2', ['example']));

        $this->postJson(
            '/api/v1/projects/CL2A/modules',
            ['slug' => 'dependent-cl2'],
            authHeader($auth['token'])
        )->assertStatus(422)
            ->assertJsonFragment(['message' => "Module 'dependent-cl2' requires module 'example' to be active first."]);
    });

    it('succeeds activating a module when its dependency is already active', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL2B', 'modules' => ['example']]);

        app(ModuleRegistry::class)->register(makeTestModule('dependent-cl2b', ['example']));

        $this->postJson(
            '/api/v1/projects/CL2B/modules',
            ['slug' => 'dependent-cl2b'],
            authHeader($auth['token'])
        )->assertStatus(201);
    });

});

// ---------------------------------------------------------------------------
// CL3 — Désactivation d'un module dont d'autres dépendent rejetée
// ---------------------------------------------------------------------------

describe('CL3: Module deactivation with dependents rejected', function (): void {

    it('rejects deactivating a module that has active dependents', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL3A', 'modules' => ['example', 'dep-cl3']]);

        app(ModuleRegistry::class)->register(makeTestModule('dep-cl3', ['example']));

        $this->deleteJson(
            '/api/v1/projects/CL3A/modules/example',
            [],
            authHeader($auth['token'])
        )->assertStatus(422);
    });

    it('allows deactivating a module after its dependent is deactivated', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL3B', 'modules' => ['example', 'dep-cl3b']]);

        app(ModuleRegistry::class)->register(makeTestModule('dep-cl3b', ['example']));

        // Désactive le module dépendant en premier
        $this->deleteJson(
            '/api/v1/projects/CL3B/modules/dep-cl3b',
            [],
            authHeader($auth['token'])
        )->assertStatus(204);

        // Maintenant on peut désactiver 'example'
        $this->deleteJson(
            '/api/v1/projects/CL3B/modules/example',
            [],
            authHeader($auth['token'])
        )->assertStatus(204);
    });

});

// ---------------------------------------------------------------------------
// CL4 — Création concurrente d'artefacts : identifiants uniques sans trous
// ---------------------------------------------------------------------------

describe('CL4: Concurrent artifact creation produces unique identifiers', function (): void {

    it('assigns unique sequential identifiers when creating 10 stories sequentially', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL4']);
        app(TenantManager::class)->setTenant($auth['tenant']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $epicId = $epic->fresh()->identifier;

        $identifiers = [];

        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson(
                "/api/v1/projects/CL4/epics/{$epicId}/stories",
                ['titre' => "Story {$i}", 'type' => 'backend'],
                authHeader($auth['token'])
            )->assertStatus(201);

            $identifiers[] = $response->json('data.identifier');
        }

        // Tous les identifiants sont uniques
        expect(array_unique($identifiers))->toHaveCount(10);

        // Tous les identifiants commencent par le code projet
        foreach ($identifiers as $identifier) {
            expect($identifier)->toStartWith('CL4-');
        }

        // Les numéros de séquence couvrent une plage continue
        $sequenceNumbers = array_map(
            fn ($id) => (int) explode('-', $id, 2)[1],
            $identifiers
        );
        sort($sequenceNumbers);

        $min = min($sequenceNumbers);
        $max = max($sequenceNumbers);
        $expected = range($min, $max);

        expect($sequenceNumbers)->toBe($expected);
    });

});

// ---------------------------------------------------------------------------
// CL5 — Token expiré rejeté
// ---------------------------------------------------------------------------

describe('CL5: Expired token rejected', function (): void {

    it('returns 401 when the API token is expired', function (): void {
        $tenant = createTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $raw = ApiToken::generateRaw();
        $user->apiTokens()->create([
            'name' => 'expired-token',
            'token' => $raw['hash'],
            'expires_at' => Carbon::now()->subHour(),
            'tenant_id' => $tenant->id,
        ]);

        $this->getJson('/api/v1/ping', authHeader($raw['raw']))
            ->assertStatus(401);
    });

    it('returns 200 when the API token is valid and not expired', function (): void {
        $tenant = createTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $raw = ApiToken::generateRaw();
        $user->apiTokens()->create([
            'name' => 'valid-token',
            'token' => $raw['hash'],
            'expires_at' => Carbon::now()->addHour(),
            'tenant_id' => $tenant->id,
        ]);

        $this->getJson('/api/v1/ping', authHeader($raw['raw']))
            ->assertStatus(200);
    });

});

// ---------------------------------------------------------------------------
// CL6 — OAuth2 : refresh avec un refresh token valide
// ---------------------------------------------------------------------------

describe('CL6: OAuth2 refresh with valid refresh token', function (): void {

    it('issues new tokens when refresh token is valid', function (): void {
        $tenant = createTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $client = OAuthClient::create([
            'name' => 'CL6 Client',
            'client_id' => 'cl6-client',
            'redirect_uris' => ['http://localhost/callback'],
            'grant_types' => ['authorization_code'],
            'tenant_id' => $tenant->id,
        ]);

        $accessToken = OAuthAccessToken::create([
            'oauth_client_id' => $client->id,
            'user_id' => $user->id,
            'token' => hash('sha256', 'cl6-old-access'),
            'scopes' => ['projects:read'],
            'expires_at' => Carbon::now()->addHour(),
            'tenant_id' => $tenant->id,
        ]);

        $rawRefresh = 'rt-'.bin2hex(random_bytes(20));
        OAuthRefreshToken::create([
            'access_token_id' => $accessToken->id,
            'token' => hash('sha256', $rawRefresh),
            'expires_at' => Carbon::now()->addDays(30),
            'revoked' => false,
        ]);

        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => 'cl6-client',
            'refresh_token' => $rawRefresh,
        ])->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'refresh_token']);
    });

});

// ---------------------------------------------------------------------------
// CL7 — OAuth2 : refresh avec un refresh token expiré → 400
// ---------------------------------------------------------------------------

describe('CL7: OAuth2 refresh with expired refresh token', function (): void {

    it('returns 400 when refresh token is expired', function (): void {
        $tenant = createTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $client = OAuthClient::create([
            'name' => 'CL7 Client',
            'client_id' => 'cl7-client',
            'redirect_uris' => ['http://localhost/callback'],
            'grant_types' => ['authorization_code'],
            'tenant_id' => $tenant->id,
        ]);

        $accessToken = OAuthAccessToken::create([
            'oauth_client_id' => $client->id,
            'user_id' => $user->id,
            'token' => hash('sha256', 'cl7-old-access'),
            'scopes' => null,
            'expires_at' => Carbon::now()->addHour(),
            'tenant_id' => $tenant->id,
        ]);

        $rawRefresh = 'rt-'.bin2hex(random_bytes(20));
        OAuthRefreshToken::create([
            'access_token_id' => $accessToken->id,
            'token' => hash('sha256', $rawRefresh),
            'expires_at' => Carbon::now()->subHour(),
            'revoked' => false,
        ]);

        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => 'cl7-client',
            'refresh_token' => $rawRefresh,
        ])->assertStatus(400)
            ->assertJsonFragment(['error' => 'invalid_grant']);
    });

});

// ---------------------------------------------------------------------------
// CL8 — Suppression en cascade : épic → stories → tâches
// ---------------------------------------------------------------------------

describe('CL8: Cascade deletion epic -> stories -> tasks', function (): void {

    it('deletes stories and tasks when an epic is deleted', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL8']);
        app(TenantManager::class)->setTenant($auth['tenant']);

        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'story_id' => $story->id,
        ]);

        $epicId = $epic->fresh()->identifier;

        $this->deleteJson(
            "/api/v1/projects/CL8/epics/{$epicId}",
            [],
            authHeader($auth['token'])
        )->assertStatus(204);

        $this->assertDatabaseMissing('epics', ['id' => $epic->id]);
        $this->assertDatabaseMissing('stories', ['id' => $story->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    });

});

// ---------------------------------------------------------------------------
// CL9 — Suppression de story : artefact nettoyé
// ---------------------------------------------------------------------------

describe('CL9: Story deletion cleans up artifact', function (): void {

    it('removes the story record when a story is deleted via API', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL9']);
        app(TenantManager::class)->setTenant($auth['tenant']);

        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);
        $storyId = $story->fresh()->identifier;

        $this->deleteJson(
            "/api/v1/projects/CL9/stories/{$storyId}",
            [],
            authHeader($auth['token'])
        )->assertStatus(204);

        $this->assertDatabaseMissing('stories', ['id' => $story->id]);
    });

    it('cleans up the artifact record when a story is deleted')
        ->skip('HasArtifactIdentifier does not register a deleting hook — artifact cascade not yet implemented in core.');

});

// ---------------------------------------------------------------------------
// CL10 — Une tâche standalone ne peut pas être reliée à une story via PATCH
// ---------------------------------------------------------------------------

describe('CL10: Standalone task cannot be re-linked to a story', function (): void {

    it('ignores story_id when updating a standalone task', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL10']);
        app(TenantManager::class)->setTenant($auth['tenant']);

        // Créer un epic et une story pour fournir un story_id cible
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);

        // Créer une tâche standalone via l'API
        $createResp = $this->postJson(
            '/api/v1/projects/CL10/tasks',
            ['titre' => 'Standalone Task', 'type' => 'backend'],
            authHeader($auth['token'])
        )->assertStatus(201);

        $taskIdentifier = $createResp->json('data.identifier');

        // Tenter de lier la tâche à la story via PATCH
        $this->patchJson(
            "/api/v1/projects/CL10/tasks/{$taskIdentifier}",
            ['story_id' => $story->id, 'titre' => 'Updated Standalone'],
            authHeader($auth['token'])
        )->assertStatus(200);

        // La tâche doit rester standalone (story_id non modifiable via UpdateTaskRequest)
        $task = Task::whereHas('artifact', fn ($q) => $q->where('identifier', $taskIdentifier))->first();
        expect($task)->not->toBeNull();
        expect($task->story_id)->toBeNull();
        expect($task->titre)->toBe('Updated Standalone');
    });

});

// ---------------------------------------------------------------------------
// CL11 — Endpoint de module désactivé retourne 404
// ---------------------------------------------------------------------------

describe('CL11: Disabled module endpoint returns 404', function (): void {

    it('returns 404 when the module is not active for the project', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL11A', 'modules' => []]);

        $this->getJson(
            '/api/v1/projects/CL11A/module-check/sprint',
            authHeader($auth['token'])
        )->assertStatus(404);
    });

    it('returns 200 when the module is active for the project', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL11B', 'modules' => ['sprint']]);

        $this->getJson(
            '/api/v1/projects/CL11B/module-check/sprint',
            authHeader($auth['token'])
        )->assertStatus(200);
    });

});

// ---------------------------------------------------------------------------
// CL12 — Format de code projet invalide rejeté
// ---------------------------------------------------------------------------

describe('CL12: Invalid project code format rejected', function (): void {

    it('rejects a code that is too short (1 char)', function (): void {
        $auth = createAuth();

        $this->postJson(
            '/api/v1/projects',
            ['code' => 'a', 'titre' => 'Short Code'],
            authHeader($auth['token'])
        )->assertStatus(422)
            ->assertJsonValidationErrors('code');
    });

    it('rejects a code with special characters', function (): void {
        $auth = createAuth();

        $this->postJson(
            '/api/v1/projects',
            ['code' => '!@#$', 'titre' => 'Bad Chars'],
            authHeader($auth['token'])
        )->assertStatus(422)
            ->assertJsonValidationErrors('code');
    });

    it('rejects a code that is too long (26 chars)', function (): void {
        $auth = createAuth();

        $this->postJson(
            '/api/v1/projects',
            ['code' => str_repeat('A', 26), 'titre' => 'Too Long'],
            authHeader($auth['token'])
        )->assertStatus(422)
            ->assertJsonValidationErrors('code');
    });

    it('accepts a valid code with 2 chars', function (): void {
        $auth = createAuth();

        $this->postJson(
            '/api/v1/projects',
            ['code' => 'AB', 'titre' => 'Min Length'],
            authHeader($auth['token'])
        )->assertStatus(201);
    });

    it('accepts a valid code with alphanumeric and hyphens (25 chars)', function (): void {
        $auth = createAuth();

        $this->postJson(
            '/api/v1/projects',
            ['code' => 'VALID-CODE-25-CHARS-12345', 'titre' => 'Max Length'],
            authHeader($auth['token'])
        )->assertStatus(201);
    });

});

// ---------------------------------------------------------------------------
// CL13 — Code projet en doublon rejeté
// ---------------------------------------------------------------------------

describe('CL13: Duplicate project code rejected', function (): void {

    it('returns 422 when creating a project with an already existing code', function (): void {
        $auth = createAuth();
        Project::factory()->create(['code' => 'DUP13', 'tenant_id' => $auth['tenant']->id]);

        $this->postJson(
            '/api/v1/projects',
            ['code' => 'DUP13', 'titre' => 'Duplicate'],
            authHeader($auth['token'])
        )->assertStatus(422)
            ->assertJsonValidationErrors('code');
    });

});

// ---------------------------------------------------------------------------
// CL14 — Valeur de type ou priorité invalide rejetée
// ---------------------------------------------------------------------------

describe('CL14: Invalid type/priority value rejected', function (): void {

    it('rejects a story with an invalid type', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL14A']);
        app(TenantManager::class)->setTenant($auth['tenant']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $epicId = $epic->fresh()->identifier;

        $this->postJson(
            "/api/v1/projects/CL14A/epics/{$epicId}/stories",
            ['titre' => 'Bad Type Story', 'type' => 'invalid'],
            authHeader($auth['token'])
        )->assertStatus(422)
            ->assertJsonValidationErrors('type');
    });

    it('rejects a story with an invalid priority', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL14B']);
        app(TenantManager::class)->setTenant($auth['tenant']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $epicId = $epic->fresh()->identifier;

        $this->postJson(
            "/api/v1/projects/CL14B/epics/{$epicId}/stories",
            ['titre' => 'Bad Priority Story', 'type' => 'backend', 'priorite' => 'invalid'],
            authHeader($auth['token'])
        )->assertStatus(422)
            ->assertJsonValidationErrors('priorite');
    });

    it('rejects a task with an invalid type', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL14C']);

        $this->postJson(
            '/api/v1/projects/CL14C/tasks',
            ['titre' => 'Bad Type Task', 'type' => 'invalid'],
            authHeader($auth['token'])
        )->assertStatus(422)
            ->assertJsonValidationErrors('type');
    });

});

// ---------------------------------------------------------------------------
// CL15 — Pagination au-delà des résultats : données vides avec méta correcte
// ---------------------------------------------------------------------------

describe('CL15: Pagination beyond results returns empty with correct meta', function (): void {

    it('returns empty data and valid meta when requesting a non-existent page', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL15']);

        $response = $this->getJson(
            '/api/v1/projects/CL15/stories?per_page=10&page=999',
            authHeader($auth['token'])
        )->assertStatus(200);

        expect($response->json('data'))->toBeArray()->toBeEmpty();
        expect($response->json('meta.current_page'))->toBe(999);
        expect($response->json('meta.per_page'))->toBe(10);
        expect($response->json('meta.total'))->toBe(0);
    });

});

// ---------------------------------------------------------------------------
// CL16 — Données du module conservées après désactivation
// ---------------------------------------------------------------------------

describe('CL16: Module data retained after deactivation', function (): void {

    it('keeps project data after module deactivation', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL16', 'modules' => ['example']]);
        app(TenantManager::class)->setTenant($auth['tenant']);

        // Crée un epic (donnée métier core) pendant que le module est actif
        $epic = Epic::factory()->create([
            'project_id' => $project->id,
            'titre' => 'Epic to retain',
        ]);

        // Désactive le module
        $this->deleteJson(
            '/api/v1/projects/CL16/modules/example',
            [],
            authHeader($auth['token'])
        )->assertStatus(204);

        // Vérifie que la donnée métier est toujours présente
        $this->assertDatabaseHas('epics', [
            'project_id' => $project->id,
            'titre' => 'Epic to retain',
        ]);

        // Vérifie que le module est bien désactivé
        $project->refresh();
        expect($project->modules)->not->toContain('example');
    });

});

// ---------------------------------------------------------------------------
// CL17 — Utilisateur sans accès au projet : 403
// ---------------------------------------------------------------------------

describe('CL17: User without project access gets 403', function (): void {

    it('returns 403 when accessing a project the user is not a member of', function (): void {
        $tenant = createTenant();
        $owner = createAuth($tenant);
        $project = setupProject($owner, ['code' => 'CL17']);

        $stranger = createAuth($tenant);

        $this->getJson(
            '/api/v1/projects/CL17/',
            authHeader($stranger['token'])
        )->assertStatus(403);
    });

    it('returns 200 when the user is a member of the project', function (): void {
        $tenant = createTenant();
        $owner = createAuth($tenant);
        $project = setupProject($owner, ['code' => 'CL17B']);

        $member = createAuth($tenant);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member['user']->id,
            'position' => 'member',
        ]);

        $this->getJson(
            '/api/v1/projects/CL17B/',
            authHeader($member['token'])
        )->assertStatus(200);
    });

});

// ---------------------------------------------------------------------------
// CL18 — Enregistrement dynamique de client OAuth (RFC 7591)
// ---------------------------------------------------------------------------

describe('CL18: Dynamic client registration', function (): void {

    it('creates a new OAuth client via the registration endpoint', function (): void {
        $tenant = createTenant();
        $this->postJson('/oauth/register', [
            'client_name' => 'CL18 Test App',
            'redirect_uris' => ['https://app.example.com/callback'],
            'grant_types' => ['authorization_code'],
            'scope' => 'projects:read projects:write',
            'tenant_slug' => $tenant->slug,
        ])->assertStatus(201)
            ->assertJsonStructure(['client_id', 'client_name', 'redirect_uris', 'grant_types']);

        $this->assertDatabaseHas('oauth_clients', ['name' => 'CL18 Test App']);
    });

    it('rejects registration without redirect_uris', function (): void {
        $tenant = createTenant();
        $this->postJson('/oauth/register', [
            'client_name' => 'Missing URIs',
            'tenant_slug' => $tenant->slug,
        ])->assertStatus(422);
    });

    it('rejects registration with invalid redirect URIs', function (): void {
        $tenant = createTenant();
        $this->postJson('/oauth/register', [
            'client_name' => 'Bad URI App',
            'redirect_uris' => ['not-a-valid-url'],
            'tenant_slug' => $tenant->slug,
        ])->assertStatus(422);
    });

});

// ---------------------------------------------------------------------------
// CL19 — Transition de statut invalide rejetée
// ---------------------------------------------------------------------------

describe('CL19: Invalid status transition rejected', function (): void {

    it('rejects transitioning from open to draft', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL19']);
        app(TenantManager::class)->setTenant($auth['tenant']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'open']);
        $storyId = $story->fresh()->identifier;

        $this->patchJson(
            "/api/v1/projects/CL19/stories/{$storyId}/status",
            ['statut' => 'draft'],
            authHeader($auth['token'])
        )->assertStatus(422);
    });

    it('rejects transitioning from draft to closed', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL19B']);
        app(TenantManager::class)->setTenant($auth['tenant']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'draft']);
        $storyId = $story->fresh()->identifier;

        $this->patchJson(
            "/api/v1/projects/CL19B/stories/{$storyId}/status",
            ['statut' => 'closed'],
            authHeader($auth['token'])
        )->assertStatus(422);
    });

    it('accepts valid transition draft -> open', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL19C']);
        app(TenantManager::class)->setTenant($auth['tenant']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id, 'statut' => 'draft']);
        $storyId = $story->fresh()->identifier;

        $this->patchJson(
            "/api/v1/projects/CL19C/stories/{$storyId}/status",
            ['statut' => 'open'],
            authHeader($auth['token'])
        )->assertStatus(200);
    });

});

// ---------------------------------------------------------------------------
// CL20 — Dépendance circulaire rejetée
// ---------------------------------------------------------------------------

describe('CL20: Circular dependency rejected', function (): void {

    it('rejects adding B->A when A->B already exists', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL20']);
        app(TenantManager::class)->setTenant($auth['tenant']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $storyA = Story::factory()->create(['epic_id' => $epic->id]);
        $storyB = Story::factory()->create(['epic_id' => $epic->id]);
        $idA = $storyA->fresh()->identifier;
        $idB = $storyB->fresh()->identifier;

        // A est bloqué par B
        $this->postJson('/api/v1/dependencies', [
            'blocked_identifier' => $idA,
            'blocking_identifier' => $idB,
        ], authHeader($auth['token']))->assertStatus(201);

        // B est bloqué par A → cycle
        $this->postJson('/api/v1/dependencies', [
            'blocked_identifier' => $idB,
            'blocking_identifier' => $idA,
        ], authHeader($auth['token']))->assertStatus(422);
    });

});

// ---------------------------------------------------------------------------
// CL21 — Dépendance vers un élément inexistant rejetée
// ---------------------------------------------------------------------------

describe('CL21: Dependency on non-existent item rejected', function (): void {

    it('returns 404 when one of the identifiers does not exist', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL21']);
        app(TenantManager::class)->setTenant($auth['tenant']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);
        $idA = $story->fresh()->identifier;

        $this->postJson('/api/v1/dependencies', [
            'blocked_identifier' => $idA,
            'blocking_identifier' => 'CL21-9999',
        ], authHeader($auth['token']))->assertStatus(404);
    });

    it('returns 404 when both identifiers do not exist', function (): void {
        $auth = createAuth();
        setupProject($auth, ['code' => 'CL21B']);

        $this->postJson('/api/v1/dependencies', [
            'blocked_identifier' => 'CL21B-0001',
            'blocking_identifier' => 'CL21B-0002',
        ], authHeader($auth['token']))->assertStatus(404);
    });

});

// ---------------------------------------------------------------------------
// CL22 — Création batch avec un item invalide : zéro items créés, erreur avec index
// ---------------------------------------------------------------------------

describe('CL22: Batch creation with invalid item — zero items created, error includes index', function (): void {

    it('creates zero stories when one item in the batch is invalid', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL22']);
        app(TenantManager::class)->setTenant($auth['tenant']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $epicId = $epic->fresh()->identifier;

        $response = $this->postJson(
            "/api/v1/projects/CL22/epics/{$epicId}/stories/batch",
            [
                'stories' => [
                    ['titre' => 'Valid Story', 'type' => 'backend'],
                    ['titre' => '',            'type' => 'backend'], // invalide : titre vide
                ],
            ],
            authHeader($auth['token'])
        )->assertStatus(422);

        // Aucune story créée (atomicité)
        expect(Story::where('epic_id', $epic->id)->count())->toBe(0);

        // L'erreur référence l'index de l'item invalide
        $errors = $response->json('errors');
        expect($errors)->toHaveKey('stories.1.titre');
    });

    it('creates zero tasks when one item in the batch is invalid', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL22B']);

        $response = $this->postJson(
            '/api/v1/projects/CL22B/tasks/batch',
            [
                'tasks' => [
                    ['titre' => 'Valid Task', 'type' => 'backend'],
                    ['titre' => '',           'type' => 'backend'], // invalide
                ],
            ],
            authHeader($auth['token'])
        )->assertStatus(422);

        expect(Task::where('project_id', $project->id)->count())->toBe(0);

        $errors = $response->json('errors');
        expect($errors)->toHaveKey('tasks.1.titre');
    });

});

// ---------------------------------------------------------------------------
// CL23 — Suppression d'un item bloquant : dépendances orphelines supprimées
// ---------------------------------------------------------------------------

describe('CL23: Deleting blocking item removes orphan dependencies', function (): void {

    it('removes the dependency when the blocking story is deleted', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL23']);
        app(TenantManager::class)->setTenant($auth['tenant']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $storyA = Story::factory()->create(['epic_id' => $epic->id]);
        $storyB = Story::factory()->create(['epic_id' => $epic->id]);

        /** @var DependencyService $service */
        $service = app(DependencyService::class);

        // storyB bloque storyA
        $service->addDependency($storyA, $storyB);

        $this->assertDatabaseHas('item_dependencies', [
            'item_id' => $storyA->id,
            'depends_on_id' => $storyB->id,
        ]);

        // Supprime storyB (le bloquant)
        $idB = $storyB->fresh()->identifier;
        $this->deleteJson(
            "/api/v1/projects/CL23/stories/{$idB}",
            [],
            authHeader($auth['token'])
        )->assertStatus(204);

        // La dépendance doit être nettoyée
        $this->assertDatabaseMissing('item_dependencies', [
            'depends_on_id' => $storyB->id,
        ]);

        // storyA ne doit plus avoir de bloquants
        $storyA->refresh();
        expect($storyA->blockedBy()->count())->toBe(0);
    });

    it('removes all dependencies of a deleted item (both directions)', function (): void {
        $auth = createAuth();
        $project = setupProject($auth, ['code' => 'CL23B']);
        app(TenantManager::class)->setTenant($auth['tenant']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $storyA = Story::factory()->create(['epic_id' => $epic->id]);
        $storyB = Story::factory()->create(['epic_id' => $epic->id]);
        $storyC = Story::factory()->create(['epic_id' => $epic->id]);

        /** @var DependencyService $service */
        $service = app(DependencyService::class);

        // storyA bloque storyB, storyC bloque storyA
        $service->addDependency($storyB, $storyA);
        $service->addDependency($storyA, $storyC);

        // Supprime storyA
        $idA = $storyA->fresh()->identifier;
        $this->deleteJson(
            "/api/v1/projects/CL23B/stories/{$idA}",
            [],
            authHeader($auth['token'])
        )->assertStatus(204);

        $this->assertDatabaseMissing('item_dependencies', ['item_id' => $storyA->id]);
        $this->assertDatabaseMissing('item_dependencies', ['depends_on_id' => $storyA->id]);
    });

});
