<?php

use App\Core\Contracts\ModuleInterface;
use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\ApiToken;
use App\Core\Models\Epic;
use App\Core\Models\OAuthAccessToken;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\Story;
use App\Core\Models\Task;
use App\Core\Models\User;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\DependencyService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

// ---------------------------------------------------------------------------
// Helper: anonymous ModuleInterface implementation
// ---------------------------------------------------------------------------

function makeModule(string $slug, array $deps = []): ModuleInterface
{
    return new class($slug, $deps) implements ModuleInterface
    {
        public function __construct(
            private readonly string $slug,
            private readonly array $deps,
        ) {}

        public function slug(): string
        {
            return $this->slug;
        }

        public function name(): string
        {
            return ucfirst($this->slug).' Module';
        }

        public function description(): string
        {
            return 'Test module';
        }

        public function dependencies(): array
        {
            return $this->deps;
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

// ===========================================================================
// ProjectMember::isLastOwner()
// ===========================================================================

describe('ProjectMember::isLastOwner()', function () {
    it('returns true when the user is the only owner', function () {
        $tenant = createTenant();
        $project = Project::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'position' => 'owner']);

        expect(ProjectMember::isLastOwner($project->id, $user->id))->toBeTrue();
    });

    it('returns false when multiple owners exist', function () {
        $tenant = createTenant();
        $project = Project::factory()->create(['tenant_id' => $tenant->id]);
        $u1 = User::factory()->create(['tenant_id' => $tenant->id]);
        $u2 = User::factory()->create(['tenant_id' => $tenant->id]);

        ProjectMember::create(['project_id' => $project->id, 'user_id' => $u1->id, 'position' => 'owner']);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $u2->id, 'position' => 'owner']);

        expect(ProjectMember::isLastOwner($project->id, $u1->id))->toBeFalse();
    });

    it('returns false when the user is only a member', function () {
        $tenant = createTenant();
        $project = Project::factory()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $member = User::factory()->create(['tenant_id' => $tenant->id]);

        ProjectMember::create(['project_id' => $project->id, 'user_id' => $owner->id, 'position' => 'owner']);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $member->id, 'position' => 'member']);

        expect(ProjectMember::isLastOwner($project->id, $member->id))->toBeFalse();
    });
});

// ===========================================================================
// Story::transitionStatus()
// ===========================================================================

describe('Story::transitionStatus()', function () {
    beforeEach(function () {
        $tenant = createTenant();
        $this->project = Project::factory()->create(['code' => 'STY', 'tenant_id' => $tenant->id]);
        $this->epic = Epic::factory()->create(['project_id' => $this->project->id]);
    });

    it('transitions draft → open', function () {
        $story = Story::factory()->create(['epic_id' => $this->epic->id, 'statut' => 'draft']);

        $story->transitionStatus('open');

        expect($story->fresh()->statut)->toBe('open');
    });

    it('transitions open → closed', function () {
        $story = Story::factory()->create(['epic_id' => $this->epic->id, 'statut' => 'open']);

        $story->transitionStatus('closed');

        expect($story->fresh()->statut)->toBe('closed');
    });

    it('transitions closed → open', function () {
        $story = Story::factory()->create(['epic_id' => $this->epic->id, 'statut' => 'closed']);

        $story->transitionStatus('open');

        expect($story->fresh()->statut)->toBe('open');
    });

    it('throws ValidationException on open → draft', function () {
        $story = Story::factory()->create(['epic_id' => $this->epic->id, 'statut' => 'open']);

        expect(fn () => $story->transitionStatus('draft'))
            ->toThrow(ValidationException::class);
    });

    it('throws ValidationException on closed → draft', function () {
        $story = Story::factory()->create(['epic_id' => $this->epic->id, 'statut' => 'closed']);

        expect(fn () => $story->transitionStatus('draft'))
            ->toThrow(ValidationException::class);
    });

    it('throws ValidationException on draft → closed', function () {
        $story = Story::factory()->create(['epic_id' => $this->epic->id, 'statut' => 'draft']);

        expect(fn () => $story->transitionStatus('closed'))
            ->toThrow(ValidationException::class);
    });
});

