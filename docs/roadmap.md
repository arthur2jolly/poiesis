# Agile Roadmap — Poiesis: Le Codage Sans Fin

**Version:** 1.0
**Date:** 2026-02-21
**Project:** Poiesis — Modular Agile Project Management Platform for AI Agents
**Stack:** Laravel 12, PHP 8.4, MariaDB 11.6, DDEV

---

## Reading Guide

Each story follows this structure:

- **ID:** Global identifier in the format `POIESIS-{N}`
- **Title:** Imperative, action-oriented description
- **Description:** What to build and why, with enough detail for a developer or AI agent to implement without ambiguity
- **Type:** `backend` | `frontend` | `devops` | `qa`
- **Nature:** `feature` | `bug` | `improvement` | `spike` | `chore`
- **Priority:** `critical` | `high` | `medium` | `low`
- **Points:** Fibonacci estimate (1, 2, 3, 5, 8, 13, 21)
- **Blocked by:** List of POIESIS-{N} IDs that must be completed first

Stories within each epic are ordered logically, respecting their dependency chain.

---

## Epic Index

| Epic | Title | Stories |
|------|-------|---------|
| E1 | Project Scaffolding and DDEV Setup | POIESIS-1 to POIESIS-8 |
| E2 | Database Schema and Migrations | POIESIS-9 to POIESIS-22 |
| E3 | Core Models and Relationships | POIESIS-23 to POIESIS-34 |
| E4 | Authentication — Static Tokens and OAuth2 | POIESIS-35 to POIESIS-46 |
| E5 | Internal REST API Layer | POIESIS-47 to POIESIS-74 |
| E6 | MCP Server Integration | POIESIS-75 to POIESIS-96 |
| E7 | Module System | POIESIS-97 to POIESIS-107 |
| E8 | Artisan CLI Commands | POIESIS-108 to POIESIS-120 |
| E9 | Testing and QA | POIESIS-121 to POIESIS-138 |
| E10 | DevOps and Deployment | POIESIS-139 to POIESIS-148 |

---

## Epic E1 — Project Scaffolding and DDEV Setup

**Scope:** Establish the local development environment with DDEV, initialize the Laravel 12 application, configure PHP 8.4, set up MariaDB 11.6, and put foundational tooling in place (code style, static analysis, Git hooks). This epic produces a running, empty Laravel application accessible locally via DDEV, with all developer tooling configured and documented.

**Goal:** Any developer or AI agent can clone the repository, run `ddev start`, and have a fully functional local environment with a seeded database in under five minutes.

---

### POIESIS-1

- **Title:** Initialize Laravel 12 project with PHP 8.4
- **Description:** Create a new Laravel 12 application targeting PHP 8.4. Remove the default scaffolding that is not needed (Breeze, Sail, default welcome route). Set `APP_NAME=Poiesis` in `.env.example`. Ensure `composer.json` declares `"php": "^8.4"`. Run `composer install` and verify the application boots with `php artisan serve`.
- **Type:** devops
- **Nature:** chore
- **Priority:** critical
- **Points:** 2
- **Blocked by:** —

---

### POIESIS-2

- **Title:** Configure DDEV for Laravel with MariaDB 11.6
- **Description:** Add a `.ddev/config.yaml` configuring the project type as `laravel`, PHP version `8.4`, database type `mariadb`, MariaDB version `11.6`, and web server `nginx-fpm`. Set the `docroot` to `public`. Add a `.ddev/docker-compose.override.yaml` if needed for any custom service configuration. Provide a `ddev start` command that brings the full stack up. Document the local URLs in `README.md`. Do NOT configure Redis — it is explicitly excluded from this project.
- **Type:** devops
- **Nature:** chore
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-1

---

### POIESIS-3

- **Title:** Configure environment variables and application base settings
- **Description:** Set up `.env.example` with all required variables: `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_URL`, `DB_CONNECTION=mysql` (MariaDB uses the mysql driver in Laravel), `DB_HOST`, `DB_PORT=3306`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Add Poiesis-specific variables: `MCP_ENDPOINT_PATH=/mcp`, `OAUTH_ACCESS_TOKEN_TTL=60`, `OAUTH_REFRESH_TOKEN_TTL=43200`. Provide a DDEV-compatible `.env` via a `ddev/config` hook or documented setup step. Ensure `config/app.php` references the correct timezone (`UTC`).
- **Type:** devops
- **Nature:** chore
- **Priority:** critical
- **Points:** 2
- **Blocked by:** POIESIS-2

---

### POIESIS-4

- **Title:** Create the Core configuration file config/core.php
- **Description:** Create `config/core.php` with the following keys as specified in the architecture document. All values must be plain PHP arrays — no enums, no DB lookups. Keys required: `item_types` (`['backend', 'frontend', 'devops', 'qa']`), `priorities` (`['critique', 'haute', 'moyenne', 'basse']`), `default_priority` (`'moyenne'`), `statuts` (`['draft', 'open', 'closed']`), `default_statut` (`'draft'`), `work_natures` (`['feature', 'bug', 'improvement', 'spike', 'chore']`), `project_roles` (`['owner', 'member']`), `default_project_role` (`'member'`), `oauth_scopes` (`['projects:read', 'projects:write', 'admin']`), `oauth_access_token_ttl` (`60`), `oauth_refresh_token_ttl` (`43200`). This file is the single source of truth for all business values. Add a corresponding entry in `config/modules.php` (empty array) for later use by the module system.
- **Type:** backend
- **Nature:** chore
- **Priority:** critical
- **Points:** 1
- **Blocked by:** POIESIS-1

---

### POIESIS-5

- **Title:** Set up Laravel Pint for code style enforcement
- **Description:** Install `laravel/pint` as a dev dependency. Create a `pint.json` at the project root using the `laravel` preset. Add a Composer script: `"lint": "pint"` and `"lint:check": "pint --test"`. Configure a Git pre-commit hook (via `.git/hooks/pre-commit` or a tool such as `captainhook/captainhook`) that runs `composer lint:check` and blocks commits if style violations are detected. Document the workflow in `README.md`.
- **Type:** devops
- **Nature:** chore
- **Priority:** medium
- **Points:** 2
- **Blocked by:** POIESIS-1

---

### POIESIS-6

- **Title:** Set up PHPStan at level 8 for static analysis
- **Description:** Install `phpstan/phpstan` and `larastan/larastan` as dev dependencies. Create `phpstan.neon` at the project root with `level: 8`, paths pointing to `app/` and `config/`, and the Larastan extension enabled. Add a Composer script: `"analyse": "phpstan analyse"`. The analysis must pass with zero errors on a fresh installation. Add the script to the CI pipeline placeholder (even if CI is not yet configured).
- **Type:** devops
- **Nature:** chore
- **Priority:** medium
- **Points:** 2
- **Blocked by:** POIESIS-1

---

### POIESIS-7

- **Title:** Bootstrap the Core service provider and application namespace
- **Description:** Create `app/Core/Providers/CoreServiceProvider.php`. This provider is responsible for: (1) loading routes from `app/Core/Routes/api.php` and `app/Core/Routes/mcp.php`, (2) registering Artisan commands from `app/Core/Console/Commands/`, (3) binding core contracts in the container. Register `CoreServiceProvider` in `bootstrap/providers.php`. Create the empty directory structure matching the architecture specification: `app/Core/Models/`, `app/Core/Http/Controllers/`, `app/Core/Http/Middleware/`, `app/Core/Http/Requests/`, `app/Core/Http/Resources/`, `app/Core/Console/Commands/`, `app/Core/Mcp/`, `app/Core/Routes/`, `app/Core/Database/Migrations/`. Add empty route files `api.php` and `mcp.php`.
- **Type:** backend
- **Nature:** chore
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-4

---

### POIESIS-8

- **Title:** Configure DDEV database hooks and application seeding scaffold
- **Description:** Add a DDEV post-start hook that runs `ddev exec php artisan migrate --force` and `ddev exec php artisan db:seed --class=DevSeeder` when the environment starts. Create an empty `DevSeeder` class in `database/seeders/` that will be populated incrementally as models are built. Document in `README.md` the commands required to reset the local database: `ddev exec php artisan migrate:fresh --seed`. Ensure MariaDB connection settings are correct in `config/database.php` (charset `utf8mb4`, collation `utf8mb4_unicode_ci`).
- **Type:** devops
- **Nature:** chore
- **Priority:** high
- **Points:** 2
- **Blocked by:** POIESIS-2, POIESIS-3

---

## Epic E2 — Database Schema and Migrations

**Scope:** Define and implement all database migrations for the Core entities as specified in the architecture document. This covers every table: `projects`, `users`, `api_tokens`, `oauth_clients`, `oauth_authorization_codes`, `oauth_access_tokens`, `oauth_refresh_tokens`, `project_members`, `epics`, `stories`, `tasks`, `artifacts`, and `item_dependencies`. All primary keys use UUID v7. All foreign keys use CASCADE delete unless otherwise specified. No Redis. No Postgres.

**Goal:** Running `php artisan migrate` from a clean database produces a fully formed schema that can hold all Core data, with correct indexes, constraints, and foreign key relationships.

---

### POIESIS-9

- **Title:** Create the projects table migration
- **Description:** Migration file: `create_projects_table`. Columns: `id` (uuid v7, PK), `code` (varchar 25, UNIQUE, NOT NULL), `titre` (varchar 255, NOT NULL), `description` (text, NULLABLE), `modules` (json, NOT NULL, default `'[]'`), `created_at` (timestamp), `updated_at` (timestamp). Add an index on `code`. Route model binding for Project will use `code` not `id` — ensure this is documented in a comment in the migration. Laravel's `HasUuids` trait will handle UUID generation; the migration column type must be `uuid()` not `string()`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 1
- **Blocked by:** POIESIS-7

---

### POIESIS-10

- **Title:** Create the users table migration
- **Description:** Migration file: `create_users_table`. Replace or extend the default Laravel users migration. Columns: `id` (uuid v7, PK), `name` (varchar 255, NOT NULL), `created_at` (timestamp), `updated_at` (timestamp). Remove all default Laravel auth columns (email, password, remember_token, email_verified_at) — this platform does not use password-based auth. If the default migration exists, drop it and replace with a clean version. Add an index on `name` for the Artisan user lookup commands.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 1
- **Blocked by:** POIESIS-7

---

### POIESIS-11

- **Title:** Create the api_tokens table migration
- **Description:** Migration file: `create_api_tokens_table`. Columns: `id` (uuid v7, PK), `user_id` (uuid, FK → `users.id`, ON DELETE CASCADE, NOT NULL), `name` (varchar 255, NOT NULL), `token` (varchar 255, UNIQUE, NOT NULL — stores SHA-256 hash of the raw token), `expires_at` (timestamp, NULLABLE — null means never expires), `last_used_at` (timestamp, NULLABLE), `created_at` (timestamp). Note: no `updated_at` — tokens are immutable once created; only `last_used_at` is updated. Add composite index on `(user_id, name)`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 1
- **Blocked by:** POIESIS-10

---

### POIESIS-12

- **Title:** Create the oauth_clients table migration
- **Description:** Migration file: `create_oauth_clients_table`. Columns: `id` (uuid v7, PK), `user_id` (uuid, FK → `users.id`, NULLABLE, ON DELETE CASCADE), `name` (varchar 255, NOT NULL), `client_id` (varchar 255, UNIQUE, NOT NULL), `client_secret` (varchar 255, NULLABLE — public clients have no secret), `redirect_uris` (json, NOT NULL), `grant_types` (json, NOT NULL, default `'["authorization_code"]'`), `scopes` (json, NULLABLE), `created_at` (timestamp), `updated_at` (timestamp). Add index on `client_id`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 2
- **Blocked by:** POIESIS-10

---

### POIESIS-13

- **Title:** Create the oauth_authorization_codes table migration
- **Description:** Migration file: `create_oauth_authorization_codes_table`. Columns: `id` (uuid v7, PK), `oauth_client_id` (uuid, FK → `oauth_clients.id`, ON DELETE CASCADE, NOT NULL), `user_id` (uuid, FK → `users.id`, ON DELETE CASCADE, NOT NULL), `code` (varchar 255, UNIQUE, NOT NULL), `redirect_uri` (varchar 2048, NOT NULL), `scopes` (json, NULLABLE), `code_challenge` (varchar 255, NULLABLE — for PKCE), `code_challenge_method` (varchar 10, NULLABLE — value `S256`), `expires_at` (timestamp, NOT NULL), `created_at` (timestamp). Authorization codes are short-lived; no `updated_at` needed.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 2
- **Blocked by:** POIESIS-12

---

### POIESIS-14

