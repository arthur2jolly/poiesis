# Specifications Document — Core Architecture V2

## Poiesis — The Endless Coding

### Modular agile project management platform for AI agents

---
  
## 1. Philosophy

The Core represents the **fundamental building blocks of agility**: the atomic entities upon which any agile process can be built. The Core contains no workflow logic, no processes, no methodology (Scrum, Kanban, SAFe...). It is purely **structural**.

**Guiding principles:**
- The Core defines **what** we do. Modules define **how** we organize to do it.
- **The MCP server is the sole entry point.** No human edits content directly. All interaction goes through AI agents via the MCP protocol.
- The REST API is **internal**: it serves as the persistence layer between the MCP server and the database. It is never publicly exposed.
- Platform configuration (business values, modules) is the only element editable outside of MCP.

---

## 2. Core Entities

### 2.1 Project

Root entity. Container for all work.

| Field | Type | Constraints |
|-------|------|-------------|
| id | uuid (v7) | PK |
| code | varchar(25) | UNIQUE, NOT NULL, immutable, 2-25 chars [A-Za-z0-9-] |
| titre | varchar(255) | NOT NULL |
| description | text | NULLABLE |
| modules | json | NOT NULL, default: `[]` |
| created_at | timestamp | auto |
| updated_at | timestamp | auto |

**Rules:**
- `code` is the business identifier, used in URLs and artifact identifiers
- `code` is immutable after creation
- `modules` contains the list of modules activated for this project (e.g.: `["sprint", "comments", "timer"]`)
- Route model binding by `code`

**Core Relations:**
- hasMany(Epic)
- hasMany(Task) — standalone tasks (without a story)
- hasMany(Artifact)

---

### 2.2 User

Identity of an AI agent or an operator authorized to interact with the platform via MCP. No link to assignment or workflow roles.

| Field | Type | Constraints |
|-------|------|-------------|
| id | uuid (v7) | PK |
| name | varchar(255) | NOT NULL |
| created_at | timestamp | auto |
| updated_at | timestamp | auto |

**Core Relations:**
- belongsToMany(Project) via pivot table `project_members`
- hasMany(ApiToken) via table `api_tokens`

**Table `api_tokens`:**

| Field | Type | Constraints |
|-------|------|-------------|
| id | uuid (v7) | PK |
| user_id | uuid | FK → users.id, CASCADE |
| name | varchar(255) | NOT NULL |
| token | varchar(255) | UNIQUE, NOT NULL (SHA-256 hash) |
| expires_at | timestamp | NULLABLE (null = never) |
| last_used_at | timestamp | NULLABLE |
| created_at | timestamp | auto |

**Token rules:**
- A User can have **multiple tokens** (one per agent, one per environment, etc.)
- The raw token is displayed only once at creation; only the hash is stored in the DB
- A token can have an expiration date (`expires_at`) or be permanent
- `last_used_at` is updated on each use to track activity
- A revoked token = deleted from the table

**Table `oauth_clients`:**

