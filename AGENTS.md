# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## Development Commands

All commands run inside the DDEV container:

```bash
ddev start                                          # Start environment
ddev exec ./vendor/bin/pint                         # Fix PSR-12 style issues
ddev exec ./vendor/bin/pint --test                  # Check without fixing
ddev exec ./vendor/bin/phpstan analyse --no-progress # Static analysis (level 8)
ddev exec ./vendor/bin/pest                         # Run all tests
ddev exec ./vendor/bin/pest --filter "ToolName"     # Run a single test
ddev artisan user:create                            # Create a user
ddev artisan token:create --name="Agent" --user-id=1 # Generate MCP token
ddev artisan role:seed                              # Seed user roles
```

> Never use `php artisan pint` or `php artisan stan` — these artisan wrappers do not exist. Use the vendor binaries directly.

## Architecture Overview

### Core vs Modules

The codebase is split into two layers:

- **`app/Core/`** — the immutable platform: MCP server, REST API, auth, models, migrations
- **`app/Modules/`** — optional plugins that extend the platform (e.g., `Example`)

### Module System

Modules are declared in **`config/modules.php`** as `slug => ClassName` pairs. `ModuleServiceProvider` iterates this map on boot and:
1. Registers each module in `ModuleRegistry`
2. Calls `registerRoutes()`, `registerListeners()`, `loadMigrationsFrom(migrationPath())`
3. Registers `mcpTools()` into `McpServer` under the module slug

To create a module: implement `ModuleInterface` (`app/Core/Contracts/ModuleInterface.php`), place it in `app/Modules/<Name>/`, add it to `config/modules.php`.

### MCP Server

`McpServer` (singleton) is the dispatch hub:
- **Core tools** registered via `registerCoreTools(McpToolInterface)` in `CoreServiceProvider`
- **Module tools** registered via `registerModuleTools(slug, [McpToolInterface])` — only active for requests scoped to a project with that module activated
- Handles JSON-RPC methods: `initialize`, `tools/list`, `tools/call`, `resources/list`, `resources/read`, `prompts/list`, `prompts/get`
- `notifications/*` return HTTP 202 with no body (MCP spec requirement)

HTTP endpoints: `POST /mcp` (request) and `GET /mcp` (SSE stream), both protected by `auth.bearer`.

### Authentication

`AuthenticateBearer` middleware supports two token types, checked in order:
1. **`ApiToken`** — static SHA-256 hashed tokens, created via `artisan token:create`
2. **`OAuthAccessToken`** — OAuth2 PKCE flow tokens

Both resolve to a `User` via `Auth::setUser()`. No session is used.

### Tenant Isolation

Tenant isolation must be explicit for tenant-owned data. A tenant must never be able to see, resolve, list, mutate, or infer another tenant's stories, tasks, sprints, sprint items, documents, boards, tokens, or project data.

When adding new tenant-owned tables, include `tenant_id`, use `BelongsToTenant` where applicable, add tenant-aware queries/tests, and verify cross-tenant access is impossible through direct lookup, MCP tools, API routes, search, artifact resolution, relationships, and UI pages.

Do not rely only on indirect tenancy through parent relationships for sprint/backlog/board data unless the table is purely internal and unreachable. If data is exposed by MCP/API/UI or can be queried directly, prefer explicit `tenant_id` and tenant scope.

### Artifact Identifier System

`HasArtifactIdentifier` trait (used on `Epic`, `Story`, `Task`) auto-generates `{PROJECT_CODE}-{N}` identifiers inside a `DB::transaction` with `lockForUpdate()` on creation. The `Artifact` polymorphic table is the single source of truth for identifiers and supports cross-type search.

### Status Lifecycle

All artifacts follow: `draft → open → closed` (re-open: `closed → open`). Transitions are enforced in model methods; attempting an invalid transition throws a `ValidationException`.

### Middleware Stack (API routes)

- `auth.bearer` — validates token, sets authenticated user
- `project.access` — ensures the user is a member of the project in `{code}`
- `module.active:{slug}` — ensures the module is activated for the project

### Key Config