- **Title:** Create the oauth_access_tokens table migration
- **Description:** Migration file: `create_oauth_access_tokens_table`. Columns: `id` (uuid v7, PK), `oauth_client_id` (uuid, FK → `oauth_clients.id`, ON DELETE CASCADE, NOT NULL), `user_id` (uuid, FK → `users.id`, ON DELETE CASCADE, NOT NULL), `token` (varchar 255, UNIQUE, NOT NULL — SHA-256 hash), `scopes` (json, NULLABLE), `expires_at` (timestamp, NOT NULL), `created_at` (timestamp). No `updated_at`. Add index on `token` for fast lookup during authentication.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 1
- **Blocked by:** POIESIS-12

---

### POIESIS-15

- **Title:** Create the oauth_refresh_tokens table migration
- **Description:** Migration file: `create_oauth_refresh_tokens_table`. Columns: `id` (uuid v7, PK), `access_token_id` (uuid, FK → `oauth_access_tokens.id`, ON DELETE CASCADE, NOT NULL), `token` (varchar 255, UNIQUE, NOT NULL — SHA-256 hash), `expires_at` (timestamp, NOT NULL), `revoked` (boolean, NOT NULL, default false), `created_at` (timestamp). Add index on `token`. Cascading from `access_token_id` means that revoking an access token also deletes its refresh tokens.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 1
- **Blocked by:** POIESIS-14

---

### POIESIS-16

- **Title:** Create the project_members pivot table migration
- **Description:** Migration file: `create_project_members_table`. Columns: `id` (uuid v7, PK), `project_id` (uuid, FK → `projects.id`, ON DELETE CASCADE, NOT NULL), `user_id` (uuid, FK → `users.id`, ON DELETE CASCADE, NOT NULL), `role` (varchar 20, NOT NULL, default `'member'`), `created_at` (timestamp). No `updated_at` — role changes will use an UPDATE query but the timestamp is irrelevant to the pivot. Add UNIQUE constraint on `(project_id, user_id)` — a user can only appear once per project. Add index on `(project_id, role)` to efficiently find owners.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 2
- **Blocked by:** POIESIS-9, POIESIS-10

---

### POIESIS-17

- **Title:** Create the epics table migration
- **Description:** Migration file: `create_epics_table`. Columns: `id` (uuid v7, PK), `project_id` (uuid, FK → `projects.id`, ON DELETE CASCADE, NOT NULL), `titre` (varchar 255, NOT NULL), `description` (text, NULLABLE), `created_at` (timestamp), `updated_at` (timestamp). Add index on `project_id`. Epics do not have an `ordre` field — their ordering is determined by `created_at`. Cascade delete: deleting a project deletes all its epics.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 1
- **Blocked by:** POIESIS-9

---

### POIESIS-18

- **Title:** Create the stories table migration
- **Description:** Migration file: `create_stories_table`. Columns: `id` (uuid v7, PK), `epic_id` (uuid, FK → `epics.id`, ON DELETE CASCADE, NOT NULL), `titre` (varchar 255, NOT NULL), `description` (text, NULLABLE), `type` (varchar 20, NOT NULL), `nature` (varchar 20, NULLABLE), `statut` (varchar 20, NOT NULL, default `'draft'`), `priorite` (varchar 20, NOT NULL, default `'moyenne'`), `ordre` (integer unsigned, NULLABLE), `story_points` (integer unsigned, NULLABLE), `reference_doc` (varchar 2048, NULLABLE), `tags` (json, NULLABLE), `created_at` (timestamp), `updated_at` (timestamp). Indexes: `epic_id`, `type`, `nature`, `statut`, `priorite`, `ordre`. Note: MariaDB does not support GIN indexes natively; for `tags` (json), add a generated virtual column `tags_index` as a computed expression if full-text JSON search is needed, otherwise rely on application-level filtering with `JSON_CONTAINS`. Document this decision in a comment in the migration.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-17

---

### POIESIS-19

- **Title:** Create the tasks table migration
- **Description:** Migration file: `create_tasks_table`. Columns: `id` (uuid v7, PK), `project_id` (uuid, FK → `projects.id`, ON DELETE CASCADE, NOT NULL), `story_id` (uuid, FK → `stories.id`, NULLABLE, ON DELETE SET NULL — important: when a story is deleted, its child tasks should also be deleted, so use CASCADE, not SET NULL; re-read the spec: "Cascade deletion: deleting a Story deletes its child Tasks" — use ON DELETE CASCADE), `titre` (varchar 255, NOT NULL), `description` (text, NULLABLE), `type` (varchar 20, NOT NULL), `nature` (varchar 20, NULLABLE), `statut` (varchar 20, NOT NULL, default `'draft'`), `priorite` (varchar 20, NOT NULL, default `'moyenne'`), `ordre` (integer unsigned, NULLABLE), `estimation_temps` (integer unsigned, NULLABLE — time estimate in minutes), `tags` (json, NULLABLE), `created_at` (timestamp), `updated_at` (timestamp). Indexes: `project_id`, `story_id`, `type`, `nature`, `statut`, `priorite`, `ordre`. The FK on `story_id` uses ON DELETE CASCADE (not SET NULL) because child tasks are deleted when their parent story is deleted. Standalone tasks have `story_id = NULL`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-18

---

### POIESIS-20

- **Title:** Create the artifacts table migration
- **Description:** Migration file: `create_artifacts_table`. This table is the centralized registry of business identifiers. Columns: `id` (uuid v7, PK), `project_id` (uuid, FK → `projects.id`, ON DELETE CASCADE, NOT NULL), `identifier` (varchar 35, UNIQUE, NOT NULL — format `{CODE}-{N}`, e.g., `AGENTMG-1`), `sequence_number` (integer unsigned, NOT NULL), `artifactable_id` (uuid, NOT NULL — polymorphic FK), `artifactable_type` (varchar 255, NOT NULL — class name: `App\Core\Models\Epic`, `App\Core\Models\Story`, or `App\Core\Models\Task`), `created_at` (timestamp), `updated_at` (timestamp). Indexes: UNIQUE on `identifier`, composite index on `(project_id, sequence_number)`, index on `(artifactable_id, artifactable_type)`. The sequence counter must be unique per project — the application enforces this with a `SELECT ... FOR UPDATE` lock during artifact creation.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-17, POIESIS-18, POIESIS-19

---

### POIESIS-21

- **Title:** Create the item_dependencies table migration
- **Description:** Migration file: `create_item_dependencies_table`. This table expresses blocking relationships between items (Story or Task). Columns: `id` (uuid v7, PK), `item_id` (uuid, NOT NULL — the blocked item), `item_type` (varchar 255, NOT NULL — class name of the blocked item), `depends_on_id` (uuid, NOT NULL — the blocking item), `depends_on_type` (varchar 255, NOT NULL — class name of the blocking item), `created_at` (timestamp). No `updated_at`. Add UNIQUE constraint on `(item_id, item_type, depends_on_id, depends_on_type)` to prevent duplicate dependencies. Add indexes on `(item_id, item_type)` and `(depends_on_id, depends_on_type)`. There are no direct FK constraints on the polymorphic columns — the application handles referential integrity and cascade cleanup via Eloquent model observers.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 2
- **Blocked by:** POIESIS-18, POIESIS-19

---

### POIESIS-22

- **Title:** Verify full migration run and document schema
- **Description:** Run `php artisan migrate:fresh` from scratch and verify that all 13 migrations execute successfully in order, with no foreign key constraint errors. Verify that MariaDB 11.6 accepts all column types used (uuid, json, etc.). Run `php artisan migrate:status` and confirm all migrations are listed as `Ran`. Add a brief schema diagram (ASCII or description) to a `docs/schema.md` file illustrating the relationships between all tables. This story is a verification and documentation gate before model implementation begins.
- **Type:** qa
- **Nature:** chore
- **Priority:** high
- **Points:** 2
- **Blocked by:** POIESIS-9, POIESIS-10, POIESIS-11, POIESIS-12, POIESIS-13, POIESIS-14, POIESIS-15, POIESIS-16, POIESIS-17, POIESIS-18, POIESIS-19, POIESIS-20, POIESIS-21

---

## Epic E3 — Core Models and Relationships

**Scope:** Implement all Eloquent models for the Core entities. Each model must use UUID v7 (`HasUuids`), define all relationships, define `$fillable` attributes, add casts for JSON and timestamp fields, and implement any model-level business logic (attribute mutators, scopes, observers). The `HasArtifactIdentifier` trait handles automatic artifact assignment on creation.

**Goal:** All Core entities can be created, read, updated, and deleted via Eloquent with correct cascades, correct UUID generation, and automatic artifact identifier assignment.

---

### POIESIS-23

- **Title:** Implement the Project model
- **Description:** Create `app/Core/Models/Project.php`. Use `HasUuids`. Set `$fillable = ['code', 'titre', 'description', 'modules']`. Cast `modules` to `array`. Add `$casts = ['modules' => 'array', 'created_at' => 'datetime', 'updated_at' => 'datetime']`. Define relationships: `hasMany(Epic::class)`, `hasMany(Task::class)->whereNull('story_id')` (standalone tasks), `hasMany(Artifact::class)`, `belongsToMany(User::class, 'project_members')->withPivot('role', 'created_at')`. Override `getRouteKeyName()` to return `'code'` for route model binding. Add a `members()` relationship via the `ProjectMember` pivot model. Add a scope `accessibleBy(User $user)` that filters projects where the user is a member.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-22

---

### POIESIS-24

- **Title:** Implement the User model
- **Description:** Create `app/Core/Models/User.php`. Extend `Illuminate\Foundation\Auth\User` for compatibility with Laravel's auth contracts, but remove all password-related concerns. Use `HasUuids`. Set `$fillable = ['name']`. Define relationships: `belongsToMany(Project::class, 'project_members')->withPivot('role', 'created_at')`, `hasMany(ApiToken::class)`, `hasMany(OAuthClient::class)`. Implement `Illuminate\Contracts\Auth\Authenticatable` — this is required for `$request->user()` to work with the custom `AuthenticateBearer` middleware. Remove the default password, email, and remember_token fillable items.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 2
- **Blocked by:** POIESIS-22

---

### POIESIS-25

- **Title:** Implement the ProjectMember pivot model
- **Description:** Create `app/Core/Models/ProjectMember.php` extending `Illuminate\Database\Eloquent\Relations\Pivot`. Use `HasUuids`. Define `$table = 'project_members'`. Set `$fillable = ['project_id', 'user_id', 'role']`. Add a `role` validation scope or method that checks the value against `config('core.project_roles')`. Define relationships `belongsTo(Project::class)` and `belongsTo(User::class)`. Add a static method `isLastOwner(string $projectId, string $userId): bool` that returns true if the given user is the only owner of the project — this enforces business rule R1.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 2
- **Blocked by:** POIESIS-23, POIESIS-24

---

### POIESIS-26

- **Title:** Implement the ApiToken model
- **Description:** Create `app/Core/Models/ApiToken.php`. Use `HasUuids`. Set `$fillable = ['user_id', 'name', 'token', 'expires_at']`. Cast `expires_at` and `last_used_at` to `datetime` (nullable). Add a `isExpired(): bool` method that returns true if `expires_at` is not null and is in the past. Add a `touch()` override or a custom `recordUsage()` method that updates `last_used_at` to now. The `token` attribute stores the SHA-256 hash — never the raw token. Add a static `generateRaw(): string` method that creates a raw token string prefixed with `aa-` followed by 40 random hex characters, then returns both the raw token and its SHA-256 hash. Define `belongsTo(User::class)`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-24

---

### POIESIS-27

- **Title:** Implement OAuth2 models (OAuthClient, OAuthAuthorizationCode, OAuthAccessToken, OAuthRefreshToken)
- **Description:** Create four models in `app/Core/Models/`. All use `HasUuids`. `OAuthClient`: `$fillable = ['user_id', 'name', 'client_id', 'client_secret', 'redirect_uris', 'grant_types', 'scopes']`, cast `redirect_uris`, `grant_types`, `scopes` to `array`, define `hasMany(OAuthAuthorizationCode::class)`, `hasMany(OAuthAccessToken::class)`. `OAuthAuthorizationCode`: `$fillable = ['oauth_client_id', 'user_id', 'code', 'redirect_uri', 'scopes', 'code_challenge', 'code_challenge_method', 'expires_at']`, cast `scopes` to `array`, `expires_at` to `datetime`, `isExpired(): bool`. `OAuthAccessToken`: `$fillable = ['oauth_client_id', 'user_id', 'token', 'scopes', 'expires_at']`, cast `scopes` to `array`, `expires_at` to `datetime`, `isExpired(): bool`, `hasOne(OAuthRefreshToken::class)`. `OAuthRefreshToken`: `$fillable = ['access_token_id', 'token', 'expires_at', 'revoked']`, cast `expires_at` to `datetime`, `revoked` to `boolean`, `isExpired(): bool`, `isRevoked(): bool`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-24

---

### POIESIS-28