| Field | Type | Constraints |
|-------|------|-------------|
| id | uuid (v7) | PK |
| user_id | uuid | FK → users.id, NULLABLE, CASCADE |
| name | varchar(255) | NOT NULL |
| client_id | varchar(255) | UNIQUE, NOT NULL |
| client_secret | varchar(255) | NULLABLE (public clients don't have one) |
| redirect_uris | json | NOT NULL |
| grant_types | json | NOT NULL, default: `["authorization_code"]` |
| scopes | json | NULLABLE |
| created_at | timestamp | auto |
| updated_at | timestamp | auto |

**Table `oauth_authorization_codes`:**

| Field | Type | Constraints |
|-------|------|-------------|
| id | uuid (v7) | PK |
| oauth_client_id | uuid | FK → oauth_clients.id, CASCADE |
| user_id | uuid | FK → users.id, CASCADE |
| code | varchar(255) | UNIQUE, NOT NULL |
| redirect_uri | varchar(2048) | NOT NULL |
| scopes | json | NULLABLE |
| code_challenge | varchar(255) | NULLABLE (PKCE) |
| code_challenge_method | varchar(10) | NULLABLE (S256) |
| expires_at | timestamp | NOT NULL |
| created_at | timestamp | auto |

**Table `oauth_access_tokens`:**

| Field | Type | Constraints |
|-------|------|-------------|
| id | uuid (v7) | PK |
| oauth_client_id | uuid | FK → oauth_clients.id, CASCADE |
| user_id | uuid | FK → users.id, CASCADE |
| token | varchar(255) | UNIQUE, NOT NULL (SHA-256 hash) |
| scopes | json | NULLABLE |
| expires_at | timestamp | NOT NULL |
| created_at | timestamp | auto |

**Table `oauth_refresh_tokens`:**

| Field | Type | Constraints |
|-------|------|-------------|
| id | uuid (v7) | PK |
| access_token_id | uuid | FK → oauth_access_tokens.id, CASCADE |
| token | varchar(255) | UNIQUE, NOT NULL (SHA-256 hash) |
| expires_at | timestamp | NOT NULL |
| revoked | boolean | NOT NULL, default: false |
| created_at | timestamp | auto |

**Pivot table `project_members`:**

| Field | Type | Constraints |
|-------|------|-------------|
| id | uuid (v7) | PK |
| project_id | uuid | FK → projects.id, CASCADE |
| user_id | uuid | FK → users.id, CASCADE |
| role | varchar(20) | NOT NULL, default: 'member' |
| created_at | timestamp | auto |

**Project roles** (values defined in `config/core.php`):

| Value | Description |
|-------|-------------|
| `owner` | Project owner. Full rights: deletion, member management, module activation |
| `member` | Project member. Read/write access to project entities |

**Rules:**
- A User represents an AI agent (Claude, GPT, etc.) or an operator
- Two authentication modes (see section 8.2)
- A project must have **at least one owner** at all times
- A User can be owner of multiple projects
- A User can be a member of multiple projects
- Only one record per (project_id, user_id) pair — UNIQUE constraint
- The Core manages **no agile roles** (PO, SM, Dev, QA) — that is a module concept
- `owner` / `member` are **access responsibilities**, not workflow roles

---

### 2.3 Epic

Functional grouping of stories around a theme or business objective.

| Field | Type | Constraints |
|-------|------|-------------|
| id | uuid (v7) | PK |
| project_id | uuid | FK → projects.id, CASCADE |
| titre | varchar(255) | NOT NULL |
| description | text | NULLABLE |
| created_at | timestamp | auto |
| updated_at | timestamp | auto |

**Core Relations:**
- belongsTo(Project)
- hasMany(Story)

**Rules:**
- An Epic must belong to a Project
- Cascade deletion: deleting an Epic deletes its child Stories

---

### 2.4 Story (User Story)

Unit of work. Describes a feature, a bug, or an improvement to be implemented.

| Field | Type | Constraints |
|-------|------|-------------|
| id | uuid (v7) | PK |
| epic_id | uuid | FK → epics.id, CASCADE |
| titre | varchar(255) | NOT NULL |
| description | text | NULLABLE (Markdown) |
| type | varchar(20) | NOT NULL |
| nature | varchar(20) | NULLABLE |
| statut | varchar(20) | NOT NULL, default: 'draft' |
| priorite | varchar(20) | NOT NULL, default: 'moyenne' |
| ordre | integer | NULLABLE, unsigned |
| story_points | integer | NULLABLE, unsigned |
| reference_doc | varchar(2048) | NULLABLE |
| tags | json | NULLABLE |
| created_at | timestamp | auto |
| updated_at | timestamp | auto |

**Index:** `epic_id`, `titre`, `type`, `nature`, `statut`, `priorite`, `ordre`, GIN on `tags` (MariaDB: virtual index on JSON)

**Core Relations:**
- belongsTo(Epic)
- hasMany(Task)
- belongsToMany(Story) via `item_dependencies` — dependencies (blocks / blocked_by)

**Rules:**
- A Story must belong to an Epic
- Cascade deletion: deleting a Story deletes its child Tasks
- `ordre` represents the relative position of the Story within its Epic (sequential ordering, e.g.: phases)
- `statut` represents the basic lifecycle of the item (draft → open → closed). The Workflow module adds more granular states on top

---

### 2.5 Task

Technical sub-unit of work. Can exist independently or be attached to a Story.

| Field | Type | Constraints |
|-------|------|-------------|
| id | uuid (v7) | PK |
| project_id | uuid | FK → projects.id, CASCADE |
| story_id | uuid | FK → stories.id, NULLABLE, SET NULL |
| titre | varchar(255) | NOT NULL |
| description | text | NULLABLE (Markdown) |
| type | varchar(20) | NOT NULL |
| nature | varchar(20) | NULLABLE |
| statut | varchar(20) | NOT NULL, default: 'draft' |
| priorite | varchar(20) | NOT NULL, default: 'moyenne' |
| ordre | integer | NULLABLE, unsigned |
| estimation_temps | integer | NULLABLE, unsigned (minutes) |
| tags | json | NULLABLE |
| created_at | timestamp | auto |
| updated_at | timestamp | auto |

**Index:** `project_id`, `story_id`, `titre`, `type`, `nature`, `statut`, `priorite`, `ordre`, GIN on `tags`

**Core Relations:**
- belongsTo(Project)
- belongsTo(Story) — **optional**

**Two modes of existence:**
- **Standalone**: `story_id = NULL`, attached directly to the Project (isolated bug, hotfix, technical debt)
- **Child of Story**: `story_id` is set, sub-task of a story

**Rules:**
- `project_id` is mandatory and always set (inferred via Story→Epic→Project for child tasks, direct for standalone)
- Deleting a Story: its child Tasks are deleted via cascade
- Deleting a Project: all its standalone Tasks are deleted via cascade
- `ordre` represents the relative position of the Task within its Story
- `statut`: same basic lifecycle as Story (draft → open → closed)

---

### 2.6 Artifact (Identifier System)

Centralized registry of business identifiers in the format `{PROJECT_CODE}-{N}`.

| Field | Type | Constraints |
|-------|------|-------------|
| id | uuid (v7) | PK |
| project_id | uuid | FK → projects.id, CASCADE |
| identifier | varchar(35) | UNIQUE, NOT NULL |
| sequence_number | integer | NOT NULL, unsigned |
| artifactable_id | uuid | NOT NULL |
| artifactable_type | varchar(255) | NOT NULL |
| created_at | timestamp | auto |
| updated_at | timestamp | auto |

**Rules:**
- The `sequence_number` counter is unique and shared across all artifact types within the same project (Epic, Story, Task)
- Assignment is atomic (transaction + `FOR UPDATE` lock)
- The identifier is immutable
- Polymorphic relation to Epic, Story, or Task

---

### 2.7 Dependencies between items

Pivot table expressing blocking relationships between Stories and/or Tasks.

**Table `item_dependencies`:**

| Field | Type | Constraints |
|-------|------|-------------|
| id | uuid (v7) | PK |
| item_id | uuid | NOT NULL |
| item_type | varchar(255) | NOT NULL (App\Core\Models\Story or App\Core\Models\Task) |
| depends_on_id | uuid | NOT NULL |
| depends_on_type | varchar(255) | NOT NULL |
| created_at | timestamp | auto |

**Index:** UNIQUE on `(item_id, item_type, depends_on_id, depends_on_type)`

**Relations (polymorphic):**
- An item (Story or Task) can depend on **multiple** other items (`blocked_by`)
- An item can block **multiple** other items (`blocks`)
- Dependencies are cross-type: a Story can depend on a Task and vice versa

**Rules:**
- No circular dependencies (application-level validation)
- Deleting an item removes its dependencies (CASCADE on both sides)
- Dependencies are exposed via the `blocks()` and `blockedBy()` relations on the Story and Task models

**Example:**
```
PROJ-22 (Story Phase 3) blocked_by → PROJ-20 (Story Phase 2)
PROJ-22 (Story Phase 3) blocked_by → PROJ-21 (Story Phase 2)
PROJ-25 (Task deploy)   blocked_by → PROJ-23 (Task build)
```

---

## 3. Business value configuration

All allowed values for the `type`, `nature`, `priorite`, and `role` fields are defined in a configuration file, **never hardcoded in the code nor as DB enums**. This allows adding, renaming, or removing values without migrations or code deployments.

### 3.1 File `config/core.php`

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Types d'items (Story & Task)
    |--------------------------------------------------------------------------
    */
    'item_types' => ['backend', 'frontend', 'devops', 'qa'],

    /*
    |--------------------------------------------------------------------------
    | Priorites (Story & Task)
    |--------------------------------------------------------------------------
    */
    'priorities' => ['critique', 'haute', 'moyenne', 'basse'],

    /*
    |--------------------------------------------------------------------------
    | Priorite par defaut
    |--------------------------------------------------------------------------
    */
    'default_priority' => 'moyenne',

    /*
    |--------------------------------------------------------------------------
    | Statuts (Story & Task) — cycle de vie de base
    |--------------------------------------------------------------------------
    */
    'statuts' => ['draft', 'open', 'closed'],

    /*
    |--------------------------------------------------------------------------
    | Statut par defaut
    |--------------------------------------------------------------------------
    */
    'default_statut' => 'draft',

    /*
    |--------------------------------------------------------------------------
    | Natures de travail (Story & Task)
    |--------------------------------------------------------------------------
    */
    'work_natures' => ['feature', 'bug', 'improvement', 'spike', 'chore'],

    /*
    |--------------------------------------------------------------------------
    | Roles projet (table project_members)
    |--------------------------------------------------------------------------
    */
    'project_roles' => ['owner', 'member'],

    /*
    |--------------------------------------------------------------------------
    | Role par defaut a l'ajout d'un membre
    |--------------------------------------------------------------------------
    */
    'default_project_role' => 'member',

    /*
    |--------------------------------------------------------------------------
    | OAuth2 scopes
    |--------------------------------------------------------------------------
    */
    'oauth_scopes' => ['projects:read', 'projects:write', 'admin'],

    /*
    |--------------------------------------------------------------------------
    | OAuth2 durees (en minutes)
    |--------------------------------------------------------------------------
    */
    'oauth_access_token_ttl' => 60,        // 1 heure
    'oauth_refresh_token_ttl' => 43200,    // 30 jours

];
```

### 3.2 Application-level validation

Form Requests use the config for dynamic validation:

```php
// Dans StoreStoryRequest
public function rules(): array
{
    return [
        'type'     => ['required', 'string', Rule::in(config('core.item_types'))],
        'nature'   => ['nullable', 'string', Rule::in(config('core.work_natures'))],
        'priorite' => ['required', 'string', Rule::in(config('core.priorities'))],
    ];
}
```

### 3.3 Rules

- **No PHP enums** for business values — only the config file
- **No DB enum constraints** — columns are `varchar(20)`
- Adding a new value = editing `config/core.php`, nothing else
- The `GET /api/config/values` API exposes the allowed values (for clients/UI)

---

## 4. Core file structure

```
app/
  Core/
    Models/
      Project.php
      User.php
      ProjectMember.php              # Pivot model (pour acceder au role)
      Epic.php
      Story.php
      Task.php
      Artifact.php
      Concerns/
        HasArtifactIdentifier.php    # Trait generation identifiants
    Config/
      core.php                       # Valeurs metier : types, priorites, natures, roles
    Http/
      Controllers/
        ProjectController.php
        EpicController.php
        StoryController.php
        TaskController.php
        ArtifactController.php
        ModuleController.php
        ConfigController.php
        OAuthController.php          # authorize, token, revoke, register
      Middleware/
        AuthenticateBearer.php       # Resout token statique ou OAuth2, identifie le User
        EnsureProjectAccess.php      # Verifie que le User est membre du projet
        EnsureModuleActive.php       # Verifie que le module est actif pour le projet
      Requests/
        StoreProjectRequest.php
        UpdateProjectRequest.php
        StoreEpicRequest.php
        UpdateEpicRequest.php
        StoreStoryRequest.php
        UpdateStoryRequest.php
        StoreTaskRequest.php
        UpdateTaskRequest.php
      Resources/
        ProjectResource.php
        EpicResource.php
        StoryResource.php
        TaskResource.php
        ArtifactResource.php
    Console/
      Commands/
        UserListCommand.php            # php artisan user:list
        UserCreateCommand.php          # php artisan user:create
        UserUpdateCommand.php          # php artisan user:update {name}
        UserDeleteCommand.php          # php artisan user:delete {name}
        TokenCreateCommand.php         # php artisan token:create {user}
        TokenListCommand.php           # php artisan token:list {user}
        TokenRevokeCommand.php         # php artisan token:revoke {token_id}
        ProjectMembersCommand.php      # php artisan project:members {code}
        ProjectAddMemberCommand.php    # php artisan project:add-member {code} {user}
        ProjectRemoveMemberCommand.php # php artisan project:remove-member {code} {user}
    Providers/
      CoreServiceProvider.php        # Enregistre routes, observers, bindings, commandes
    Routes/
      api.php                        # Routes CRUD Core (interne uniquement)
    Database/
      Migrations/
        create_projects_table.php
        create_users_table.php
        create_api_tokens_table.php
        create_oauth_clients_table.php
        create_oauth_authorization_codes_table.php
        create_oauth_access_tokens_table.php
        create_oauth_refresh_tokens_table.php
        create_project_members_table.php
        create_epics_table.php
        create_stories_table.php
        create_tasks_table.php
        create_artifacts_table.php
        create_item_dependencies_table.php

    Mcp/
      Server/
        McpServer.php                  # Serveur MCP central, registre des tools et resources
        McpTransport.php               # Transport Streamable HTTP (JSON-RPC + SSE)
      Tools/
        ProjectTools.php               # Core tools projets
        EpicTools.php                  # Core tools epics
        StoryTools.php                 # Core tools stories
        TaskTools.php                  # Core tools tasks
        ArtifactTools.php              # Core tools artifacts
        ModuleTools.php                # Core tools gestion des modules
      Resources/
        ProjectOverviewResource.php    # Resource overview
        ProjectConfigResource.php      # Resource config
      Contracts/
        McpToolInterface.php           # Contrat pour un tool MCP
        McpResourceInterface.php       # Contrat pour une resource MCP