// ===========================================================================
// Task::transitionStatus()
// ===========================================================================

describe('Task::transitionStatus()', function () {
    beforeEach(function () {
        $tenant = createTenant();
        $this->project = Project::factory()->create(['code' => 'TSK', 'tenant_id' => $tenant->id]);
    });

    it('transitions draft → open', function () {
        $task = Task::factory()->standalone()->create(['project_id' => $this->project->id, 'statut' => 'draft']);

        $task->transitionStatus('open');

        expect($task->fresh()->statut)->toBe('open');
    });

    it('transitions open → closed', function () {
        $task = Task::factory()->standalone()->create(['project_id' => $this->project->id, 'statut' => 'open']);

        $task->transitionStatus('closed');

        expect($task->fresh()->statut)->toBe('closed');
    });

    it('transitions closed → open', function () {
        $task = Task::factory()->standalone()->create(['project_id' => $this->project->id, 'statut' => 'closed']);

        $task->transitionStatus('open');

        expect($task->fresh()->statut)->toBe('open');
    });

    it('throws ValidationException on open → draft', function () {
        $task = Task::factory()->standalone()->create(['project_id' => $this->project->id, 'statut' => 'open']);

        expect(fn () => $task->transitionStatus('draft'))
            ->toThrow(ValidationException::class);
    });

    it('throws ValidationException on closed → draft', function () {
        $task = Task::factory()->standalone()->create(['project_id' => $this->project->id, 'statut' => 'closed']);

        expect(fn () => $task->transitionStatus('draft'))
            ->toThrow(ValidationException::class);
    });

    it('throws ValidationException on draft → closed', function () {
        $task = Task::factory()->standalone()->create(['project_id' => $this->project->id, 'statut' => 'draft']);

        expect(fn () => $task->transitionStatus('closed'))
            ->toThrow(ValidationException::class);
    });
});

// ===========================================================================
// DependencyService::addDependency()
// ===========================================================================

describe('DependencyService::addDependency()', function () {
    beforeEach(function () {
        $this->service = new DependencyService;
        $tenant = createTenant();
        $project = Project::factory()->create(['code' => 'DEP', 'tenant_id' => $tenant->id]);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $this->epic = $epic;
        $this->project = $project;
    });

    it('rejects a self-dependency', function () {
        $story = Story::factory()->create(['epic_id' => $this->epic->id]);

        expect(fn () => $this->service->addDependency($story, $story))
            ->toThrow(ValidationException::class);
    });

    it('rejects a duplicate dependency', function () {
        $s1 = Story::factory()->create(['epic_id' => $this->epic->id]);
        $s2 = Story::factory()->create(['epic_id' => $this->epic->id]);

        $this->service->addDependency($s2, $s1);

        expect(fn () => $this->service->addDependency($s2, $s1))
            ->toThrow(ValidationException::class);
    });

    it('rejects a direct circular dependency', function () {
        $s1 = Story::factory()->create(['epic_id' => $this->epic->id]);
        $s2 = Story::factory()->create(['epic_id' => $this->epic->id]);

        $this->service->addDependency($s2, $s1); // s2 blocked by s1

        expect(fn () => $this->service->addDependency($s1, $s2)) // s1 blocked by s2 => cycle
            ->toThrow(ValidationException::class);
    });

    it('rejects a transitive circular dependency', function () {
        $a = Story::factory()->create(['epic_id' => $this->epic->id]);
        $b = Story::factory()->create(['epic_id' => $this->epic->id]);
        $c = Story::factory()->create(['epic_id' => $this->epic->id]);

        $this->service->addDependency($b, $a); // B blocked by A
        $this->service->addDependency($c, $b); // C blocked by B

        expect(fn () => $this->service->addDependency($a, $c)) // A blocked by C => A→C→B→A
            ->toThrow(ValidationException::class);
    });
});

// ===========================================================================
// HasArtifactIdentifier — identifier format
// ===========================================================================