- `config/core.php` — item types, priorities, statuses, user/project roles, OAuth TTLs
- `config/modules.php` — module registration map
- `phpstan-baseline.neon` — baseline for pre-existing PHPStan errors; regenerate with `./vendor/bin/phpstan analyse --generate-baseline` when needed

### Testing Conventions

- Feature tests in `tests/Feature/Core/` mirror the `app/Core/` structure
- Each test file uses a `RefreshDatabase` trait (via `TestCase`)
- MCP integration tests use raw JSON-RPC payloads against the HTTP endpoint

## Story Operating Rules

These rules apply every time an agent works on, plans, reviews, or manipulates a Poiesis story through code, Git, or MCP.

### 1. Always qualify the story before acting

Before creating tasks, adding a story to a sprint, changing a status, writing code, merging, or closing anything, the agent must inspect and state:

- story identifier, title, current status, priority, type, nature, story points, tags;
- whether the story is already in a sprint, and which sprint;
- whether the story is already implemented or merged in Git;
- whether the story is `ready` when Scrum planning requires it;
- whether the story is blocked by dependencies;
- whether its current status is coherent with the intended action.

If a story is `closed`, the agent must treat it as already done. It must not add it to a future sprint or create implementation tasks for it unless the user explicitly says this is a correction, audit, or rework scenario.

### 2. Use Sam and Joyce for story planning

For every story decomposition, the agent must run a two-agent review:

- **Sam** proposes the technical decomposition, scope, implementation tasks, and validation approach.
- **Joyce** challenges Sam's proposal, focusing on missing cases, edge cases, architecture risks, security, tenancy, concurrency, MCP contract, and tests.

The dialogue must be visible to the user in this form:

1. Sam proposal
2. Joyce critique
3. Sam response or adjustment
4. Joyce final objections, if any
5. Consensus

The agent must not silently compress this into a final task list unless the user explicitly asks for a summary only.

### 3. Establish scope before task creation

Before creating MCP tasks under a story, the consensus must explicitly define:

- in-scope files/modules;
- out-of-scope work;
- business rules and lifecycle rules;
- edge cases;
- test strategy;
- verification commands;
- expected MCP/API/UI behavior.

Only after consensus may the agent create tasks under the story.

### 4. Keep task creation atomic and traceable

Tasks created under a story must be concrete and independently implementable. Each task must include:

- clear title;
- task type (`backend`, `frontend`, `devops`, or `qa`);
- nature (`feature`, `bug`, `improvement`, `spike`, or `chore`);
- priority;
- implementation scope;
- edge cases;
- expected tests;
- estimated effort when useful.

Avoid vague tasks such as "implement feature" or "add tests". Prefer tasks that a developer can pick up without reinterpreting the story.

### 5. Do not close work without user validation

The agent must not close a story merely because tests pass or a merge succeeded. A story may be closed only when:

- implementation is committed/merged according to the branch workflow;
- targeted tests and quality checks were run;
- MCP/UI behavior was verified when relevant;
- the user has validated the result or explicitly asked the agent to close it.

If the user reports a functional issue after closure, reopen the story immediately before further work.

### 6. Respect the Scrum workflow

Sprint backlog items should normally be open work, not completed work. Before adding items to a sprint, verify:

- the item is not `closed`;
- the item is not already in another planned or active sprint;
- the item is ready for planning when the current workflow requires `ready=true`;
- total story points fit the sprint capacity or the over-capacity decision is explicit.

If these checks fail, stop and explain the inconsistency before making the MCP change.

### 7. Keep UI modular and usable

When implementing UI, especially dashboard or Scrum views, do not dump full nested content into a single page. Design for progressive disclosure:

- overview pages must stay scannable and useful for piloting work;
- show compact summaries first, not full specifications;
- hide long descriptions, task details, logs, or nested content behind expandable sections, panels, or dedicated detail pages;
- extract repeated UI into Blade components inside the owning module;
- keep module UI components reusable and independent from unrelated modules;
- avoid large inline Blade blocks when a component can carry the behavior clearly;
- preserve module independence: Scrum UI components live under `app/Modules/Scrum/Resources/views/components/`.

Before accepting an UI implementation, ask whether the result is usable with real data volume, not only with a tiny test fixture.