```

---

## 5. Core REST API

Global prefix: `/api/v1`

Conventions: **plural** resource names, business identifiers in URLs (never UUIDs), HTTP verbs for actions.

### 5.1 Projects

| Method | URI | Description |
|--------|-----|-------------|
| GET | /api/v1/projects | List projects |
| POST | /api/v1/projects | Create a project |
| GET | /api/v1/projects/{code} | Project details |
| PATCH | /api/v1/projects/{code} | Update a project (partial) |
| DELETE | /api/v1/projects/{code} | Delete a project |

### 5.2 Epics

| Method | URI | Description |
|--------|-----|-------------|
| GET | /api/v1/projects/{code}/epics | List epics |
| POST | /api/v1/projects/{code}/epics | Create an epic |
| GET | /api/v1/projects/{code}/epics/{identifier} | Epic details |
| PATCH | /api/v1/projects/{code}/epics/{identifier} | Update an epic (partial) |
| DELETE | /api/v1/projects/{code}/epics/{identifier} | Delete an epic |

### 5.3 Stories

| Method | URI | Description |
|--------|-----|-------------|
| GET | /api/v1/projects/{code}/stories | List all stories in the project |
| GET | /api/v1/projects/{code}/epics/{identifier}/stories | List stories of an epic |
| POST | /api/v1/projects/{code}/epics/{identifier}/stories | Create a story |
| GET | /api/v1/projects/{code}/stories/{identifier} | Story details |
| PATCH | /api/v1/projects/{code}/stories/{identifier} | Update a story (partial) |
| DELETE | /api/v1/projects/{code}/stories/{identifier} | Delete a story |

### 5.4 Tasks

| Method | URI | Description |
|--------|-----|-------------|
| GET | /api/v1/projects/{code}/tasks | List all tasks in the project (standalone + children) |
| GET | /api/v1/projects/{code}/stories/{identifier}/tasks | List tasks of a story |
| POST | /api/v1/projects/{code}/tasks | Create a standalone task |
| POST | /api/v1/projects/{code}/stories/{identifier}/tasks | Create a child task |
| GET | /api/v1/projects/{code}/tasks/{identifier} | Task details |
| PATCH | /api/v1/projects/{code}/tasks/{identifier} | Update a task (partial) |
| DELETE | /api/v1/projects/{code}/tasks/{identifier} | Delete a task |

**Note:** `{identifier}` is the artifact identifier (e.g.: `AGENTMG-3`). Internal UUIDs are never exposed in URLs.

### 5.5 Artifacts

| Method | URI | Description |
|--------|-----|-------------|
| GET | /api/v1/artifacts/{identifier} | Resolve an identifier (e.g.: AGENTMG-3) |
| GET | /api/v1/projects/{code}/artifacts?q=keyword | Search by keyword |

### 5.6 Configuration

| Method | URI | Description |
|--------|-----|-------------|
| GET | /api/v1/config | Allowed values (types, priorities, natures, roles) |

### 5.7 Pagination

All list endpoints support pagination via query params:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | integer | 1 | Page number |
| per_page | integer | 25 | Number of items per page (max: 100) |

Paginated response:

```json
{
  "data": [...],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 42,
    "last_page": 2
  }
}
```

### 5.8 Filtering

List endpoints support filtering via query params corresponding to indexed fields:

```
GET /api/v1/projects/{code}/stories?type=backend&priorite=haute&nature=bug
GET /api/v1/projects/{code}/tasks?type=qa&priorite=critique
```

| Parameter | Applies to | Description |
|-----------|------------|-------------|
| type | Stories, Tasks | Filter by type |
| nature | Stories, Tasks | Filter by nature |
| statut | Stories, Tasks | Filter by status |
| priorite | Stories, Tasks | Filter by priority |
| tags | Stories, Tasks | Filter by tag (e.g.: `?tags=urgent,v2`) |
| q | Stories, Tasks | Text search in title and description |

---

## 6. API response format

Responses return the resource (or collection) directly without a wrapper. The HTTP status code carries the status.

### 6.1 Single resource (GET, POST, PATCH)

```json
{
  "identifier": "AGENTMG-3",
  "titre": "Implementer le login",
  "type": "backend",
  "priorite": "haute",
  "created_at": "2026-02-20T10:30:00Z"
}
```

### 6.2 Paginated collection (GET list)

```json
{
  "data": [
    { "identifier": "AGENTMG-3", "titre": "..." },
    { "identifier": "AGENTMG-4", "titre": "..." }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 42,
    "last_page": 2
  }
}
```

### 6.3 Validation error (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "titre": ["The titre field is required."],
    "type": ["The selected type is invalid."]
  }
}
```