- **Title:** Implement the HasArtifactIdentifier trait
- **Description:** Create `app/Core/Models/Concerns/HasArtifactIdentifier.php`. This trait is used by `Epic`, `Story`, and `Task`. It must hook into the `created` Eloquent event (via `static::created()` in `bootHasArtifactIdentifier()`). On creation, it must: (1) acquire a `SELECT sequence_number FROM artifacts WHERE project_id = ? ORDER BY sequence_number DESC LIMIT 1 FOR UPDATE` lock to get the current max sequence number for the project, (2) compute the new `sequence_number = max + 1` (or 1 if none exists), (3) build the identifier as `{PROJECT_CODE}-{N}` (e.g., `AGENTMG-7`), (4) insert a new `Artifact` record. The entire operation must occur inside a database transaction. This guarantees atomicity even under concurrent creation (CL4). The trait must also add an `artifact()` morphOne relationship and an `identifier` accessor that returns the artifact identifier string.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 8
- **Blocked by:** POIESIS-20

---

### POIESIS-29

- **Title:** Implement the Epic model
- **Description:** Create `app/Core/Models/Epic.php`. Use `HasUuids` and `HasArtifactIdentifier`. Set `$fillable = ['project_id', 'titre', 'description']`. Define relationships: `belongsTo(Project::class)`, `hasMany(Story::class)`, `morphOne(Artifact::class, 'artifactable')`. Add a `storiesCount()` method or eager-loadable count. The model must resolve the `project_id` from its parent project for artifact generation — the trait needs access to the project code. Add a `getProjectCodeAttribute()` helper that returns `$this->project->code` (with `project` eager-loaded or fetched if needed). Cascade deletion is handled at the DB level (ON DELETE CASCADE on `epics.project_id`).
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 2
- **Blocked by:** POIESIS-28, POIESIS-23

---

### POIESIS-30

- **Title:** Implement the Story model
- **Description:** Create `app/Core/Models/Story.php`. Use `HasUuids` and `HasArtifactIdentifier`. Set `$fillable = ['epic_id', 'titre', 'description', 'type', 'nature', 'statut', 'priorite', 'ordre', 'story_points', 'reference_doc', 'tags']`. Cast `tags` to `array`. Define relationships: `belongsTo(Epic::class)`, `hasMany(Task::class)`, `morphOne(Artifact::class, 'artifactable')`, `belongsToMany(self::class, 'item_dependencies', 'item_id', 'depends_on_id')->as('dependency')` (blockedBy), `belongsToMany(self::class, 'item_dependencies', 'depends_on_id', 'item_id')->as('dependency')` (blocks). Also define cross-type dependencies via polymorphic relationships to `Task`. Add `blockedBy()` and `blocks()` methods returning the mixed-type dependency collections. Add a `transitionStatus(string $newStatus): void` method that enforces the allowed transitions (draft->open, open->closed, closed->open) and throws a domain exception if the transition is invalid (CL19). Add a local scope `filter(array $filters)` that applies type, nature, statut, priorite, tags, and text search (q) filters.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-29, POIESIS-28

---

### POIESIS-31

- **Title:** Implement the Task model
- **Description:** Create `app/Core/Models/Task.php`. Use `HasUuids` and `HasArtifactIdentifier`. Set `$fillable = ['project_id', 'story_id', 'titre', 'description', 'type', 'nature', 'statut', 'priorite', 'ordre', 'estimation_temps', 'tags']`. Cast `tags` to `array`. Define relationships: `belongsTo(Project::class)`, `belongsTo(Story::class)->nullable()`, `morphOne(Artifact::class, 'artifactable')`. Add `blockedBy()` and `blocks()` polymorphic many-to-many relationships via `item_dependencies` (same pattern as Story). Add `transitionStatus(string $newStatus): void` with identical logic to Story (same allowed transitions). Add `isStandalone(): bool` returning `$this->story_id === null`. Add a local scope `filter(array $filters)` for type, nature, statut, priorite, tags, and text search.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-30, POIESIS-28

---

### POIESIS-32

- **Title:** Implement the Artifact model
- **Description:** Create `app/Core/Models/Artifact.php`. Use `HasUuids`. Set `$fillable = ['project_id', 'identifier', 'sequence_number', 'artifactable_id', 'artifactable_type']`. Define: `belongsTo(Project::class)`, `morphTo('artifactable')`. Add a static `resolveIdentifier(string $identifier): ?Model` method that looks up an artifact by its identifier string and eager-loads the `artifactable` polymorphic relation, returning the underlying Epic, Story, or Task. Add a static `searchInProject(Project $project, string $keyword): Collection` method that searches titles and descriptions across epics, stories, and tasks — this can be done via separate queries with UNION or via individual queries merged in PHP. Since the polymorphic pattern makes raw SQL UNIONs complex, prefer three separate Eloquent queries filtered by keyword and merged.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-29

---

### POIESIS-33

- **Title:** Implement the ItemDependency service (circular dependency detection)
- **Description:** Create `app/Core/Services/DependencyService.php`. This service handles the business logic for the `item_dependencies` table. Methods required: `addDependency(Model $blockedItem, Model $blockingItem): void` — inserts a row in `item_dependencies` after validating (1) the items are not the same, (2) the dependency does not already exist, (3) no circular dependency would result (CL20). `removeDependency(Model $blockedItem, Model $blockingItem): void` — deletes the row. `getDependencies(Model $item): array` — returns `['blocked_by' => [...], 'blocks' => [...]]`. Circular dependency detection must be recursive: given that A is blocked by B, check that B is not (directly or transitively) blocked by A. Use a depth-first traversal capped at a reasonable depth (e.g., 50 hops) to prevent infinite loops on malformed data.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 8
- **Blocked by:** POIESIS-30, POIESIS-31

---

### POIESIS-34

- **Title:** Add Dev seeder with fixture data for all core models
- **Description:** Populate `database/seeders/DevSeeder.php` with realistic fixture data: 2 projects (`DEMO` and `TEST`), each with 1 owner User and 1 member User, 3 epics per project, 5 stories per epic (varied types, natures, statuses, and priorities), 3 tasks per story (some standalone), artifacts assigned to all epics/stories/tasks, and 2 dependencies between stories. Use Laravel factories. Create `ProjectFactory`, `UserFactory`, `EpicFactory`, `StoryFactory`, `TaskFactory` in `database/factories/`. Factories must use `HasUuids` and the `HasArtifactIdentifier` trait will trigger automatically on creation. Run `php artisan db:seed --class=DevSeeder` and verify no errors.
- **Type:** backend
- **Nature:** chore
- **Priority:** medium
- **Points:** 5
- **Blocked by:** POIESIS-23, POIESIS-24, POIESIS-25, POIESIS-29, POIESIS-30, POIESIS-31, POIESIS-32

---

## Epic E4 — Authentication: Static Tokens and OAuth2

**Scope:** Implement the two authentication modes: static token (Bearer) and OAuth2 Authorization Code with PKCE. This includes the `AuthenticateBearer` middleware, the OAuth2 authorization flow endpoints, token exchange, refresh token handling, dynamic client registration, and token revocation. No third-party OAuth2 library — implement from scratch using the models from E2/E3 to maintain full control and alignment with the architecture.

**Goal:** An AI agent can authenticate with a static Bearer token and have requests authorized. An interactive client (Claude Desktop) can complete the full OAuth2 PKCE flow and receive a working access token.

---

### POIESIS-35

- **Title:** Implement the AuthenticateBearer middleware
- **Description:** Create `app/Core/Http/Middleware/AuthenticateBearer.php`. This middleware is the sole authentication gate for all MCP requests. Logic: (1) Extract the `Authorization: Bearer {token}` header from the request. If absent, return `401 Unauthorized`. (2) Hash the raw token with SHA-256: `hash('sha256', $rawToken)`. (3) Look up the hash in `api_tokens` — if found and not expired, resolve the associated User, call `$apiToken->recordUsage()` (updates `last_used_at`), bind the user to the request via `Auth::setUser($user)`. (4) If not found in `api_tokens`, look up in `oauth_access_tokens` — if found and not expired, resolve the associated User and bind. (5) If found in neither table, or token is expired, return `401`. Register in `app/Core/Providers/CoreServiceProvider.php` and alias as `auth:bearer`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-26, POIESIS-27

---

### POIESIS-36

- **Title:** Implement the EnsureProjectAccess middleware
- **Description:** Create `app/Core/Http/Middleware/EnsureProjectAccess.php`. This middleware runs after `AuthenticateBearer` on all routes that include a `{code}` project parameter. Logic: (1) Resolve the project from the `code` route parameter using `Project::where('code', $code)->firstOrFail()`. (2) Check that `$request->user()` is a member of this project via `project_members`. (3) If not a member, return `403 Forbidden` (CL17). (4) Inject the resolved project into the request attributes (`$request->attributes->set('project', $project)`) so controllers can access it without re-querying. Register and alias as `project.access`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-35

---

### POIESIS-37

- **Title:** Implement the EnsureModuleActive middleware
- **Description:** Create `app/Core/Http/Middleware/EnsureModuleActive.php`. This middleware accepts a module slug parameter (e.g., `module:sprint`). Logic: (1) Retrieve the project from `$request->attributes->get('project')` (set by `EnsureProjectAccess`). (2) Check that the module slug is present in `$project->modules`. (3) If not present, return `404` with message: `"Module '{slug}' is not active for this project."` (CL11). Register and alias as `module.active`.
- **Type:** backend
- **Nature:** feature
- **Priority:** high
- **Points:** 2
- **Blocked by:** POIESIS-36

---

### POIESIS-38

- **Title:** Implement the OAuth2 well-known metadata endpoint
- **Description:** Create `app/Core/Http/Controllers/OAuthController.php` with a `metadata()` action. This action returns the RFC 8414 authorization server metadata as JSON: `issuer` (app URL), `authorization_endpoint` (`/oauth/authorize`), `token_endpoint` (`/oauth/token`), `registration_endpoint` (`/oauth/register`), `revocation_endpoint` (`/oauth/revoke`), `scopes_supported` (from `config('core.oauth_scopes')`), `response_types_supported` (`['code']`), `grant_types_supported` (`['authorization_code', 'refresh_token']`), `code_challenge_methods_supported` (`['S256']`). Route: `GET /.well-known/oauth-authorization-server` — no authentication required on this endpoint. Register in a dedicated `routes/oauth.php` file loaded by `CoreServiceProvider`.
- **Type:** backend
- **Nature:** feature
- **Priority:** high
- **Points:** 2
- **Blocked by:** POIESIS-35

---

### POIESIS-39

- **Title:** Implement dynamic OAuth2 client registration (RFC 7591)
- **Description:** Add `register()` action to `OAuthController`. Route: `POST /oauth/register`. No authentication required. Accepts: `client_name` (required), `redirect_uris` (required, array), `grant_types` (optional, default `['authorization_code']`), `scope` (optional string). Validates that `redirect_uris` is a non-empty array of valid URLs. Creates an `OAuthClient` record with a generated `client_id` (UUID v7) and no `client_secret` (public client). Returns the client registration response per RFC 7591: `client_id`, `client_name`, `redirect_uris`, `grant_types`. The `user_id` on the client is null until the client completes an authorization flow. The consent screen (CL18) will display the redirect URI so the user can verify it.
- **Type:** backend
- **Nature:** feature
- **Priority:** high
- **Points:** 3
- **Blocked by:** POIESIS-27, POIESIS-38

---

### POIESIS-40

- **Title:** Implement the OAuth2 authorization endpoint (consent screen)
- **Description:** Add `authorize()` action to `OAuthController`. Route: `GET /oauth/authorize`. No Bearer authentication — this is a user-facing endpoint. Parameters: `client_id` (required), `redirect_uri` (required), `response_type` (must be `code`), `code_challenge` (required, PKCE), `code_challenge_method` (must be `S256`), `scope` (optional), `state` (optional, opaque). Logic: (1) Validate `client_id` exists in `oauth_clients` and `redirect_uri` matches one of its registered URIs. (2) Validate PKCE parameters. (3) Return a simple HTML consent page showing the client name, the redirect URI, and the requested scopes with Approve/Deny buttons. (4) On approval (POST to same endpoint), generate an authorization code (random 32-byte hex string, hashed with SHA-256 for storage), create an `OAuthAuthorizationCode` record with `expires_at = now() + 10 minutes`, then redirect to `redirect_uri?code={raw_code}&state={state}`. (5) On denial, redirect to `redirect_uri?error=access_denied&state={state}`. Since no user login exists (users are AI agents managed via Artisan), the consent screen should accept a static user identifier (e.g., `user_id` query param for local dev) or be designed to work with Artisan-created users.
- **Type:** backend
- **Nature:** feature
- **Priority:** high
- **Points:** 8
- **Blocked by:** POIESIS-39

---

### POIESIS-41