describe('HasArtifactIdentifier', function () {
    it('generates identifiers matching {CODE}-{N}', function () {
        $tenant = createTenant();
        $project = Project::factory()->create(['code' => 'TST', 'tenant_id' => $tenant->id]);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $story = Story::factory()->create(['epic_id' => $epic->id]);

        expect($epic->identifier)->toMatch('/^TST-\d+$/');
        expect($story->identifier)->toMatch('/^TST-\d+$/');
    });

    it('assigns sequential numbers starting at 1', function () {
        $tenant = createTenant();
        $project = Project::factory()->create(['code' => 'SEQ', 'tenant_id' => $tenant->id]);
        $epic1 = Epic::factory()->create(['project_id' => $project->id]);
        $epic2 = Epic::factory()->create(['project_id' => $project->id]);

        expect($epic1->identifier)->toBe('SEQ-1');
        expect($epic2->identifier)->toBe('SEQ-2');
    });
});

// ===========================================================================
// Project code validation regex
// ===========================================================================

describe('Project code validation regex', function () {
    $pattern = '/^[A-Za-z0-9\-]{2,25}$/';

    it('accepts a 2-character alphabetic code', function () use ($pattern) {
        expect(preg_match($pattern, 'AB'))->toBe(1);
    });

    it('accepts a 25-character alphanumeric code', function () use ($pattern) {
        expect(preg_match($pattern, str_repeat('A', 25)))->toBe(1);
    });

    it('accepts codes containing hyphens', function () use ($pattern) {
        expect(preg_match($pattern, 'MY-PROJECT'))->toBe(1);
    });

    it('accepts mixed-case alphanumeric codes', function () use ($pattern) {
        expect(preg_match($pattern, 'MyProject1'))->toBe(1);
    });

    it('rejects a single-character code', function () use ($pattern) {
        expect(preg_match($pattern, 'A'))->toBe(0);
    });

    it('rejects a 26-character code', function () use ($pattern) {
        expect(preg_match($pattern, str_repeat('A', 26)))->toBe(0);
    });

    it('rejects codes containing spaces', function () use ($pattern) {
        expect(preg_match($pattern, 'MY PROJECT'))->toBe(0);
    });

    it('rejects codes containing special characters', function () use ($pattern) {
        expect(preg_match($pattern, 'MY_PROJECT'))->toBe(0);
    });

    it('rejects an empty string', function () use ($pattern) {
        expect(preg_match($pattern, ''))->toBe(0);
    });
});

// ===========================================================================
// ApiToken::isExpired()
// ===========================================================================

describe('ApiToken::isExpired()', function () {
    it('returns true when expires_at is in the past', function () {
        $tenant = createTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $raw = ApiToken::generateRaw();
        $token = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'test',
            'token' => $raw['hash'],
            'expires_at' => Carbon::yesterday(),
            'tenant_id' => $tenant->id,
        ]);

        expect($token->isExpired())->toBeTrue();
    });

    it('returns false when expires_at is in the future', function () {
        $tenant = createTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $raw = ApiToken::generateRaw();
        $token = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'test',
            'token' => $raw['hash'],
            'expires_at' => Carbon::tomorrow(),
            'tenant_id' => $tenant->id,
        ]);

        expect($token->isExpired())->toBeFalse();
    });

    it('returns false when expires_at is null', function () {
        $tenant = createTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $raw = ApiToken::generateRaw();
        $token = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'test',
            'token' => $raw['hash'],
            'expires_at' => null,
            'tenant_id' => $tenant->id,
        ]);

        expect($token->isExpired())->toBeFalse();
    });
});

// ===========================================================================
// OAuthAccessToken expiry check
// ===========================================================================