### 6.4 Generic error (404, 403, 500...)

```json
{
  "message": "Resource not found."
}
```

### 6.5 HTTP Status Codes

| Code | Usage |
|------|-------|
| 200 | Success (GET, PATCH) |
| 201 | Successful creation (POST) |
| 204 | Successful deletion (DELETE) — no body |
| 400 | Malformed request |
| 401 | Not authenticated |
| 403 | Access forbidden |
| 404 | Resource not found |
| 422 | Validation error |
| 429 | Rate limit exceeded |
| 500 | Server error |

---

## 7. Module system

### 7.1 Per-project activation

Each project declares its active modules in the `modules` field (JSON array):

```json
{
  "code": "AGENTMG",
  "titre": "Agent Manager",
  "modules": ["sprint", "comments", "timer", "assignation", "webhooks"]
}
```

### 7.2 Module management API

| Method | URI | Description |
|--------|-----|-------------|
| GET | /api/v1/modules | List all available modules (global registry) |
| GET | /api/v1/projects/{code}/modules | List active modules for the project |
| POST | /api/v1/projects/{code}/modules | Activate a module |
| DELETE | /api/v1/projects/{code}/modules/{slug} | Deactivate a module |

### 7.3 Module sources

A module can be loaded in two ways:

**A. Local directory** — internal modules, shipped with the application:

```
app/Modules/Sprint/
  SprintModule.php
  SprintServiceProvider.php
  Models/
  Http/
  ...
```

Declared in `config/modules.php` and loaded by the `ModuleServiceProvider`.

**B. Composer package** — external modules, distributable and versioned:

```
vendor/poiesis/module-kanban/
  src/
    KanbanModule.php
    KanbanServiceProvider.php
  config/
    kanban.php
  database/
    migrations/
  composer.json
```

Installed via `composer require poiesis/module-kanban`. Automatically discovered through Laravel's package discovery (`extra.laravel.providers` field in `composer.json`).

Both module types implement the same contract and register in the same `ModuleRegistry`. From the application's perspective, there is no difference.

### 7.4 Activation mechanism

1. At boot, the `ModuleServiceProvider` loads all modules (local via `config/modules.php` + packages via auto-discovery)
2. Each module registers in the **global registry** (`ModuleRegistry`)
3. When a request arrives, the `EnsureModuleActive` middleware checks that the module is activated for the current project
4. If a request targets an endpoint of a deactivated module, a `404` response is returned with an explicit message

### 7.5 Module contract

Each module implements the `ModuleInterface`:

```php
interface ModuleInterface
{
    /** Identifiant unique du module (slug) */
    public function slug(): string;

    /** Nom affichable */
    public function name(): string;

    /** Description */
    public function description(): string;

    /** Modules requis (dependances) */
    public function dependencies(): array;

    /** Enregistrer les routes du module */
    public function registerRoutes(): void;

    /** Enregistrer les event listeners / observers */
    public function registerListeners(): void;

    /** Migrations du module */
    public function migrationPath(): string;

    /** Classes de tools MCP exposes par le module (implements McpToolInterface) */
    public function mcpTools(): array;
}
```

---

## 8. MCP Server (Core component)

The MCP server is the **sole interface** of the platform. No content is created or modified outside of MCP. It is **integrated directly into Laravel** — no separate process, no third-party language. Laravel handles both the MCP protocol and the business logic.

### 8.1 Architecture

The MCP server is a **single Laravel application** hosted on a remote server, accessible via HTTPS. All clients (Claude Code, Claude Desktop, autonomous agents, third-party apps) connect to the same endpoint via the **Streamable HTTP** transport.

```
Claude Code          Claude Desktop        Agents autonomes     Apps tierces
     │                     │                      │                  │
     │                     │                      │                  │
     └─────────────────────┴──────────────────────┴──────────────────┘
                                    │
                                    │  HTTPS (Streamable HTTP)
                                    │  Auth: Bearer token (statique ou OAuth2)
                                    ▼
                    ┌──────────────────────────────────────┐
                    │        Application Laravel            │
                    │        https://mcp.example.com        │
                    │                                       │
                    │  POST /mcp      → Protocole MCP      │
                    │  GET  /mcp      → SSE (streaming)    │
                    │  GET  /oauth/*  → OAuth2             │
                    │  POST /oauth/*  → OAuth2             │
                    │                                       │
                    │  ┌─────────────────────────────────┐ │
                    │  │ McpServer (registre central)     │ │
                    │  │  ├── Core Tools (PHP)            │ │
                    │  │  ├── Module Tools (PHP)          │ │
                    │  │  └── Resources (PHP)             │ │
                    │  └─────────────────────────────────┘ │
                    │                                       │
                    │  Eloquent ORM → Database              │
                    └───────────────────────────────────────┘
```