- **Title:** Implement the OAuth2 token endpoint (code exchange and refresh)
- **Description:** Add `token()` action to `OAuthController`. Route: `POST /oauth/token`. No Bearer authentication. Handles two grant types: `authorization_code` and `refresh_token`. For `authorization_code`: (1) Validate `client_id`, `code`, `redirect_uri`, `code_verifier` (PKCE). (2) Retrieve the `OAuthAuthorizationCode`, verify it is not expired, verify that `SHA-256(code_verifier) == code_challenge` (S256 method). (3) Generate a raw access token (`aa-` prefix + 40 hex chars), hash it, create `OAuthAccessToken` with `expires_at = now() + config('core.oauth_access_token_ttl') minutes`. (4) Generate a raw refresh token, hash it, create `OAuthRefreshToken` with `expires_at = now() + config('core.oauth_refresh_token_ttl') minutes`. (5) Delete the used authorization code. (6) Return `access_token`, `token_type: bearer`, `expires_in`, `refresh_token`. For `refresh_token`: (1) Validate `client_id` and `refresh_token`. (2) Retrieve the token, verify it is not expired or revoked. (3) Issue a new access token (and optionally a new refresh token). (4) Mark the old access token as expired or delete it.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 8
- **Blocked by:** POIESIS-40

---

### POIESIS-42

- **Title:** Implement the OAuth2 token revocation endpoint
- **Description:** Add `revoke()` action to `OAuthController`. Route: `POST /oauth/revoke`. No Bearer authentication (per RFC 7009, revocation does not require auth). Accepts: `token` (required), `token_type_hint` (optional: `access_token` or `refresh_token`). Logic: (1) Hash the raw token. (2) Look up in `oauth_access_tokens` — if found, delete it (cascades to refresh tokens). (3) If not found there, look up in `oauth_refresh_tokens` — if found, set `revoked = true` (or delete). (4) Always return `200 OK` even if the token is not found (RFC 7009 requirement). This endpoint also serves as the mechanism for F8 (revoke static token) via the Artisan command `token:revoke`, which calls `ApiToken::destroy($id)` directly.
- **Type:** backend
- **Nature:** feature
- **Priority:** high
- **Points:** 3
- **Blocked by:** POIESIS-41

---

### POIESIS-43

- **Title:** Implement static token generation service
- **Description:** Create `app/Core/Services/TokenService.php`. This service centralizes token lifecycle management for static API tokens. Methods: `generate(User $user, string $name, ?Carbon $expiresAt): array` — creates an `ApiToken` record and returns `['token' => $rawToken, 'model' => $apiToken]`. The raw token is never stored; only the SHA-256 hash is persisted. The raw token is returned once for display to the user. `revoke(string $tokenId): void` — deletes the `ApiToken` by ID. `listForUser(User $user): Collection` — returns all tokens for the user (without the raw token values). This service is used by both the Artisan CLI commands and the MCP user management tools.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-26

---

### POIESIS-44

- **Title:** Write authentication integration tests
- **Description:** Using Pest PHP, write integration tests for the authentication layer. Test cases: (1) Valid static token in header authenticates successfully. (2) Expired static token returns 401. (3) Missing Authorization header returns 401. (4) Invalid/unknown token returns 401. (5) Valid OAuth2 access token authenticates successfully. (6) Expired OAuth2 access token returns 401. (7) Project access: authenticated user with membership passes `EnsureProjectAccess`. (8) Authenticated user without membership is rejected with 403. (9) `EnsureModuleActive` passes when module is active. (10) `EnsureModuleActive` fails with 404 when module is inactive. Tests should use `RefreshDatabase` and in-memory SQLite if available, or MariaDB test database.
- **Type:** qa
- **Nature:** feature
- **Priority:** high
- **Points:** 5
- **Blocked by:** POIESIS-35, POIESIS-36, POIESIS-37, POIESIS-41

---

### POIESIS-45

- **Title:** Implement PKCE verification unit tests
- **Description:** Write unit tests (Pest PHP) specifically covering the PKCE flow: (1) Valid S256 code verifier produces matching code challenge. (2) Invalid code verifier is rejected at the token endpoint. (3) Missing `code_challenge` in the authorization request is rejected. (4) `code_challenge_method` other than `S256` is rejected. (5) Replayed authorization code (used twice) is rejected. (6) Expired authorization code is rejected. (7) Mismatched `redirect_uri` in token exchange is rejected. These are pure logic tests that can run without a database.
- **Type:** qa
- **Nature:** feature
- **Priority:** high
- **Points:** 3
- **Blocked by:** POIESIS-41

---

### POIESIS-46

- **Title:** Implement token expiry and last_used_at update integration test
- **Description:** Write integration tests verifying: (1) `last_used_at` is updated on each successful request using a static token. (2) A token with `expires_at` in the past is rejected with 401. (3) A token with `expires_at` in the future is accepted. (4) A token with `expires_at = null` (permanent) is always accepted. (5) A revoked token (deleted from `api_tokens`) is rejected. These tests require database interaction and should use the `RefreshDatabase` trait.
- **Type:** qa
- **Nature:** feature
- **Priority:** medium
- **Points:** 2
- **Blocked by:** POIESIS-44

---

## Epic E5 — Internal REST API Layer

**Scope:** Implement the internal REST API controllers, form request validators, and API resources for all Core entities. While the architecture specifies that MCP tools call Eloquent directly (not via HTTP), the REST API layer defines the controllers and business logic that MCP tools will share — it acts as a structured service layer. All controllers live in `app/Core/Http/Controllers/`. Routes are registered in `app/Core/Routes/api.php`. All routes are under the `/api/v1` prefix and require `auth:bearer` middleware.

**Goal:** All 56 functional requirements (F1–F56) have a corresponding controller action and form request with validation. API resources format responses consistently. Edge cases from the specification are handled and tested.

---

### POIESIS-47

- **Title:** Implement ProjectController (CRUD + list)
- **Description:** Create `app/Core/Http/Controllers/ProjectController.php`. Actions: `index()` — returns paginated list of projects accessible by the authenticated user (using `accessibleBy()` scope), `store()` — validates via `StoreProjectRequest`, creates project and adds the creator as owner via `project_members`, `show()` — returns project details with active modules, `update()` — validates via `UpdateProjectRequest`, only `titre` and `description` are updatable (R2), `destroy()` — validates the user is an owner (R6), cascades to all children. Create `StoreProjectRequest` with rules: `code` (required, regex `/^[A-Za-z0-9\-]{2,25}$/`, unique in `projects`), `titre` (required, string, max 255), `description` (nullable, string). Create `UpdateProjectRequest` with rules: `titre` (sometimes, string, max 255), `description` (nullable, string). Create `ProjectResource` returning: `code`, `titre`, `description`, `modules`, `created_at`. Enforce CL12 (invalid code format) and CL13 (duplicate code) in validation.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-23, POIESIS-36

---

### POIESIS-48

- **Title:** Implement EpicController (CRUD + list)
- **Description:** Create `app/Core/Http/Controllers/EpicController.php`. Routes are nested under `/api/v1/projects/{code}/epics`. The project is resolved and access-checked by `EnsureProjectAccess`. Actions: `index()` — returns paginated list of epics for the project, `store()` — validates via `StoreEpicRequest`, creates the epic (artifact assigned automatically), `show()` — returns epic with `stories_count` and identifier, `update()` — validates via `UpdateEpicRequest`, `destroy()` — cascades to stories and their tasks (DB cascade). Create `StoreEpicRequest`: `titre` (required, max 255), `description` (nullable). Create `UpdateEpicRequest`: `titre` (sometimes, max 255), `description` (nullable). Create `EpicResource` returning: `identifier`, `titre`, `description`, `stories_count`, `created_at`. The `{identifier}` route parameter must resolve via the `artifacts` table, not by UUID.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-29, POIESIS-36

---

### POIESIS-49

- **Title:** Implement identifier resolution helper for route model binding
- **Description:** Create a custom route binding in `CoreServiceProvider` that resolves `{identifier}` parameters (e.g., `AGENTMG-3`) to their underlying Eloquent model. When a route includes `{identifier}`, the binding should: (1) Look up the `Artifact` by its `identifier` column. (2) Load the `artifactable` polymorphic relation. (3) Return the underlying model (Epic, Story, or Task). (4) If the artifact does not exist, throw `ModelNotFoundException` (returns 404). This binding is scoped: an epic identifier in a story route must belong to the correct project. Implement as a custom route model binding in `CoreServiceProvider::boot()` using `Route::bind('identifier', ...)`. This avoids complex middleware logic and keeps controllers clean.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-32

---

### POIESIS-50

- **Title:** Implement StoryController — create, read, update, delete
- **Description:** Create `app/Core/Http/Controllers/StoryController.php`. Actions: `store()` (nested under epic) — validates via `StoreStoryRequest`, creates story with artifact, `show()` — returns full story resource including dependencies and task count, `update()` — validates via `UpdateStoryRequest`, `destroy()` — cascades to child tasks. `StoreStoryRequest` rules: `titre` (required, max 255), `type` (required, `Rule::in(config('core.item_types'))`), `description` (nullable), `nature` (nullable, `Rule::in(config('core.work_natures'))`), `priorite` (nullable, `Rule::in(config('core.priorities'))`, default `config('core.default_priority')`), `ordre` (nullable, integer, min 0), `story_points` (nullable, integer, min 0), `reference_doc` (nullable, url, max 2048), `tags` (nullable, array), `tags.*` (string). `UpdateStoryRequest`: all fields are `sometimes`. The `type` and `epic_id` are not updatable after creation. `StoryResource`: `identifier`, `titre`, `description`, `type`, `nature`, `statut`, `priorite`, `ordre`, `story_points`, `reference_doc`, `tags`, `epic` (identifier only), `tasks_count`, `blocked_by` (array of identifiers), `blocks` (array of identifiers), `created_at`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 8
- **Blocked by:** POIESIS-30, POIESIS-48, POIESIS-49

---

### POIESIS-51

- **Title:** Implement StoryController — list, filter, and pagination
- **Description:** Add `index()` action to `StoryController` for `GET /api/v1/projects/{code}/stories` (all stories in project, across all epics) and a separate `indexByEpic()` action for `GET /api/v1/projects/{code}/epics/{identifier}/stories`. Both support filtering via query params: `type`, `nature`, `statut`, `priorite`, `tags` (comma-separated or array), `q` (text search on `titre` and `description` using `LIKE %keyword%` in MariaDB). Both return paginated results with `meta` object. The `filter()` scope on the `Story` model handles all filter logic. Page size defaults to 25, maximum 100 (CL15: if page is beyond results, return empty `data` with correct `meta`).
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-50

---

### POIESIS-52

- **Title:** Implement story status transition endpoint
- **Description:** Add `transition()` action to `StoryController`. Route: `PATCH /api/v1/projects/{code}/stories/{identifier}/status`. Accepts: `statut` (required, string). Logic: (1) Retrieve the story. (2) Call `$story->transitionStatus($newStatus)`. (3) This method enforces allowed transitions (draft->open, open->closed, closed->open). If the transition is invalid (e.g., open->draft, CL19), return `422` with a descriptive error message. (4) Save the story. (5) Return the updated `StoryResource`. This endpoint covers F47.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-51

---

### POIESIS-53

