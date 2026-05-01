# Poiesis — Modular Agile Project Management for AI Agents

[<image-card alt="PHP Version" src="https://img.shields.io/badge/PHP-8.4+-blue?logo=php" ></image-card>](https://php.net)
[<image-card alt="Laravel" src="https://img.shields.io/badge/Laravel-12.x-red?logo=laravel" ></image-card>](https://laravel.com)
[<image-card alt="License" src="https://img.shields.io/badge/License-Apache%202.0-yellow" ></image-card>](LICENSE)
[<image-card alt="MCP 2.0" src="https://img.shields.io/badge/MCP-2.0-orange" ></image-card>](https://modelcontextprotocol.io/)

**Poiesis** is a production-ready agile project management platform designed to be controlled via the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/). It provides AI agents with structured tools and workflows to manage projects, epics, stories, and tasks in a fully modular architecture.

---

## Features

### Core Capabilities

- **Hierarchical Project Structure**: Projects → Epics → Stories → Tasks
- **Unified Status Lifecycle**: `draft` → `open` → `closed` (with re-open)
- **Dependency Tracking**: Declare dependencies between artifacts; check blockers before opening work
- **Module System**: Extend platform capabilities with pluggable modules
- **Multi-tenancy (SaaS)**: Row-level tenant isolation with automatic Global Scope — transparent to API consumers
- **Multi-user Collaboration**: Two-tier access control — global user roles and per-project membership roles
- **RESTful API**: HTTP-based project management endpoints
- **MCP Server**: Native MCP 2.0 integration for AI agent control

### MCP Server Features

The MCP server exposes:

- **36+ Tools**: Full CRUD operations for projects, epics, stories, tasks, modules, and dependencies
- **2 Resources**: Project overview and configuration snapshots
- **1 Prompt**: Agile workflow guide for AI agents
- **HTTP Transport**: Streamable HTTP with Server-Sent Events support
- **Bearer Token Auth**: Secure agent-to-server communication

---

## Installation & Setup

### Requirements

- PHP 8.4+
- Laravel 12
- MariaDB 11.8+ (or any compatible database)

### Quick Start

```bash
git clone https://github.com/arthur2jolly/poiesis.git
cd poiesis
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

---

## Using the MCP Server

### Authentication

All MCP requests require a **Bearer token** in the `Authorization` header:

```bash
curl -X POST http://localhost:8000/mcp \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1,"params":{}}'
```

**Generate a token:**

```bash
php artisan artisan:token:create --name="Agent" --user-id=1
```

### MCP Endpoints

- **POST /mcp**: JSON-RPC request handler (initialize, tools/list, tools/call, resources/list, resources/read, prompts/list, prompts/get)
- **GET /mcp**: Server-Sent Events stream for push notifications

### Available Tools

#### Project Management

- `list_projects` — List all accessible projects
- `get_project` — Get project details
- `create_project` — Create a new project
- `update_project` — Update project title/description
- `delete_project` — Delete a project (owner only)

#### Epics

- `list_epics` — List epics in a project
- `get_epic` — Get epic details
- `create_epic` — Create an epic
- `update_epic` — Update epic
- `delete_epic` — Delete epic and child stories

#### Stories

- `list_stories` — List stories with optional filters (type, priority, status, tags)
- `get_story` — Get story with dependencies
- `create_story` — Create a story in an epic
- `create_stories` — Bulk create stories (atomic)
- `update_story` — Update story fields
- `delete_story` — Delete story and child tasks
- `update_story_status` — Change status (draft→open→closed)
- `list_epic_stories` — List stories of a specific epic

#### Tasks

- `list_tasks` — List all tasks in project with filters
- `get_task` — Get task details
- `create_task` — Create a standalone or child task
- `create_tasks` — Bulk create tasks (atomic)
- `update_task` — Update task
- `delete_task` — Delete task
- `update_task_status` — Change task status

#### Artifacts & Search

- `resolve_artifact` — Resolve identifier (e.g., `POIESIS-1`) to full object
- `search_artifacts` — Full-text search in a project

#### Dependencies

- `add_dependency` — Declare that artifact A is blocked by artifact B
- `remove_dependency` — Remove a dependency
- `list_dependencies` — Inspect dependency graph for an artifact

#### Modules

- `list_available_modules` — List all available modules
- `list_project_modules` — List active modules for a project
- `activate_module` — Activate a module (owner only)
- `deactivate_module` — Deactivate a module (owner only)

### Available Resources

- `project://{code}/overview` — Project summary (epic/story/task counts, active modules)
- `project://{code}/config` — Project configuration (allowed types, priorities, active modules)

### Workflow Prompt

The `agile-workflow` prompt contains:

- Hierarchy explanation (Project → Epic → Story → Task)
- Status lifecycle
- Workflow best practices
- Dependencies and modules guide
- Conventions and usage patterns

Clients can fetch this prompt via `prompts/get` to provide LLMs with structured guidance.

---

## Development & Extension

### File Structure

```tree
app/Core/
├── Mcp/
│   ├── Contracts/
│   │   ├── McpToolInterface.php      # Tool provider contract
│   │   ├── McpResourceInterface.php  # Resource provider contract
│   │   └── McpPromptInterface.php    # Prompt provider contract
│   ├── Tools/
│   │   ├── ProjectTools.php          # Project CRUD
│   │   ├── EpicTools.php             # Epic management
│   │   ├── StoryTools.php            # Story management
│   │   ├── TaskTools.php             # Task management
│   │   ├── ArtifactTools.php         # Search & resolve
│   │   ├── DependencyTools.php       # Dependency graph
│   │   └── ModuleTools.php           # Module activation
│   ├── Resources/
│   │   ├── ProjectOverviewResource.php
│   │   └── ProjectConfigResource.php
│   ├── Prompts/
│   │   └── AgileWorkflowPrompt.php   # Workflow guide
│   ├── Http/Controllers/
│   │   └── McpController.php         # HTTP handler
│   ├── Server/
│   │   ├── McpServer.php             # Core MCP dispatch
│   │   └── McpTransport.php          # JSON-RPC codec
│   └── Routes/
│       └── mcp.php                   # MCP endpoint routes
└── Providers/
    └── CoreServiceProvider.php       # Tool/resource registration
```

### Adding a New Tool

1. **Create a tool provider** (e.g., `app/Core/Mcp/Tools/CustomTools.php`):

   ```php
   namespace App\Core\Mcp\Tools;
   use App\Core\Mcp\Contracts\McpToolInterface;
   use App\Core\Models\User;

   class CustomTools implements McpToolInterface
   {
       public function tools(): array
       {
           return [
               [
                   'name' => 'custom_action',
                   'description' => 'Does something custom',
                   'inputSchema' => [
                       'type' => 'object',
                       'properties' => [
                           'param' => ['type' => 'string'],
                       ],
                       'required' => ['param'],
                   ],
               ],
           ];
       }

       public function execute(string $toolName, array $params, User $user): mixed
       {
           return match ($toolName) {
               'custom_action' => $this->doAction($params),
               default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
           };
       }

       private function doAction(array $params): array
       {
           // Implementation
           return ['result' => 'success'];
       }
   }
   ```

2. **Register in `CoreServiceProvider`**:

   ```php
   private function registerMcpTools(): void
   {
       $server = $this->app->make(McpServer::class);
       $server->registerCoreTools(new CustomTools);
   }
   ```

### Adding a New Resource

1. **Create a resource provider** (e.g., `app/Core/Mcp/Resources/CustomResource.php`):

   ```php
   namespace App\Core\Mcp\Resources;
   use App\Core\Mcp\Contracts\McpResourceInterface;
   use App\Core\Models\User;

   class CustomResource implements McpResourceInterface
   {
       public function uri(): string
       {
           return 'custom://{id}';
       }

       public function name(): string
       {
           return 'Custom Resource';
       }

       public function description(): string
       {
           return 'A custom resource';
       }

       public function read(array $params, User $user): mixed
       {
           $id = $params['id'] ?? null;
           return ['id' => $id, 'data' => 'value'];
       }
   }
   ```

2. **Register in `CoreServiceProvider`**:

   ```php
   private function registerMcpResources(): void
   {
       $server = $this->app->make(McpServer::class);
       $server->registerCoreResource(new CustomResource);
   }
   ```

### Adding a New Prompt

1. **Create a prompt provider** (e.g., `app/Core/Mcp/Prompts/CustomPrompt.php`):

   ```php
   namespace App\Core\Mcp\Prompts;
   use App\Core\Mcp\Contracts\McpPromptInterface;

   class CustomPrompt implements McpPromptInterface
   {
       public function name(): string
       {
           return 'custom-guide';
       }

       public function description(): string
       {
           return 'Custom workflow guide';
       }

       public function messages(): array
       {
           return [
               [
                   'role' => 'user',
                   'content' => [
                       'type' => 'text',
                       'text' => file_get_contents(resource_path('mcp/custom.md')),
                   ],
               ],
           ];
       }
   }
   ```

2. **Create content** in `resources/mcp/custom.md`

3. **Register in `CoreServiceProvider`**:

   ```php
   private function registerMcpPrompts(): void
   {
       $server = $this->app->make(McpServer::class);
       $server->registerPrompt(new CustomPrompt);
   }
   ```

### Creating a New Module

Modules are pluggable extensions that add domain-specific tools, resources, and workflows. Each module consists of:

1. **Tool providers** — Additional MCP tools specific to the module
2. **Optional resources & prompts** — Domain-specific context
3. **Registration** — Declare in the module registry with optional dependencies

#### Step 1: Create Module Structure

Create a directory for your module (e.g., `app/Modules/Reporting/`):

```tree
app/Modules/Reporting/
├── MCP/
│   ├── Tools/
│   │   ├── ReportTools.php        # Tools for this module
│   │   └── AnalyticsTools.php
│   ├── Resources/
│   │   └── ReportingConfigResource.php
│   └── Prompts/
│       └── ReportingGuidePrompt.php
├── Services/
│   ├── ReportService.php
│   └── AnalyticsService.php
├── Models/
│   ├── Report.php
│   └── ReportTemplate.php
└── ReportingModuleProvider.php
```

#### Step 2: Create Tool Providers

`app/Modules/Reporting/MCP/Tools/ReportTools.php`:

```php
namespace App\Modules\Reporting\MCP\Tools;
use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\User;

class ReportTools implements McpToolInterface
{
    public function tools(): array
    {
        return [
            [
                'name' => 'generate_report',
                'description' => 'Generate a report for a project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_code' => ['type' => 'string'],
                        'format' => ['type' => 'string', 'enum' => ['json', 'pdf']],
                        'include_metrics' => ['type' => 'boolean'],
                    ],
                    'required' => ['project_code'],
                ],
            ],
            [
                'name' => 'list_reports',
                'description' => 'List all reports for a project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_code' => ['type' => 'string'],
                    ],
                    'required' => ['project_code'],
                ],
            ],
        ];
    }

    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'generate_report' => $this->generateReport($params, $user),
            'list_reports' => $this->listReports($params, $user),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }

    private function generateReport(array $params, User $user): array
    {
        // Implementation
        return ['report_id' => 'REP-1', 'url' => 'https://...'];
    }

    private function listReports(array $params, User $user): array
    {
        // Implementation
        return ['data' => []];
    }
}
```

#### Step 3: Create Module Provider

`app/Modules/Reporting/ReportingModuleProvider.php`:

```php
namespace App\Modules\Reporting;
use App\Core\Mcp\Server\McpServer;
use App\Modules\Reporting\MCP\Tools\ReportTools;
use App\Modules\Reporting\MCP\Tools\AnalyticsTools;
use Illuminate\Support\ServiceProvider;

class ReportingModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind module services
        $this->app->singleton(\App\Modules\Reporting\Services\ReportService::class);
    }

    public function boot(): void
    {
        // Publish migrations, assets, etc.
        $this->publishMigrations();
        $this->publishConfig();

        // Register MCP tools for this module
        $this->registerMcpTools();
    }

    private function registerMcpTools(): void
    {
        $server = $this->app->make(McpServer::class);

        // Register tools under module slug 'reporting'
        $server->registerModuleTools('reporting', [
            new ReportTools,
            new AnalyticsTools,
        ]);
    }

    private function publishMigrations(): void
    {
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ]);
    }

    private function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/reporting.php' => config_path('modules/reporting.php'),
        ]);
    }
}
```

#### Step 4: Register Module in Laravel

Add to `config/app.php` providers:

```php
\App\Modules\Reporting\ReportingModuleProvider::class,
```

#### Step 5: Register Module in Module Registry

Create `app/Core/Module/ModuleRegistry.php` (or update if exists) to declare module metadata:

```php
public function registerModule(string $slug, array $config): void
{
    $this->modules[$slug] = [
        'name' => $config['name'],
        'description' => $config['description'],
        'dependencies' => $config['dependencies'] ?? [],
    ];
}

// In a service provider or boot method:
$this->app->make(ModuleRegistry::class)->registerModule('reporting', [
    'name' => 'Reporting',
    'description' => 'Generate reports and analytics',
    'dependencies' => [], // List dependent module slugs, e.g., ['core']
]);
```

#### Step 6: Run Migrations

```bash
php artisan migrate
```

#### Result

Once registered, agents can:

1. **List available modules:**

   ```bash
   curl ... -d '{"jsonrpc":"2.0","method":"tools/list","params":{}}'
   # → Now includes tools from ReportTools and AnalyticsTools
   ```

2. **Activate the module for a project:**

   ```bash
   curl ... -d '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"activate_module","arguments":{"project_code":"MY_PROJECT","slug":"reporting"}}}'
   ```

3. **Use module tools:**

   ```bash
   curl ... -d '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"generate_report","arguments":{"project_code":"MY_PROJECT","format":"pdf"}}}'
   ```

### Workflow Documentation

The agile workflow guide is stored in:

```text
resources/mcp/agile-workflow.md
```

**Edit this file** to adjust guidelines, add module-specific instructions, or update best practices without modifying PHP code.

---

## Code Quality

### Linting (PSR-12)

```bash
./vendor/bin/pint              # Fix issues
./vendor/bin/pint --test       # Check without fixing
```

### Static Analysis (PHPStan Level 8)

```bash
./vendor/bin/phpstan analyse --no-progress
```

### Testing (Pest)

```bash
./vendor/bin/pest
```

---

## Multi-tenancy

Poiesis supports **row-level multi-tenancy** for SaaS deployments. Each tenant has isolated users, projects, tokens, and artifacts.

### How it works

- A `BelongsToTenant` trait applies a **Global Scope** on all tenant-aware models (User, Project, ApiToken, OAuthClient, OAuthAccessToken, OAuthAuthorizationCode, Artifact)
- The tenant is resolved from the Bearer token in `AuthenticateBearer` middleware and stored in a `TenantManager` singleton
- All queries are automatically scoped — no code changes needed in controllers or MCP tools
- CLI commands use `withoutTenantScope()` to operate across tenants

### Tenant Management

```bash
# Create a tenant (optionally create an owner user)
php artisan tenant:create "Acme Corp" --slug=acme

# List all tenants
php artisan tenant:list

# Enable / disable a tenant
php artisan tenant:enable acme
php artisan tenant:disable acme

# Delete a tenant and ALL its data (cascading)
php artisan tenant:delete acme

# Assign orphan rows (tenant_id=null) to a tenant
php artisan tenant:assign-default acme

# Create a superadmin (separate from tenant users)
php artisan superadmin:create --name=admin --password=secret123
```

### User & Token with Tenant

```bash
# Create a user in a specific tenant
php artisan user:create --tenant=acme --role=1

# Create a token for a user in a specific tenant
php artisan token:create john --tenant=acme --name=agent
```

---

## Access Control

Poiesis uses two independent role systems that work together.

### Global User Role

Assigned at user creation, controls what operations the user can perform across the entire platform (MCP tools, REST API):

| `--role` | Name | Create/edit artifacts | Manage projects | Manage users |
| --- | --- | --- | --- | --- |
| `1` | `administrator` | yes | yes | yes |
| `2` | `manager` | yes | yes | no |
| `3` | `developer` | yes | no | no |
| `4` | `viewer` (default) | no | no | no |

```bash
php artisan user:create --role=2   # creates a manager
```

### Project Membership Role

Assigned per project, controls project-level administration:

| `--role` | Permissions |
| --- | --- |
| `owner` | delete project, manage members, activate/deactivate modules |
| `member` (default) | read/write access within the limits of the global role |

A user must have **both** an appropriate global role (to act on artifacts) **and** be a member of the project (to access it).

```bash
# Add a user to a project (project role: member, global role unchanged)
php artisan project:add-member PROJ claude.dev

# Add and set global role at the same time
php artisan project:add-member PROJ claude.manager --role=owner --policy=manager

# Update an existing member
php artisan project:update-member PROJ claude.dev --policy=developer
php artisan project:update-member PROJ claude.dev --role=owner

# List / remove
php artisan project:members PROJ
php artisan project:remove-member PROJ claude.dev
```

---

## Database Management

### Create a User

```bash
php artisan user:create [--tenant=acme] [--role=4]
```

### Generate MCP Token

```bash
php artisan token:create <username> [--tenant=acme] [--name=default] [--expires=30d]
```

---

## Production Deployment

### Environment Configuration

- Set `APP_ENV=production`
- Use a strong `APP_KEY`
- Configure database credentials
- Enable HTTPS (MCP requires secure connections in production)

### Database

- Use managed MariaDB 11.8+ (AWS RDS, DigitalOcean, etc.)
- Run migrations: `php artisan migrate`
- Consider read replicas for scale

### Web Server

- Use **Nginx** with **PHP-FPM**
- Configure reverse proxy if behind load balancer
- Enable gzip compression

### Bearer Tokens

- Generate tokens via CLI: `php artisan artisan:token:create`
- Rotate tokens periodically
- Store securely in agent configuration

### Monitoring

- Monitor `php artisan queue:work` if using async jobs
- Log MCP requests: check `storage/logs/`
- Set up alerts for HTTP 5xx errors

---

## License

Apache License 2.0. See [LICENSE](LICENSE) file for details.