**Benefits of the Laravel integration:**
- **Zero indirection**: no intermediate HTTP layer between MCP and the DB; tools call Eloquent directly
- **Single codebase**: PHP everywhere; tools share the same models, validations, and services as modules
- **Simplified deployment**: a single application to deploy, configure, and maintain
- **Consistency**: business validation, events, and policies are the same for MCP tools and modules

**Single transport: Streamable HTTP**
- All clients use the same HTTP/HTTPS transport
- No stdio transport — the server is remote, not a local process
- The server exposes an MCP endpoint: `POST /mcp` (JSON-RPC requests) and `GET /mcp` (SSE for streaming responses)
- The MCP protocol is encapsulated in standard HTTP requests

### 8.2 Authentication

Two authentication modes, both on the same HTTP transport:

#### A. Static token

For command-line agents (Claude Code, CI scripts, autonomous agents). The token is passed in the client configuration:

```json
{
  "mcpServers": {
    "poiesis": {
      "url": "https://mcp.example.com/mcp",
      "headers": {
        "Authorization": "Bearer aa-token-xxxxxxxx"
      }
    }
  }
}
```

The Laravel middleware receives the token in the HTTP header, hashes it, and compares it against the `api_tokens` table to identify the User. No intermediate layer.

#### B. OAuth2

For clients that support the interactive authorization flow (Claude Desktop, Claude Code with OAuth2, third-party apps). Laravel exposes standard OAuth2 endpoints:

| Endpoint | Description |
|----------|-------------|
| `GET /.well-known/oauth-authorization-server` | Authorization server metadata (RFC 8414) |
| `POST /oauth/register` | Dynamic client registration (RFC 7591) |
| `GET /oauth/authorize` | Consent screen + authorization code |
| `POST /oauth/token` | Exchange code → access_token + refresh_token |
| `POST /oauth/revoke` | Token revocation |

**OAuth2 Authorization Code + PKCE flow:**

```
Client MCP                           Application Laravel
      │                                      │
      │  1. GET /oauth/authorize             │
      │     ?client_id=xxx                   │
      │     &code_challenge=yyy              │
      │     &redirect_uri=zzz               │
      │ ───────────────────────────────────→ │
      │                                      │
      │  2. Redirect avec code               │
      │ ←─────────────────────────────────── │
      │                                      │
      │  3. POST /oauth/token                │
      │     code + code_verifier             │
      │ ───────────────────────────────────→ │
      │                                      │
      │  4. access_token + refresh           │
      │ ←─────────────────────────────────── │
      │                                      │
      │  5. POST /mcp + Bearer token         │
      │     {"method":"tools/call",...}       │
      │ ───────────────────────────────────→ │  ← Eloquent direct, pas d'API interne
      │                                      │
      │  6. Reponse JSON-RPC                 │
      │ ←─────────────────────────────────── │
      │                                      │
```

**OAuth2 rules:**
- PKCE is mandatory (S256) — no client_secret for public clients
- Access token: short duration (1h by default, configurable)
- Refresh token: long duration (30d by default, configurable)
- Available scopes: `projects:read`, `projects:write`, `admin` (defined in `config/core.php`)
- Dynamic client registration (RFC 7591) allows Claude Desktop to register automatically

#### Identity resolution

Regardless of the mode (static token or OAuth2), the `AuthenticateBearer` middleware resolves the User the same way:
1. Looks up in `api_tokens` (SHA-256 hash of the raw token)
2. If not found, looks up in `oauth_access_tokens`
3. Checks expiration and project access rights
4. Injects the authenticated User into the request context (`$request->user()`)

### 8.3 Core MCP Tools

The Core tools correspond to CRUD operations on the fundamental entities:

**Projects:**

| Tool | Description |
|------|-------------|
| `list_projects` | Lists projects accessible by the agent |
| `get_project` | Project details by its code |
| `create_project` | Creates a new project |
| `update_project` | Updates a project |
| `delete_project` | Deletes a project |

**Epics:**

| Tool | Description |
|------|-------------|
| `list_epics` | Lists epics of a project |
| `get_epic` | Epic details by its identifier |
| `create_epic` | Creates an epic |
| `update_epic` | Updates an epic |
| `delete_epic` | Deletes an epic |

**Stories:**

| Tool | Description |
|------|-------------|
| `list_stories` | Lists stories of a project (filterable) |
| `list_epic_stories` | Lists stories of an epic |
| `get_story` | Story details by its identifier |
| `create_story` | Creates a story in an epic |
| `create_stories` | Creates multiple stories in a single operation (batch) |
| `update_story` | Updates a story |
| `delete_story` | Deletes a story |

**Tasks:**

| Tool | Description |
|------|-------------|
| `list_tasks` | Lists tasks of a project (standalone + children) |
| `list_story_tasks` | Lists tasks of a story |
| `get_task` | Task details by its identifier |
| `create_task` | Creates a task (standalone or child of a story) |
| `create_tasks` | Creates multiple tasks in a single operation (batch) |
| `update_task` | Updates a task |
| `delete_task` | Deletes a task |

**Dependencies:**

| Tool | Description |
|------|-------------|
| `add_dependency` | Declares that an item depends on another (blocked_by) |
| `remove_dependency` | Removes a dependency |
| `list_dependencies` | Lists dependencies of an item (blocks + blocked_by) |

**Artifacts:**