- **Title:** Implement TaskController — create standalone and child tasks
- **Description:** Create `app/Core/Http/Controllers/TaskController.php`. Two creation routes: `POST /api/v1/projects/{code}/tasks` (standalone, `story_id = null`, `project_id` set from route) and `POST /api/v1/projects/{code}/stories/{identifier}/tasks` (child task, `story_id` set from the story's UUID, `project_id` inferred). `StoreTaskRequest` rules: `titre` (required, max 255), `type` (required, `Rule::in(config('core.item_types'))`), `description` (nullable), `nature` (nullable, `Rule::in(config('core.work_natures'))`), `priorite` (nullable, `Rule::in(config('core.priorities'))`), `ordre` (nullable, integer, min 0), `estimation_temps` (nullable, integer, min 0 — minutes), `tags` (nullable, array). After creation, `story_id` and `project_id` are immutable (R13, CL10). Create `TaskResource`: `identifier`, `titre`, `description`, `type`, `nature`, `statut`, `priorite`, `ordre`, `estimation_temps`, `tags`, `story` (identifier or null), `project` (code), `blocked_by`, `blocks`, `created_at`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-31, POIESIS-49

---

### POIESIS-54

- **Title:** Implement TaskController — read, update, delete, list, filter, status transition
- **Description:** Add remaining actions to `TaskController`. `show()`: returns full `TaskResource`. `update()`: validates via `UpdateTaskRequest` (all fields `sometimes`; `story_id` and `project_id` not updatable). `destroy()`: deletes task and its artifact; dependencies pointing to this task are cascade-deleted at DB level (CL23). `index()` (`GET /api/v1/projects/{code}/tasks`): all tasks in project (standalone + story children), filterable, paginated. `indexByStory()` (`GET /api/v1/projects/{code}/stories/{identifier}/tasks`): tasks of a story, filterable, paginated. `transition()` (`PATCH /api/v1/projects/{code}/tasks/{identifier}/status`): same logic as story status transition (F48).
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-53

---

### POIESIS-55

- **Title:** Implement DependencyController (add, remove, list)
- **Description:** Create `app/Core/Http/Controllers/DependencyController.php`. Routes: `POST /api/v1/dependencies` (add dependency, F51), `DELETE /api/v1/dependencies` (remove dependency, F52), `GET /api/v1/artifacts/{identifier}/dependencies` (list dependencies, F53). For add/remove, the request body contains `blocked_identifier` and `blocking_identifier`. The controller resolves both via the `Artifact` model, then delegates to `DependencyService`. Validations: both identifiers must exist, must belong to the same project, must not be the same item (self-dependency), no circular dependency (CL20), no non-existent item (CL21). Returns the updated dependency list `{ blocked_by: [...], blocks: [...] }` with artifact identifiers.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-33, POIESIS-49

---

### POIESIS-56

- **Title:** Implement ArtifactController (resolve and search)
- **Description:** Create `app/Core/Http/Controllers/ArtifactController.php`. Two actions: `resolve()` (`GET /api/v1/artifacts/{identifier}`) — resolves an identifier to its underlying entity and returns the appropriate Resource (EpicResource, StoryResource, or TaskResource). `search()` (`GET /api/v1/projects/{code}/artifacts?q=keyword`) — searches titles and descriptions across all epics, stories, and tasks of the project. The search uses `LIKE %keyword%` on `titre` and `description` columns. Returns a flat list of mixed-type results, each including `identifier`, `type` (epic/story/task), and `titre`. Pagination applies. If `q` is empty or absent, return a 422 error requiring the `q` parameter.
- **Type:** backend
- **Nature:** feature
- **Priority:** high
- **Points:** 3
- **Blocked by:** POIESIS-32, POIESIS-49

---

### POIESIS-57

- **Title:** Implement ModuleController (list available, list active, activate, deactivate)
- **Description:** Create `app/Core/Http/Controllers/ModuleController.php`. Routes: `GET /api/v1/modules` — lists all modules registered in the `ModuleRegistry` (F40), `GET /api/v1/projects/{code}/modules` — lists active modules for the project (F43), `POST /api/v1/projects/{code}/modules` — activates a module for the project (F41, owner only), `DELETE /api/v1/projects/{code}/modules/{slug}` — deactivates a module (F42, owner only). Activation logic: (1) Validate the module slug exists in the registry. (2) Check that the module is not already active (return 422 if so). (3) Check that all module dependencies are already active for this project (CL2). (4) Add the slug to `$project->modules` and save. Deactivation logic: (1) Check no other active modules depend on this one (CL3). (2) Remove the slug from `$project->modules` and save. Data for deactivated modules is retained in the DB (CL16).
- **Type:** backend
- **Nature:** feature
- **Priority:** high
- **Points:** 5
- **Blocked by:** POIESIS-47, POIESIS-37

---

### POIESIS-58

- **Title:** Implement ConfigController (expose business values)
- **Description:** Create `app/Core/Http/Controllers/ConfigController.php`. Single action: `index()` (`GET /api/v1/config`). Returns all configurable business values from `config/core.php`: `item_types`, `priorities`, `statuts`, `work_natures`, `project_roles`, `oauth_scopes`. No authentication required — this endpoint is informational and safe to be public. Format: `{ "item_types": [...], "priorities": [...], "statuts": [...], "work_natures": [...], "project_roles": [...], "oauth_scopes": [...] }`. This covers F44.
- **Type:** backend
- **Nature:** feature
- **Priority:** medium
- **Points:** 1
- **Blocked by:** POIESIS-4, POIESIS-7

---

### POIESIS-59

- **Title:** Implement ProjectMemberController (add, remove, update role, list)
- **Description:** Create `app/Core/Http/Controllers/ProjectMemberController.php`. Routes under `/api/v1/projects/{code}/members`. Actions: `index()` — list members with roles (F16), `store()` — add a member (F13, owner only, validate user exists, validate not already a member R12, validate role is valid), `update()` — change a member's role (F15, owner only, refuse if last owner downgrading themselves CL1), `destroy()` — remove a member (F14, owner only, refuse if last owner CL1). The minimum check for CL1 uses `ProjectMember::isLastOwner()`. Return `{ id, user: { id, name }, role, created_at }` for each member.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-25, POIESIS-47

---

### POIESIS-60

- **Title:** Implement batch story creation endpoint
- **Description:** Add `batchStore()` action to `StoryController`. Route: `POST /api/v1/projects/{code}/epics/{identifier}/stories/batch`. Accepts: `{ "stories": [ { ...story_data }, ... ] }`. Logic: (1) Validate each story object individually using the same rules as `StoreStoryRequest` (CL22: error reports index of the invalid item). (2) Wrap all insertions in a single database transaction. (3) Artifact identifiers are assigned sequentially within the transaction (the `FOR UPDATE` lock in `HasArtifactIdentifier` guarantees no conflicts). (4) If any story fails validation, return 422 with `{ "index": N, "field": "...", "message": "..." }` and no stories are created (R18). (5) On success, return `201` with the array of created `StoryResource` objects. This covers F54.
- **Type:** backend
- **Nature:** feature
- **Priority:** high
- **Points:** 5
- **Blocked by:** POIESIS-50, POIESIS-28

---

### POIESIS-61

- **Title:** Implement batch task creation endpoint
- **Description:** Add `batchStore()` action to `TaskController`. Route: `POST /api/v1/projects/{code}/tasks/batch` (standalone) and `POST /api/v1/projects/{code}/stories/{identifier}/tasks/batch` (children). Same logic and atomicity guarantees as POIESIS-60. Accepts: `{ "tasks": [ { ...task_data }, ... ] }`. Validation errors include the index of the invalid item. All-or-nothing transaction. Returns array of created `TaskResource` objects. This covers F55.
- **Type:** backend
- **Nature:** feature
- **Priority:** high
- **Points:** 5
- **Blocked by:** POIESIS-53, POIESIS-60

---

### POIESIS-62

- **Title:** Write REST API integration tests for projects and members
- **Description:** Using Pest PHP with `RefreshDatabase`, write integration tests covering: (1) Create project — success with valid data. (2) Create project — fail with duplicate code. (3) Create project — fail with invalid code format (CL12). (4) Create project — creator becomes owner automatically. (5) List projects — user only sees their own projects (CL17). (6) Update project — code cannot be changed (R2). (7) Delete project — only owner can delete (R6). (8) Add member — success. (9) Add member — duplicate rejected (R12). (10) Remove last owner — rejected (CL1). (11) Downgrade last owner — rejected (CL1).
- **Type:** qa
- **Nature:** feature
- **Priority:** high
- **Points:** 5
- **Blocked by:** POIESIS-47, POIESIS-59

---

### POIESIS-63

- **Title:** Write REST API integration tests for epics, stories, and tasks
- **Description:** Using Pest PHP with `RefreshDatabase`, write integration tests covering: (1) Create epic — identifier assigned automatically (F37). (2) Delete epic — cascades to stories and tasks (CL8). (3) Create story — all fields validated, default status is draft. (4) Update story — type is immutable. (5) Filter stories — each filter param works. (6) Story status transition — valid transitions accepted. (7) Story status transition — invalid transition rejected (CL19). (8) Delete story — cascades to tasks (CL9). (9) Create standalone task. (10) Create child task — `story_id` immutable (R13, CL10). (11) Batch story creation — atomic on error (CL22, R18). (12) Dependency creation — circular dependency rejected (CL20). (13) Delete item with dependencies — orphan dependencies cleaned up (CL23).
- **Type:** qa
- **Nature:** feature
- **Priority:** high
- **Points:** 8
- **Blocked by:** POIESIS-52, POIESIS-55, POIESIS-60, POIESIS-61

---

### POIESIS-64

- **Title:** Write REST API integration tests for modules and configuration
- **Description:** Write integration tests for: (1) List available modules — returns all registered modules. (2) Activate module — success when dependencies satisfied. (3) Activate module — fail when dependencies missing (CL2). (4) Activate already active module — rejected. (5) Deactivate module — success. (6) Deactivate module with dependents — rejected (CL3). (7) Deactivate module — data retained, not deleted (CL16). (8) Access disabled module endpoint — 404 with message (CL11). (9) Config endpoint — returns all business values. (10) Pagination beyond last page — empty list with correct meta (CL15).
- **Type:** qa
- **Nature:** feature
- **Priority:** medium
- **Points:** 5
- **Blocked by:** POIESIS-57, POIESIS-58

---

## Epic E6 — MCP Server Integration

**Scope:** Implement the MCP server as described in the architecture: a central `McpServer` registry, a `McpTransport` for Streamable HTTP (POST for JSON-RPC, GET for SSE), a `McpController` with two routes, and all Core MCP tools (`ProjectTools`, `EpicTools`, `StoryTools`, `TaskTools`, `ArtifactTools`, `ModuleTools`) and resources (`ProjectOverviewResource`, `ProjectConfigResource`). MCP tools call Eloquent models directly — not via internal HTTP.

**Goal:** A client configured with the MCP server URL and a valid Bearer token can call `tools/list` and see all Core tools, then call any tool and receive correct results.

---

### POIESIS-65

- **Title:** Define MCP contracts: McpToolInterface and McpResourceInterface
- **Description:** Create `app/Core/Mcp/Contracts/McpToolInterface.php` and `app/Core/Mcp/Contracts/McpResourceInterface.php`. `McpToolInterface`: method `tools(): array` returns the tool definitions (name, description, inputSchema as JSON Schema array), method `execute(string $toolName, array $params, User $user): mixed` executes the tool and returns a result. `McpResourceInterface`: method `uri(): string` returns the resource URI pattern (e.g., `project://{code}/overview`), method `read(array $params, User $user): array` returns the resource content. Both interfaces are in the `App\Core\Mcp\Contracts` namespace. Bind both interfaces in `CoreServiceProvider`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 2
- **Blocked by:** POIESIS-7

---

### POIESIS-66

- **Title:** Implement the McpServer central registry
- **Description:** Create `app/Core/Mcp/Server/McpServer.php`. This class is a singleton registered in the container by `CoreServiceProvider`. Properties: `$coreTools` (array of `McpToolInterface`), `$coreResources` (array of `McpResourceInterface`), `$moduleTools` (injected by the module system after boot). Methods: `registerCoreTools(McpToolInterface $tools): void` — adds to `$coreTools`. `registerCoreResource(McpResourceInterface $resource): void` — adds to `$coreResources`. `handleRequest(array $jsonRpc, User $user): array` — dispatches to `handleInitialize`, `handleToolsList`, `handleToolsCall`, `handleResourcesList`, `handleResourcesRead` based on `$jsonRpc['method']`. `resolveTools(?Project $project): array` — returns all tools (Core + module tools active for the project). `handleToolsCall(string $toolName, array $params, User $user): array` — finds the tool provider, checks module activation, executes, wraps result in MCP response format.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 8
- **Blocked by:** POIESIS-65

---

### POIESIS-67

- **Title:** Implement the McpTransport (Streamable HTTP)
- **Description:** Create `app/Core/Mcp/Server/McpTransport.php`. This class handles encoding and decoding of the Streamable HTTP transport. Methods: `decodeRequest(Request $request): array` — parses the JSON-RPC payload from the POST body, validates it has `jsonrpc: "2.0"`, `method`, and optionally `id` and `params`. `encodeResponse(mixed $result, ?int $id): array` — wraps the result in a JSON-RPC 2.0 success response: `{ "jsonrpc": "2.0", "id": N, "result": ... }`. `encodeError(string $code, string $message, ?int $id): array` — wraps an error in JSON-RPC 2.0 error format. `streamResponse(Response $response): Response` — for `GET /mcp` SSE streaming, sets the response to `text/event-stream`, `Cache-Control: no-cache`, `Connection: keep-alive`. For this initial implementation, SSE streaming can send a single event then close — full streaming support can be enhanced later.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-66

---

### POIESIS-68

- **Title:** Implement the McpController and MCP routes
- **Description:** Create `app/Core/Mcp/Http/Controllers/McpController.php`. Two actions: `handle(Request $request): JsonResponse` — handles `POST /mcp`: decodes the JSON-RPC request via `McpTransport`, resolves the authenticated user via `$request->user()`, dispatches to `McpServer::handleRequest()`, encodes the response via `McpTransport`. `stream(Request $request): Response` — handles `GET /mcp`: returns an SSE response with a `ping` event to confirm the connection is alive. Create `app/Core/Routes/mcp.php` with: `Route::post('/mcp', [McpController::class, 'handle'])->middleware('auth:bearer')` and `Route::get('/mcp', [McpController::class, 'stream'])->middleware('auth:bearer')`. Load these routes in `CoreServiceProvider` (not in the standard `api.php` bootstrap, as they are at the root path level).
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-67, POIESIS-35

---

### POIESIS-69

- **Title:** Implement ProjectTools MCP tool provider
- **Description:** Create `app/Core/Mcp/Tools/ProjectTools.php` implementing `McpToolInterface`. Tools exposed: `list_projects`, `get_project`, `create_project`, `update_project`, `delete_project`. Each tool has a JSON Schema `inputSchema`. The `execute()` method dispatches on `$toolName` and calls Eloquent directly (not via HTTP). `list_projects`: returns projects accessible by `$user`. `get_project`: requires `project_code`, returns project details with active modules. `create_project`: requires `code`, `titre`; optional `description`; creates project, adds user as owner; enforces CL12 and CL13 by catching unique constraint violations. `update_project`: requires `project_code`; optional `titre`, `description`; enforces owner-only (R6). `delete_project`: requires `project_code`; owner only. All tools return structured arrays that the `McpServer` wraps in MCP response format. Register in `CoreServiceProvider` via `McpServer::registerCoreTools()`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-68, POIESIS-47

---

### POIESIS-70

- **Title:** Implement EpicTools MCP tool provider
- **Description:** Create `app/Core/Mcp/Tools/EpicTools.php`. Tools: `list_epics` (requires `project_code`, supports pagination params), `get_epic` (requires `identifier`), `create_epic` (requires `project_code`, `titre`; optional `description`), `update_epic` (requires `identifier`; optional `titre`, `description`), `delete_epic` (requires `identifier`). All tools validate project access via the DependencyService or model directly. Artifact identifiers are used in parameters and responses, not UUIDs. Register in `CoreServiceProvider`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-69, POIESIS-48

---

### POIESIS-71

- **Title:** Implement StoryTools MCP tool provider
- **Description:** Create `app/Core/Mcp/Tools/StoryTools.php`. Tools: `list_stories` (requires `project_code`, supports filter params: type, nature, statut, priorite, tags, q, plus pagination), `list_epic_stories` (requires `project_code`, `epic_identifier`, pagination), `get_story` (requires `identifier`), `create_story` (requires `project_code`, `epic_identifier`, `titre`, `type`; optional all other story fields), `create_stories` (batch — requires `project_code`, `epic_identifier`, `stories: [...]`; atomic), `update_story` (requires `identifier`; optional updatable fields — type and epic immutable), `delete_story` (requires `identifier`), `update_story_status` (requires `identifier`, `statut`; enforces transition rules). Register in `CoreServiceProvider`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 8
- **Blocked by:** POIESIS-70, POIESIS-50, POIESIS-51, POIESIS-52, POIESIS-60

---

### POIESIS-72

- **Title:** Implement TaskTools MCP tool provider
- **Description:** Create `app/Core/Mcp/Tools/TaskTools.php`. Tools: `list_tasks` (requires `project_code`, filter params, pagination), `list_story_tasks` (requires `project_code`, `story_identifier`, pagination), `get_task` (requires `identifier`), `create_task` (requires `project_code`, `titre`, `type`; optional `story_identifier` — if provided, creates a child task; if not, creates standalone), `create_tasks` (batch — atomic, all-or-nothing), `update_task` (requires `identifier`; updatable fields only — `story_id` and `project_id` immutable), `delete_task` (requires `identifier`), `update_task_status` (requires `identifier`, `statut`; same transition rules as story). Register in `CoreServiceProvider`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 8
- **Blocked by:** POIESIS-71, POIESIS-53, POIESIS-54, POIESIS-61

---

### POIESIS-73

- **Title:** Implement ArtifactTools and ModuleTools MCP tool providers
- **Description:** Create `app/Core/Mcp/Tools/ArtifactTools.php`: tools `resolve_artifact` (requires `identifier`, returns the complete entity), `search_artifacts` (requires `project_code`, `q`, optional pagination). Create `app/Core/Mcp/Tools/ModuleTools.php`: tools `list_available_modules`, `list_project_modules` (requires `project_code`), `activate_module` (requires `project_code`, `slug`; owner only; dependency check), `deactivate_module` (requires `project_code`, `slug`; owner only; dependent module check). Also add dependency tools to a `DependencyTools.php`: `add_dependency` (requires `blocked_identifier`, `blocking_identifier`), `remove_dependency` (requires both identifiers), `list_dependencies` (requires `identifier`). Register all in `CoreServiceProvider`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-72, POIESIS-55, POIESIS-56, POIESIS-57

---

### POIESIS-74

- **Title:** Implement Core MCP Resources (ProjectOverview, ProjectConfig)
- **Description:** Create `app/Core/Mcp/Resources/ProjectOverviewResource.php` implementing `McpResourceInterface`. URI: `project://{code}/overview`. The `read()` method returns: `{ project_code, titre, epics_count, stories_count, tasks_count, active_modules }`. This is the initial context summary for an AI agent (F45). Create `app/Core/Mcp/Resources/ProjectConfigResource.php`. URI: `project://{code}/config`. Returns the same as `GET /api/v1/config` plus project-specific info: active modules list with their slugs and descriptions. The `McpServer::handleResourcesList()` returns both resources. The `handleResourcesRead()` dispatches based on the URI pattern. Register both in `CoreServiceProvider` via `McpServer::registerCoreResource()`.
- **Type:** backend
- **Nature:** feature
- **Priority:** high
- **Points:** 3
- **Blocked by:** POIESIS-68, POIESIS-58

---

### POIESIS-75

- **Title:** Implement MCP initialize handshake
- **Description:** Implement the `initialize` method handler in `McpServer::handleInitialize()`. When an MCP client sends `{ "method": "initialize", "params": { "protocolVersion": "...", "capabilities": {...}, "clientInfo": {...} } }`, the server must respond with its own capabilities: `{ "protocolVersion": "2024-11-05", "capabilities": { "tools": {}, "resources": {} }, "serverInfo": { "name": "Poiesis", "version": "1.0.0" } }`. The protocol version must match the client's. If the client sends an unsupported version, return a JSON-RPC error. Add the `serverInfo` version to `config/app.php` or a new `config/poiesis.php`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 2
- **Blocked by:** POIESIS-68

---

### POIESIS-76

- **Title:** Write MCP integration tests for all Core tools
- **Description:** Using Pest PHP, write integration tests that POST JSON-RPC requests to `POST /mcp` with a valid Bearer token in the header. Test `tools/list` — returns all Core tools. Test `tools/call` for each tool: `list_projects`, `create_project`, `get_project`, `update_project`, `delete_project`, `list_epics`, `create_epic`, `create_story`, `list_stories` with filters, `create_task`, `create_stories` batch (success and atomic failure), `resolve_artifact`, `search_artifacts`, `activate_module`, `add_dependency` (including circular dependency rejection). Verify JSON-RPC response format is correct. Verify `resources/list` and `resources/read` for both resources. Verify `initialize` handshake.
- **Type:** qa
- **Nature:** feature
- **Priority:** high
- **Points:** 13
- **Blocked by:** POIESIS-73, POIESIS-74, POIESIS-75

---

## Epic E7 — Module System

**Scope:** Implement the module system infrastructure: the `ModuleInterface` contract, the `ModuleRegistry` singleton, the `ModuleServiceProvider`, a `config/modules.php` registry, and a skeleton first-party module (`ExampleModule`) that demonstrates the pattern. This epic does not implement any actual feature modules (sprint, kanban, etc.) — it establishes the extensibility framework.

**Goal:** A developer can create a new module by implementing `ModuleInterface`, registering it in `config/modules.php`, and having its MCP tools automatically available in the `McpServer` for projects where the module is active.

---

### POIESIS-77

- **Title:** Define the ModuleInterface contract
- **Description:** Create `app/Core/Contracts/ModuleInterface.php`. Methods: `slug(): string` (unique identifier, e.g., `sprint`), `name(): string` (display name), `description(): string`, `dependencies(): array` (slugs of required modules), `registerRoutes(): void` (register module-specific API routes), `registerListeners(): void` (register Eloquent model observers and event listeners), `migrationPath(): string` (path to the module's migrations directory, empty string if none), `mcpTools(): array` (array of `McpToolInterface` instances). This interface is the sole contract that all modules — whether local or Composer packages — must implement.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 2
- **Blocked by:** POIESIS-65

---

### POIESIS-78

- **Title:** Implement the ModuleRegistry singleton
- **Description:** Create `app/Core/Module/ModuleRegistry.php`. This is a singleton (registered in the container by `ModuleServiceProvider`). Methods: `register(ModuleInterface $module): void` — adds the module to the registry by its slug. `get(string $slug): ?ModuleInterface` — retrieves a module by slug. `all(): array` — returns all registered modules. `isRegistered(string $slug): bool`. `getDependenciesFor(string $slug): array` — returns the required dependency slugs. The registry is the central catalog of all installed modules. It is populated at boot time by the `ModuleServiceProvider` and used by `ModuleController`, `McpServer`, and `EnsureModuleActive` middleware.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-77

---

### POIESIS-79

- **Title:** Implement the ModuleServiceProvider
- **Description:** Create `app/Core/Providers/ModuleServiceProvider.php`. Register it in `bootstrap/providers.php` (after `CoreServiceProvider`). Boot logic: (1) Singleton-bind `ModuleRegistry` in the container. (2) Read `config/modules.php` which maps module slugs to their class names: `return ['example' => \App\Modules\Example\ExampleModule::class]`. (3) Instantiate each module class and call `$registry->register($module)`. (4) After all modules are registered, call `$module->registerRoutes()` and `$module->registerListeners()` for each. (5) Inject the module tool providers into the `McpServer`: for each module, call `McpServer::registerModuleTools($module->slug(), $module->mcpTools())`. (6) Run migrations for each module that has a non-empty `migrationPath()` using `$migrator->path($module->migrationPath())`.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 5
- **Blocked by:** POIESIS-78, POIESIS-66

---

### POIESIS-80

- **Title:** Create the ExampleModule skeleton
- **Description:** Create `app/Modules/Example/ExampleModule.php` implementing `ModuleInterface`. `slug()` returns `'example'`. `name()` returns `'Example Module'`. `description()` returns `'A skeleton module for demonstration'`. `dependencies()` returns `[]`. `registerRoutes()` registers an empty route group. `registerListeners()` does nothing. `migrationPath()` returns `''`. `mcpTools()` returns a single tool provider `ExampleTools`. Create `app/Modules/Example/Mcp/ExampleTools.php` implementing `McpToolInterface` with a single tool `ping` that accepts no parameters and returns `{ "message": "pong" }`. Register `ExampleModule` in `config/modules.php`. Write a test that calls `tools/call` with `ping` for a project that has `example` active and verifies the response.
- **Type:** backend
- **Nature:** spike
- **Priority:** medium
- **Points:** 3
- **Blocked by:** POIESIS-79

---

### POIESIS-81

- **Title:** Implement module activation validation with dependency resolution
- **Description:** Refine the `ModuleController::store()` action (and the corresponding `activate_module` MCP tool) to use the `ModuleRegistry` for dependency validation. When activating module `B` which depends on `A`: (1) Retrieve `$registry->getDependenciesFor('B')` — returns `['A']`. (2) Check that all dependency slugs are present in `$project->modules`. (3) If any dependency is missing, return 422: `{ "message": "Module 'B' requires module 'A' to be active first." }` (CL2). Similarly, when deactivating module `A`: (1) Find all registered modules that declare `A` in their `dependencies()`. (2) Check if any of those are currently active in `$project->modules`. (3) If yes, return 422: `{ "message": "Cannot deactivate 'A'. The following modules depend on it: ['B']." }` (CL3).
- **Type:** backend
- **Nature:** improvement
- **Priority:** high
- **Points:** 3
- **Blocked by:** POIESIS-79, POIESIS-57

---

### POIESIS-82

- **Title:** Implement EnsureModuleActive in McpServer for module tools
- **Description:** Modify `McpServer::handleToolsCall()` to check module activation before executing module tools (not Core tools). The `McpServer` must know which tools belong to which module. Extend `registerModuleTools(string $moduleSlug, array $toolProviders): void` to record the module slug alongside each tool provider. In `handleToolsCall()`, when the tool belongs to a module: (1) Extract the `project_code` from the tool params (this is required for all module tools that operate on a project). (2) Retrieve the project. (3) Check that the module slug is in `$project->modules`. (4) If not, return MCP error: `{ "code": -32000, "message": "Module 'sprint' is not active for project 'PROJ'." }` (CL11). Core tools are never blocked by this check.
- **Type:** backend
- **Nature:** feature
- **Priority:** high
- **Points:** 3
- **Blocked by:** POIESIS-80, POIESIS-66

---

### POIESIS-83

- **Title:** Write module system unit and integration tests
- **Description:** Unit tests: (1) `ModuleRegistry::register()` adds a module. (2) `ModuleRegistry::get()` retrieves it. (3) `ModuleRegistry::all()` returns all. (4) Duplicate slug registration throws or overwrites (define behavior). Integration tests: (1) Activating a module with satisfied dependencies — success. (2) Activating a module with missing dependency — 422 (CL2). (3) Deactivating a module with active dependents — 422 (CL3). (4) `ExampleModule` `ping` tool — works when module is active. (5) `ping` tool — returns MCP error when module is inactive (CL11). (6) Module data retained after deactivation (CL16 — query module's table directly to verify data exists).
- **Type:** qa
- **Nature:** feature
- **Priority:** medium
- **Points:** 5
- **Blocked by:** POIESIS-80, POIESIS-81, POIESIS-82

---

## Epic E8 — Artisan CLI Commands

**Scope:** Implement all Artisan commands for user management, token management, and project membership management. These commands are the sole human administration interface for the platform. Commands live in `app/Core/Console/Commands/`. They are registered in `CoreServiceProvider`.

**Goal:** A server administrator can create users, generate tokens, manage project memberships, and revoke access entirely via `php artisan` commands, without touching the database directly.

---

### POIESIS-84

- **Title:** Implement user:create Artisan command
- **Description:** Create `app/Core/Console/Commands/UserCreateCommand.php`. Signature: `user:create {name}`. Logic: (1) Create a `User` with the given name. (2) Display the user ID. (3) Ask `"Generate a token now? [yes/no]"` — default: yes. (4) If yes, ask for `"Token name: [default]"`. (5) Call `TokenService::generate($user, $name, null)` to create a permanent token. (6) Display the raw token in a bordered box with a warning: `"This token will never be shown again. Store it in a secure location."`. (7) Display the user ID and name in a summary table. The raw token must be shown exactly once and never again.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-43

---

### POIESIS-85

- **Title:** Implement user:list Artisan command
- **Description:** Create `app/Core/Console/Commands/UserListCommand.php`. Signature: `user:list`. Displays a formatted table with columns: `ID`, `Name`, `Tokens`, `Projects`, `Created At`. Fetches all users with `withCount(['apiTokens', 'projects'])`. Uses `$this->table()` for clean output.
- **Type:** backend
- **Nature:** feature
- **Priority:** high
- **Points:** 1
- **Blocked by:** POIESIS-24

---

### POIESIS-86

- **Title:** Implement user:update Artisan command
- **Description:** Create `app/Core/Console/Commands/UserUpdateCommand.php`. Signature: `user:update {name} {--name= : New name}`. Finds the user by current name. If `--name` option is provided, updates the user's name. Asks for confirmation before applying. Displays success or error message.
- **Type:** backend
- **Nature:** feature
- **Priority:** medium
- **Points:** 1
- **Blocked by:** POIESIS-24

---

### POIESIS-87

- **Title:** Implement user:delete Artisan command
- **Description:** Create `app/Core/Console/Commands/UserDeleteCommand.php`. Signature: `user:delete {name}`. Logic: (1) Find the user by name. (2) Display a warning listing the number of tokens and project memberships that will be affected. (3) Ask for explicit confirmation: `"Are you sure you want to delete user '{name}'? This action cannot be undone. [yes/no]"` — default: no. (4) On confirmation, delete the user — cascades: `api_tokens` deleted (ON DELETE CASCADE), `project_members` deleted (ON DELETE CASCADE), `oauth_clients` deleted (if any), `oauth_access_tokens` and `oauth_refresh_tokens` deleted via cascade. (5) Display success message.
- **Type:** backend
- **Nature:** feature
- **Priority:** medium
- **Points:** 2
- **Blocked by:** POIESIS-24

---

### POIESIS-88

- **Title:** Implement token:create Artisan command
- **Description:** Create `app/Core/Console/Commands/TokenCreateCommand.php`. Signature: `token:create {user} {--name=default} {--expires= : Duration like 30d, 6h, or never}`. Logic: (1) Find the user by name. (2) Parse the `--expires` option — supported formats: `Nd` (N days), `Nh` (N hours), `never` (null). Invalid format returns error. (3) Call `TokenService::generate($user, $name, $expiresAt)`. (4) Display the raw token in a bordered box with the token name, expiration date (or `never`), and the one-time warning. Implement the duration parser as a private method in the command or a separate utility class.
- **Type:** backend
- **Nature:** feature
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-43

---

### POIESIS-89

- **Title:** Implement token:list Artisan command
- **Description:** Create `app/Core/Console/Commands/TokenListCommand.php`. Signature: `token:list {user}`. Finds the user by name, fetches all their `api_tokens`. Displays a table: `ID`, `Name`, `Created At`, `Expires At` (or `never`), `Last Used At` (or `never`). Raw token values are never shown (only the hash is stored).
- **Type:** backend
- **Nature:** feature
- **Priority:** high
- **Points:** 1
- **Blocked by:** POIESIS-26

---

### POIESIS-90

- **Title:** Implement token:revoke Artisan command
- **Description:** Create `app/Core/Console/Commands/TokenRevokeCommand.php`. Signature: `token:revoke {token_id}`. Logic: (1) Find the `ApiToken` by UUID. (2) Display the token name and associated user for confirmation. (3) Ask: `"Revoke this token? [yes/no]"` — default: no. (4) On confirmation, call `TokenService::revoke($tokenId)`. (5) Display success. In-flight MCP requests using this token will fail on their next authentication check.
- **Type:** backend
- **Nature:** feature
- **Priority:** high
- **Points:** 2
- **Blocked by:** POIESIS-43

---

### POIESIS-91

- **Title:** Implement project:members Artisan command
- **Description:** Create `app/Core/Console/Commands/ProjectMembersCommand.php`. Signature: `project:members {code}`. Finds the project by code. Displays a table of members: `User ID`, `Name`, `Role`, `Member Since`. Uses eager loading to avoid N+1 queries.
- **Type:** backend
- **Nature:** feature
- **Priority:** medium
- **Points:** 1
- **Blocked by:** POIESIS-25

---

### POIESIS-92

- **Title:** Implement project:add-member Artisan command
- **Description:** Create `app/Core/Console/Commands/ProjectAddMemberCommand.php`. Signature: `project:add-member {code} {user} {--role=member}`. Logic: (1) Find the project by code, find the user by name. (2) Validate the role is in `config('core.project_roles')`. (3) Check the user is not already a member (R12). (4) Create the `ProjectMember` record. (5) Display success. The `--role` option defaults to `config('core.default_project_role')`.
- **Type:** backend
- **Nature:** feature
- **Priority:** medium
- **Points:** 2
- **Blocked by:** POIESIS-25

---

### POIESIS-93

- **Title:** Implement project:remove-member Artisan command
- **Description:** Create `app/Core/Console/Commands/ProjectRemoveMemberCommand.php`. Signature: `project:remove-member {code} {user}`. Logic: (1) Find the project and user. (2) Check the user is a member. (3) If the user is the last owner, refuse with error: `"Cannot remove the last owner of a project. Promote another member to owner first."` (R1, CL1). (4) Ask for confirmation. (5) Delete the `ProjectMember` record.
- **Type:** backend
- **Nature:** feature
- **Priority:** medium
- **Points:** 2
- **Blocked by:** POIESIS-25, POIESIS-91

---

### POIESIS-94

- **Title:** Write Artisan command feature tests
- **Description:** Using Pest PHP's `$this->artisan()` helper, write feature tests for all Artisan commands. Test: (1) `user:create` — user created, token displayed, token hash stored (not raw). (2) `user:list` — output contains all users. (3) `user:delete` — user and tokens deleted. (4) `token:create` — token created with correct expiration. (5) `token:create --expires=30d` — expires_at set correctly. (6) `token:create --expires=invalid` — error returned. (7) `token:revoke` — token deleted. (8) `token:list` — shows all tokens (no raw values). (9) `project:members` — shows correct members. (10) `project:add-member` — member added. (11) `project:add-member` — duplicate rejected. (12) `project:remove-member` — member removed. (13) `project:remove-member` — last owner rejected (CL1).
- **Type:** qa
- **Nature:** feature
- **Priority:** high
- **Points:** 5
- **Blocked by:** POIESIS-84, POIESIS-85, POIESIS-86, POIESIS-87, POIESIS-88, POIESIS-89, POIESIS-90, POIESIS-91, POIESIS-92, POIESIS-93

---

## Epic E9 — Testing and QA

**Scope:** Establish the full testing infrastructure and ensure comprehensive coverage of all functional requirements and edge cases. This includes the Pest PHP test framework setup, a global test suite covering all 56 functional requirements and 23 edge cases from the specifications, performance/load considerations for concurrent artifact creation, and a mutation testing run.

**Goal:** The test suite runs clean (`php artisan test` exits 0), achieves above 85% code coverage, and validates all specified edge cases.

---

### POIESIS-95

- **Title:** Set up Pest PHP testing framework with coverage configuration
- **Description:** Install `pestphp/pest`, `pestphp/pest-plugin-laravel`, and `pestphp/pest-plugin-faker` as dev dependencies. Run `pest --init` to generate `tests/Pest.php`. Configure `phpunit.xml` (or `pest.php`) to use a dedicated test database (`DB_DATABASE=poiesis_test` in `phpunit.xml` env section). Add a Composer script: `"test": "pest"`, `"test:coverage": "pest --coverage --min=85"`. Ensure tests run with `RefreshDatabase` by default. Configure `phpunit.xml` with `BCRYPT_ROUNDS=4` and `CACHE_DRIVER=array` for speed.
- **Type:** devops
- **Nature:** chore
- **Priority:** high
- **Points:** 2
- **Blocked by:** POIESIS-7

---

### POIESIS-96

- **Title:** Write edge case tests for all 23 specification edge cases
- **Description:** Create `tests/Feature/EdgeCases/` directory. Write one test per edge case class. CL1: last owner removal rejected. CL2: module activation with missing dependency rejected. CL3: module deactivation with dependents rejected. CL4: concurrent artifact creation produces unique identifiers (use parallel jobs or a transaction test). CL5: expired token rejected. CL6: OAuth2 refresh with valid refresh token — new access token issued. CL7: OAuth2 refresh with expired refresh token — 401. CL8: cascade deletion epic → stories → tasks. CL9: module data cleaned by observer on story deletion. CL10: standalone task cannot be re-linked to a story. CL11: disabled module endpoint returns 404. CL12: invalid project code format rejected. CL13: duplicate project code rejected. CL14: invalid type/priority value rejected. CL15: pagination beyond results returns empty with correct meta. CL16: module data retained after deactivation. CL17: user without project access gets 403. CL18: dynamic client registration with any redirect URI accepted (consent screen shows URI). CL19: invalid status transition rejected. CL20: circular dependency rejected. CL21: dependency on non-existent item rejected. CL22: batch creation with invalid item — zero items created, error includes index. CL23: deleting blocking item removes orphan dependencies.
- **Type:** qa
- **Nature:** feature
- **Priority:** critical
- **Points:** 13
- **Blocked by:** POIESIS-76, POIESIS-83, POIESIS-94

---

### POIESIS-97

- **Title:** Write unit tests for all business rule validations
- **Description:** Create `tests/Unit/` directory. Write unit tests for: (1) `ProjectMember::isLastOwner()` — correct in all cases. (2) `Story::transitionStatus()` — all valid and invalid transitions. (3) `Task::transitionStatus()` — same. (4) `DependencyService::addDependency()` — self-dependency rejected, duplicate rejected, circular rejected. (5) `HasArtifactIdentifier` — identifier format matches `{CODE}-{N}`. (6) Project code validation regex. (7) Token expiry check in `ApiToken::isExpired()`. (8) OAuth2 token expiry. (9) Module dependency resolution in `ModuleRegistry`. (10) Config values accessible from `config('core.*')`. Unit tests should use mocks/fakes where possible and avoid database interaction.
- **Type:** qa
- **Nature:** feature
- **Priority:** high
- **Points:** 8
- **Blocked by:** POIESIS-25, POIESIS-30, POIESIS-31, POIESIS-33

---

### POIESIS-98

- **Title:** Write concurrency test for artifact identifier uniqueness (CL4)
- **Description:** Create `tests/Feature/Concurrency/ArtifactConcurrencyTest.php`. This test simulates concurrent artifact creation to verify the atomicity guarantee (CL4). Implementation approach: (1) Use Laravel's `DB::transaction()` with `SELECT ... FOR UPDATE` already in `HasArtifactIdentifier`. (2) Simulate concurrent creation by running 10 sequential rapid `Story::create()` calls in a loop within a single test (MariaDB single-connection test). (3) Assert that all 10 stories received unique identifiers with no gaps. (4) For a stronger test, use Laravel's `Concurrency` facade (PHP 8.4 fibers) or process forking if available in the CI environment. Document the limitation: true multi-process concurrency testing requires external tooling (Apache Bench, k6) and should be done in the staging environment.
- **Type:** qa
- **Nature:** spike
- **Priority:** medium
- **Points:** 5
- **Blocked by:** POIESIS-28

---

### POIESIS-99

- **Title:** Run PHPStan analysis and resolve all issues
- **Description:** Run `composer analyse` (PHPStan at level 8). Resolve all reported issues in the codebase. Common issues to expect: missing return types, untyped properties, potential null dereferences, incorrect generic types in Eloquent relations. This is a maintenance story that runs after all implementation epics. The CI pipeline must pass PHPStan with zero errors. Document any intentional `@phpstan-ignore` annotations with justification.
- **Type:** qa
- **Nature:** improvement
- **Priority:** high
- **Points:** 5
- **Blocked by:** POIESIS-76, POIESIS-83, POIESIS-94

---

### POIESIS-100

- **Title:** Run Laravel Pint and enforce code style across the codebase
- **Description:** Run `composer lint` across the full codebase after all implementation is complete. Apply all fixes. Verify that `composer lint:check` exits 0. This ensures consistent code formatting before the first release. Document any intentional Pint rule overrides in `pint.json`.
- **Type:** qa
- **Nature:** chore
- **Priority:** medium
- **Points:** 1
- **Blocked by:** POIESIS-99

---

### POIESIS-101

- **Title:** Generate API documentation via PHPDoc comments
- **Description:** Ensure all public methods in controllers, models, services, and MCP tool providers have accurate PHPDoc blocks: `@param`, `@return`, `@throws`. This documentation serves as the reference for AI agents implementing new modules. Run `composer analyse` one final time to confirm PHPDoc accuracy. Create a `docs/mcp-tools.md` file listing all MCP tools, their parameters (with types and whether required or optional), and their return format. This document is the primary reference for AI agents connecting to the platform.
- **Type:** qa
- **Nature:** chore
- **Priority:** medium
- **Points:** 5
- **Blocked by:** POIESIS-100

---

## Epic E10 — DevOps and Deployment

**Scope:** Establish the CI pipeline, production deployment configuration, and operational tooling. This includes a GitHub Actions workflow for automated testing and static analysis, a production `.env` template, server configuration guidance (Nginx + PHP-FPM), and a deployment checklist. No Redis, no Docker Compose for production (unless DDEV-based).

**Goal:** Pushing to `main` triggers CI that runs tests, PHPStan, and Pint. A documented deployment procedure allows the application to be deployed to a production server with HTTPS, running behind Nginx, with the MCP endpoint accessible at `/mcp`.

---

### POIESIS-102

- **Title:** Set up GitHub Actions CI pipeline
- **Description:** Create `.github/workflows/ci.yml`. Jobs: (1) `lint` — runs `composer lint:check` (Pint). (2) `analyse` — runs `composer analyse` (PHPStan level 8). (3) `test` — sets up PHP 8.4, installs MariaDB 11.6 service, copies `.env.example` to `.env`, generates `APP_KEY`, runs `composer install`, runs `php artisan migrate --force`, runs `composer test`. Trigger: on push to any branch and on pull requests to `main`. Cache `vendor/` between runs using the `actions/cache` action. Use `shivammathur/setup-php` action for PHP 8.4. The MariaDB service in GitHub Actions uses the official `mariadb:11.6` Docker image.
- **Type:** devops
- **Nature:** chore
- **Priority:** high
- **Points:** 5
- **Blocked by:** POIESIS-95

---

### POIESIS-103

- **Title:** Create production environment configuration template
- **Description:** Create `.env.production.example` with production-ready settings: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain.com`, `LOG_CHANNEL=stack`, `LOG_DEPRECATIONS_CHANNEL=null`, `LOG_LEVEL=error`, `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT=3306`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `CACHE_STORE=database` (no Redis), `SESSION_DRIVER=database` (no Redis), `QUEUE_CONNECTION=database` (no Redis), `OAUTH_ACCESS_TOKEN_TTL=60`, `OAUTH_REFRESH_TOKEN_TTL=43200`, `MCP_ENDPOINT_PATH=/mcp`. Document that Redis is intentionally excluded. Add a note about the `CACHE_STORE=database` choice and its performance implications for high-traffic deployments.
- **Type:** devops
- **Nature:** chore
- **Priority:** high
- **Points:** 2
- **Blocked by:** POIESIS-3

---

### POIESIS-104

- **Title:** Create Nginx configuration for the MCP endpoint
- **Description:** Create `docs/nginx.conf.example` with a production Nginx server block for Poiesis. Requirements: HTTPS with SSL termination, `server_name your-domain.com`, `root /var/www/poiesis/public`, `index index.php`, PHP-FPM pass-through for `.php` files. The `/mcp` endpoint must support both POST (JSON-RPC) and GET (SSE). For SSE (`GET /mcp`), add: `proxy_buffering off`, `proxy_cache off`, `X-Accel-Buffering: no` header. For large JSON payloads (batch operations): `client_max_body_size 10m`. Add HTTPS redirect from HTTP. Add security headers: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`. Document the SSE-specific Nginx settings in a comment block.
- **Type:** devops
- **Nature:** chore
- **Priority:** medium
- **Points:** 3
- **Blocked by:** POIESIS-68

---

### POIESIS-105

- **Title:** Create database migrations for cache and sessions (no Redis)
- **Description:** Since Redis is excluded, run `php artisan cache:table` and `php artisan session:table` to generate the `cache` and `sessions` table migrations. Also run `php artisan queue:table` and `php artisan queue:failed-table` for database-backed queues. Apply these migrations. Add `CACHE_STORE=database` and `SESSION_DRIVER=database` and `QUEUE_CONNECTION=database` to `.env.example`. This ensures the application works in production without Redis. Note: the MCP server does not use sessions (it is stateless via Bearer tokens), so session storage is only for the OAuth2 consent screen.
- **Type:** devops
- **Nature:** chore
- **Priority:** medium
- **Points:** 2
- **Blocked by:** POIESIS-8

---

### POIESIS-106

- **Title:** Create deployment checklist and runbook
- **Description:** Create `docs/deployment.md` with a step-by-step deployment checklist: (1) Server requirements: PHP 8.4, MariaDB 11.6, Nginx, Composer. (2) Clone the repository. (3) Copy `.env.production.example` to `.env` and fill in values. (4) Run `composer install --no-dev --optimize-autoloader`. (5) Run `php artisan key:generate`. (6) Run `php artisan migrate --force`. (7) Run `php artisan config:cache && php artisan route:cache && php artisan view:cache`. (8) Set file permissions: `chmod -R 755 storage bootstrap/cache`. (9) Configure Nginx (reference `docs/nginx.conf.example`). (10) Create the first user and token: `php artisan user:create "Admin"`. (11) Test the MCP endpoint: `curl -X POST https://your-domain.com/mcp -H "Authorization: Bearer ..." -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","method":"initialize","id":1,"params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}'`.
- **Type:** devops
- **Nature:** chore
- **Priority:** medium
- **Points:** 3
- **Blocked by:** POIESIS-104, POIESIS-105

---

### POIESIS-107

- **Title:** Add DDEV custom commands for local development workflow
- **Description:** Create DDEV custom commands in `.ddev/commands/web/`: `artisan` — wraps `php artisan $@` so developers can run `ddev artisan migrate`. `test` — wraps `php artisan test`. `analyse` — wraps `composer analyse`. `lint` — wraps `composer lint`. Also create `.ddev/commands/host/open` to open the DDEV URL in the browser. Document all custom commands in `README.md`. Ensure `ddev describe` shows the MCP endpoint URL alongside the standard web URL.
- **Type:** devops
- **Nature:** chore
- **Priority:** medium
- **Points:** 2
- **Blocked by:** POIESIS-8

---

### POIESIS-108

- **Title:** Add Laravel Telescope for local debugging (dev only)
- **Description:** Install `laravel/telescope` as a dev dependency. Configure it to run only in `local` and `testing` environments (guard in `TelescopeServiceProvider`: `if (! app()->isLocal()) { return; }`). Enable the following watchers: `RequestWatcher`, `QueryWatcher`, `LogWatcher`, `CommandWatcher`. The Query watcher is especially useful for detecting N+1 queries in MCP tool implementations. Add `TELESCOPE_ENABLED=true` to `.env.example`. Exclude Telescope from production by adding its service provider to the `dont-discover` list in `composer.json` and manually registering in `AppServiceProvider` only in local/testing.
- **Type:** devops
- **Nature:** chore
- **Priority:** low
- **Points:** 2
- **Blocked by:** POIESIS-7

---

### POIESIS-109

- **Title:** Final end-to-end smoke test with Claude Code as MCP client
- **Description:** This is a manual verification story. Using Claude Code configured with the local DDEV MCP server URL and a valid static Bearer token (created via `ddev artisan user:create`), verify the following sequence: (1) Call `initialize` — handshake succeeds. (2) Call `tools/list` — all Core tools are listed. (3) Call `create_project` — project created with identifier. (4) Call `create_epic` — epic created with identifier `{CODE}-1`. (5) Call `create_stories` (batch) — 3 stories created with identifiers `{CODE}-2`, `{CODE}-3`, `{CODE}-4`. (6) Call `resolve_artifact` with `{CODE}-3` — returns the correct story. (7) Call `add_dependency` — dependency created. (8) Call `list_dependencies` — dependency visible. (9) Call `update_story_status` to `open` — success. (10) Call `update_story_status` to `draft` — fails with correct error. Document the results in a `docs/smoke-test.md` file. Mark the story as done only when all 10 steps pass.
- **Type:** qa
- **Nature:** chore
- **Priority:** critical
- **Points:** 3
- **Blocked by:** POIESIS-76, POIESIS-94, POIESIS-106

---

## Story Count Summary

| Epic | Stories | Points (approx.) |
|------|---------|-------------------|
| E1 — Scaffolding and DDEV | POIESIS-1 to POIESIS-8 (8 stories) | 17 |
| E2 — Database Migrations | POIESIS-9 to POIESIS-22 (14 stories) | 23 |
| E3 — Core Models | POIESIS-23 to POIESIS-34 (12 stories) | 47 |
| E4 — Authentication | POIESIS-35 to POIESIS-46 (12 stories) | 47 |
| E5 — REST API Layer | POIESIS-47 to POIESIS-64 (18 stories) | 79 |
| E6 — MCP Server | POIESIS-65 to POIESIS-76 (12 stories) | 65 |
| E7 — Module System | POIESIS-77 to POIESIS-83 (7 stories) | 24 |
| E8 — Artisan CLI | POIESIS-84 to POIESIS-94 (11 stories) | 23 |
| E9 — Testing and QA | POIESIS-95 to POIESIS-101 (7 stories) | 39 |
| E10 — DevOps and Deployment | POIESIS-102 to POIESIS-109 (8 stories) | 22 |
| **Total** | **109 stories** | **~386 points** |

---

## Dependency Graph (Critical Path)

The following represents the high-level dependency chain for the critical path from project initialization to first working MCP connection:

```
POIESIS-1 (Laravel init)
  └─ POIESIS-2 (DDEV)
       └─ POIESIS-3 (ENV vars)
            └─ POIESIS-8 (DB hooks)

POIESIS-1
  └─ POIESIS-4 (config/core.php)
       └─ POIESIS-7 (CoreServiceProvider)
            └─ POIESIS-9..21 (Migrations)
                 └─ POIESIS-22 (Migration verification)
                      └─ POIESIS-23..32 (Models)
                           └─ POIESIS-28 (HasArtifactIdentifier)
                                └─ POIESIS-33 (DependencyService)
                                     └─ POIESIS-35 (AuthenticateBearer)
                                          └─ POIESIS-36 (EnsureProjectAccess)
                                               └─ POIESIS-47..64 (REST API)
                                                    └─ POIESIS-65..76 (MCP Server)
                                                         └─ POIESIS-109 (Smoke test)
```

---

## Notes for Implementors

1. **UUID v7:** Use Laravel's built-in `HasUuids` trait on all models. Laravel 11+ generates UUID v7 by default when `HasUuids` is present. Verify with `\Illuminate\Support\Str::orderedUuid()`.

2. **No Redis:** The entire application runs on MariaDB 11.6 and the filesystem. Cache, sessions, and queues all use the `database` driver. Do not introduce Redis as a dependency under any circumstance.

3. **French field names in the database:** The architecture specifies French field names in the DB schema (`titre`, `statut`, `priorite`, `ordre`). This is by design and must be preserved exactly as documented. Do not translate these to English.

4. **Artifact identifier atomicity:** The `SELECT ... FOR UPDATE` lock in `HasArtifactIdentifier` is essential for correctness under concurrent load. Do not simplify this to a plain `MAX()` query without the lock.

5. **MCP tools call Eloquent directly:** Do not route MCP tool calls through the internal REST API HTTP layer. Tools must call Eloquent models and services directly. The REST API layer and the MCP tool layer share the same business logic via services and models, not via HTTP.

6. **Config-driven validation:** All `type`, `nature`, `priorite`, and `role` validations must use `Rule::in(config('core.item_types'))` patterns — never hardcoded arrays in validators, never PHP enums, never DB enum columns.

7. **Cascade deletion ordering:** Deleting a project must cascade: Project → Epics → Stories → Tasks → Artifacts → Dependencies. The DB-level ON DELETE CASCADE on foreign keys handles most of this. Verify the cascade chain in POIESIS-22.

8. **Testing database:** Always use a separate test database (not the DDEV development database) to prevent test runs from destroying development data. Configure via `phpunit.xml` environment variables.