describe('OAuthAccessToken expiry', function () {
    beforeEach(function () {
        $tenant = createTenant();
        $this->tenant = $tenant;
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->client = \App\Core\Models\OAuthClient::create([
            'name' => 'Test Client',
            'client_id' => 'oauth-test-client-'.uniqid(),
            'redirect_uris' => ['http://localhost/callback'],
            'tenant_id' => $tenant->id,
        ]);
    });

    it('is expired when expires_at is in the past', function () {
        $token = OAuthAccessToken::create([
            'oauth_client_id' => $this->client->id,
            'user_id' => $this->user->id,
            'token' => bin2hex(random_bytes(16)),
            'scopes' => [],
            'expires_at' => Carbon::yesterday(),
            'tenant_id' => $this->tenant->id,
        ]);

        expect($token->expires_at->isPast())->toBeTrue();
    });

    it('is not expired when expires_at is in the future', function () {
        $token = OAuthAccessToken::create([
            'oauth_client_id' => $this->client->id,
            'user_id' => $this->user->id,
            'token' => bin2hex(random_bytes(16)),
            'scopes' => [],
            'expires_at' => Carbon::tomorrow(),
            'tenant_id' => $this->tenant->id,
        ]);

        expect($token->expires_at->isPast())->toBeFalse();
    });
});

// ===========================================================================
// ModuleRegistry
// ===========================================================================

describe('ModuleRegistry', function () {
    beforeEach(function () {
        $this->registry = new ModuleRegistry;
    });

    it('registers a module and retrieves it by slug', function () {
        $module = makeModule('example');

        $this->registry->register($module);

        expect($this->registry->get('example'))->toBe($module);
    });

    it('returns null for an unregistered slug', function () {
        expect($this->registry->get('ghost'))->toBeNull();
    });

    it('reports a slug as registered after registration', function () {
        $this->registry->register(makeModule('alpha'));

        expect($this->registry->isRegistered('alpha'))->toBeTrue();
    });

    it('reports a slug as not registered before registration', function () {
        expect($this->registry->isRegistered('unknown'))->toBeFalse();
    });

    it('returns all registered modules', function () {
        $this->registry->register(makeModule('mod-a'));
        $this->registry->register(makeModule('mod-b'));

        expect($this->registry->all())->toHaveCount(2);
    });

    it('returns the declared dependencies for a module', function () {
        $this->registry->register(makeModule('child', ['parent']));

        expect($this->registry->getDependenciesFor('child'))->toBe(['parent']);
    });

    it('returns an empty array for dependencies of an unregistered module', function () {
        expect($this->registry->getDependenciesFor('ghost'))->toBe([]);
    });

    it('returns slugs of modules that depend on a given slug', function () {
        $this->registry->register(makeModule('parent'));
        $this->registry->register(makeModule('child', ['parent']));
        $this->registry->register(makeModule('orphan'));

        expect($this->registry->getDependentsOf('parent'))->toBe(['child']);
        expect($this->registry->getDependentsOf('orphan'))->toBe([]);
    });
});

// ===========================================================================
// Config values — config('core.*')
// ===========================================================================

describe('config(core.*)', function () {
    it('exposes item_types', function () {
        expect(config('core.item_types'))->toBeArray()->not->toBeEmpty();
    });

    it('exposes priorities', function () {
        expect(config('core.priorities'))->toBeArray()->not->toBeEmpty();
    });

    it('exposes default_priority', function () {
        expect(config('core.default_priority'))->toBeString()->not->toBeEmpty();
    });

    it('exposes statuts', function () {
        expect(config('core.statuts'))->toBeArray()->not->toBeEmpty();
    });

    it('exposes default_statut', function () {
        expect(config('core.default_statut'))->toBeString()->not->toBeEmpty();
    });

    it('exposes work_natures', function () {
        expect(config('core.work_natures'))->toBeArray()->not->toBeEmpty();
    });

    it('exposes project_positions', function () {
        expect(config('core.project_positions'))->toBeArray()->not->toBeEmpty();
    });

    it('exposes default_project_position', function () {
        expect(config('core.default_project_position'))->toBeString()->not->toBeEmpty();
    });

    it('exposes oauth_scopes', function () {
        expect(config('core.oauth_scopes'))->toBeArray()->not->toBeEmpty();
    });

    it('exposes oauth_access_token_ttl', function () {
        expect(config('core.oauth_access_token_ttl'))->toBeInt()->toBeGreaterThan(0);
    });

    it('exposes oauth_refresh_token_ttl', function () {
        expect(config('core.oauth_refresh_token_ttl'))->toBeInt()->toBeGreaterThan(0);
    });
});