| Tool | Description |
|------|-------------|
| `resolve_artifact` | Resolves an identifier (e.g.: AGENTMG-3) to the complete entity |
| `search_artifacts` | Search by keyword |

**Modules:**

| Tool | Description |
|------|-------------|
| `list_available_modules` | Lists all available modules |
| `list_project_modules` | Lists active modules of a project |
| `activate_module` | Activates a module for a project |
| `deactivate_module` | Deactivates a module for a project |

### 8.4 Batch operations

The batch tools (`create_stories`, `create_tasks`) accept an array of items and create them in a single transaction. This avoids sequential round-trips when an agent needs to create multiple entities at once.

**Example `create_stories`:**

```json
{
  "project_code": "PROJ",
  "epic_identifier": "PROJ-1",
  "stories": [
    { "titre": "Phase 1 - Setup", "type": "backend", "priorite": "haute", "ordre": 1 },
    { "titre": "Phase 2 - API", "type": "backend", "priorite": "haute", "ordre": 2 },
    { "titre": "Phase 3 - Tests", "type": "qa", "priorite": "moyenne", "ordre": 3 }
  ]
}
```

**Behavior:**
- All stories are created in a single transaction (all or nothing)
- Artifact identifiers are assigned sequentially
- The response returns the complete array of created items with their identifiers
- In case of a validation error on one item, no items are created and the error specifies the faulty index

### 8.5 Core MCP Resources

Resources provide read-only context to agents:

| Resource URI | Description |
|-------------|-------------|
| `project://{code}/overview` | Project summary with statistics |
| `project://{code}/config` | Allowed business values (types, priorities, natures) |

### 8.6 MCP Tools modularity

Modules inject their own tools into the MCP server. Everything is in PHP, in the same Laravel process.

1. The `ModuleInterface` exposes an additional method:

```php
/** Classe(s) de tools MCP du module */
public function mcpTools(): array;
```

2. Each module provides one or more tool classes:

```
app/Modules/Sprint/
  Mcp/
    SprintTools.php     # implements McpToolInterface
```

3. At boot, the `McpServer` collects tools from the Core + tools from all modules registered in the `ModuleRegistry`.

4. At runtime, before executing a module tool, the `McpServer` checks that the module is active for the current project (same logic as the `EnsureModuleActive` middleware).

**MCP tool contract:**

```php
interface McpToolInterface
{
    /** Liste des tools exposes (nom, description, schema des parametres) */
    public function tools(): array;

    /** Executer un tool */
    public function execute(string $toolName, array $params, User $user): mixed;
}
```

**Example of a Core tool:**

```php
class ProjectTools implements McpToolInterface
{
    public function tools(): array
    {
        return [
            [
                'name' => 'list_projects',
                'description' => 'Liste les projets accessibles par l\'agent',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [],
                ],
            ],
            [
                'name' => 'create_project',
                'description' => 'Cree un nouveau projet',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string', 'description' => 'Code unique du projet (2-25 chars)'],
                        'titre' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                    ],
                    'required' => ['code', 'titre'],
                ],
            ],
            // ...
        ];
    }

    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match($toolName) {
            'list_projects' => Project::whereHas('members', fn($q) => $q->where('user_id', $user->id))->get(),
            'create_project' => Project::create($params),
            // ...
        };
    }
}
```

### 8.7 MCP Protocol (Streamable HTTP)

The MCP server is handled by two Laravel routes:

```php
// routes/mcp.php
Route::post('/mcp', [McpController::class, 'handle'])->middleware('auth:bearer');
Route::get('/mcp', [McpController::class, 'stream'])->middleware('auth:bearer');
```

**`POST /mcp`** — Receives JSON-RPC requests from the MCP protocol:
- `initialize`: version negotiation, returns capabilities
- `tools/list`: returns the list of all tools (Core + active modules)
- `tools/call`: executes a tool and returns the result
- `resources/list`: returns the list of available resources
- `resources/read`: returns the content of a resource

**`GET /mcp`** — SSE (Server-Sent Events) for streaming responses and server-to-client notifications.

**The `McpServer` (central registry):**

```php
class McpServer
{
    private array $coreTools = [];
    private array $coreResources = [];

    /** Enregistrer les tools Core au boot */
    public function registerCoreTools(McpToolInterface $tools): void;

    /** Enregistrer une resource Core */
    public function registerCoreResource(McpResourceInterface $resource): void;

    /** Traiter une requete JSON-RPC entrante */
    public function handleRequest(array $jsonRpc, User $user): array;

    /** Resoudre les tools disponibles (Core + modules du projet) */
    public function resolveTools(?Project $project): array;
}
```

### 8.8 MCP structure in Laravel

```
app/Core/
  Mcp/
    Server/
      McpServer.php                  # Registre central, dispatch des requetes JSON-RPC
      McpTransport.php               # Encode/decode Streamable HTTP + SSE
    Http/
      Controllers/
        McpController.php            # POST /mcp et GET /mcp
    Tools/
      ProjectTools.php               # list_projects, get_project, create_project, ...
      EpicTools.php                  # list_epics, get_epic, create_epic, ...
      StoryTools.php                 # list_stories, get_story, create_story, ...
      TaskTools.php                  # list_tasks, get_task, create_task, ...
      ArtifactTools.php              # resolve_artifact, search_artifacts
      ModuleTools.php                # list_modules, activate_module, ...
    Resources/
      ProjectOverviewResource.php    # project://{code}/overview
      ProjectConfigResource.php      # project://{code}/config
    Contracts/
      McpToolInterface.php           # Contrat pour un provider de tools
      McpResourceInterface.php       # Contrat pour une resource MCP

app/Modules/Sprint/
  Mcp/
    SprintTools.php                  # Tools sprint (list_sprints, activate_sprint, ...)
app/Modules/Comments/
  Mcp/
    CommentTools.php                 # Tools commentaires
```

### 8.9 No public REST API

There is **no exposed REST API**. The MCP protocol (via `POST /mcp`) is the sole entry point for all operations.

The internal routes `/api/v1/...` described in section 5 exist as an **internal service layer** (Laravel controllers called directly by MCP tools, or simply as a reference for logical endpoints). In practice, MCP tools call Eloquent models and Laravel services directly without going through HTTP.

Consequences:
- No CORS
- No public Swagger documentation
- Authentication is done via Bearer token in the HTTP headers of the MCP request
- The only elements configurable by a human are in `config/core.php`, `config/modules.php`, and the Artisan commands

---

## 9. Artisan Commands (CLI)

User and token management is done exclusively via Artisan commands. This is the only human administration interface of the platform.

### 9.1 User management

| Command | Description |
|---------|-------------|
| `php artisan user:list` | List all users |
| `php artisan user:create {name}` | Create a user |
| `php artisan user:update {name} --name=new-name` | Rename a user |
| `php artisan user:delete {name}` | Delete a user and all their tokens |

**`user:list`** — Displays a table with id, name, token count, project count, and creation date.

**`user:create`** — Creates a user and immediately offers to generate a first token:

```
$ php artisan user:create "Claude Backend"

  User created: Claude Backend (id: 01924...)

  Generate a token now? [yes/no] yes
  Token name: [default]

  ┌──────────────────────────────────────────────────┐
  │  Token: aa-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx       │
  │                                                    │
  │  ⚠  Ce token ne sera plus jamais affiche.         │
  │  Conservez-le dans un endroit sur.                 │
  └──────────────────────────────────────────────────┘
```

**`user:delete`** — Asks for confirmation, then cascade-deletes tokens, memberships, and associated OAuth tokens.

### 9.2 Token management

| Command | Description |
|---------|-------------|
| `php artisan token:create {user}` | Generate a new token for a user |
| `php artisan token:list {user}` | List tokens for a user |
| `php artisan token:revoke {token_id}` | Revoke (delete) a token |

**`token:create`** — Options:

| Option | Description |
|--------|-------------|
| `--name=` | Token name (default: `default`) |
| `--expires=` | Validity duration (e.g.: `30d`, `6h`, `never`) — default: `never` |

```
$ php artisan token:create "Claude Backend" --name="ci-pipeline" --expires=30d

  Token created for Claude Backend:

  ┌──────────────────────────────────────────────────┐
  │  Token: aa-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx       │
  │  Name:  ci-pipeline                               │
  │  Expires: 2026-03-22 14:30:00                     │
  │                                                    │
  │  ⚠  Ce token ne sera plus jamais affiche.         │
  └──────────────────────────────────────────────────┘
```

**`token:list`** — Displays a table with id, name, creation date, expiration, and last usage. The raw token is never displayed (only the hash is stored).

**`token:revoke`** — Immediately deletes the token. In-flight MCP requests using this token will fail on their next call.

### 9.3 Project member management

| Command | Description |
|---------|-------------|
| `php artisan project:members {code}` | List members of a project |
| `php artisan project:add-member {code} {user} --role=member` | Add a member |
| `php artisan project:remove-member {code} {user}` | Remove a member |

**`project:add-member`** — The `--role` option accepts values defined in `config('core.project_roles')`. Default: `member`.

**`project:remove-member`** — Refuses to remove the last `owner` of a project.

---

## 10. Cross-cutting business rules

- All models use UUID v7 (`HasUuids`)
- Artifact identifiers (`{CODE}-{N}`) are automatically generated and shared across Epic, Story, and Task
- Deleting a parent entity triggers cascade deletion of its children
- No agile role management in the Core
- Assignment does not exist in the Core — it is a module
- The Core manages a **basic status** set (draft / open / closed). The Workflow module adds process states on top (backlog, in_progress, in_review, done...)
- **Descriptions** (Epic, Story, Task) support **Markdown**. Content is stored as plain text; Markdown interpretation is the responsibility of the client/agent
- Tags are free-form, with no predefined list
- All interaction goes through the MCP server — the REST API is internal
- **Dependencies** between items (blocked_by / blocks) are a structural Core component
- **Story templates** are a module feature (outside the Core)

---

## 11. Core relations schema

```
Agent IA ──(HTTPS/Streamable HTTP)──→ Laravel (MCP + Eloquent) ──→ DB

User ──membership──┐
User ──ownership───┐│
                   ▼▼
                Project
               /       \
             Epic      Task (standalone)
              |
            Story
              |
            Task (enfant)

         Artifact ← polymorphique → Epic | Story | Task
```

---

## 12. Glossary

| Term | Definition |
|------|-----------|
| Core | Set of fundamental entities, services, and MCP tools, always active |
| Module | Per-project activatable extension, built on the Core |
| MCP | Model Context Protocol — communication protocol between AI agents and the platform |
| MCP Tool | Operation executable by an AI agent via the MCP server |
| MCP Resource | Read-only data exposed to AI agents for context |
| Standalone Task | Task attached directly to the project, without a parent story |
| Artifact | Unique business identifier in the format `{CODE}-{N}` |
| Module Registry | Central registry listing all available modules |
| Project Modules | Subset of modules activated for a given project |
| Token | Authentication key for an AI agent, linked to a User |
