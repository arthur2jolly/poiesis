# MCP Tools Reference — Poiesis

Protocol version: **2025-03-26**
Server name: **Poiesis** — version 1.0.0

All tools are called via the JSON-RPC 2.0 method `tools/call`. Parameters are passed in
`params.arguments`. Responses are wrapped in a `content` array with a single `text` entry
containing a JSON-encoded payload.

---

## Table of contents

1. [Projects](#projects)
2. [Epics](#epics)
3. [Stories](#stories)
4. [Tasks](#tasks)
5. [Artifacts](#artifacts)
6. [Dependencies](#dependencies)
7. [Modules](#modules)
8. [MCP Resources](#mcp-resources)

---

## Business value reference

The following enumerated values are shared across Stories and Tasks.

| Field | Allowed values | Default |
|---|---|---|
| `type` | `backend`, `frontend`, `devops`, `qa` | — |
| `priorite` | `critique`, `haute`, `moyenne`, `basse` | `moyenne` |
| `statut` | `draft`, `open`, `closed` | `draft` |
| `nature` | `feature`, `bug`, `improvement`, `spike`, `chore` | — |

Status lifecycle: `draft` -> `open` -> `closed`. Transition `closed` -> `open` is also allowed.

---

## Projects

### `list_projects`

Lists all projects accessible by the authenticated agent.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `page` | integer | optional | Page number (default: 1) |
| `per_page` | integer | optional | Items per page, max 100 (default: 25) |

#### Return format

```json
{
  "data": [
    {
      "code": "MYAPP",
      "titre": "My Application",
      "description": "A sample project",
      "modules": ["kanban"],
      "created_at": "2025-01-15T10:00:00+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 3,
    "last_page": 1
  }
}
```

---

### `get_project`

Returns details of a single project identified by its code.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Unique project code |

#### Return format

```json
{
  "code": "MYAPP",
  "titre": "My Application",
  "description": "A sample project",
  "modules": ["kanban"],
  "created_at": "2025-01-15T10:00:00+00:00"
}
```

---

### `create_project`

Creates a new project. The authenticated user is automatically assigned the `owner` role.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `code` | string | required | Unique project code: 2–25 chars, pattern `[A-Za-z0-9-]` |
| `titre` | string | required | Project title |
| `description` | string | optional | Project description |

#### Return format

Same structure as `get_project`.

---

### `update_project`

Updates the title or description of a project.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |
| `titre` | string | optional | New title |
| `description` | string | optional | New description |

#### Return format

Same structure as `get_project`.

---

### `delete_project`

Permanently deletes a project. Only the project owner can perform this action.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |

#### Return format

```json
{ "message": "Project deleted." }
```

---

## Epics

An epic groups related stories. It receives an artifact identifier (e.g. `MYAPP-1`).

### `list_epics`

Lists epics belonging to a project, with the count of child stories.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |
| `page` | integer | optional | Page number (default: 1) |
| `per_page` | integer | optional | Items per page, max 100 (default: 25) |

#### Return format

```json
{
  "data": [
    {
      "identifier": "MYAPP-1",
      "titre": "User authentication",
      "description": "Everything related to auth",
      "stories_count": 4,
      "created_at": "2025-01-15T10:00:00+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 2,
    "last_page": 1
  }
}
```

---

### `get_epic`

Returns details of a single epic by its identifier.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `identifier` | string | required | Artifact identifier (e.g. `MYAPP-1`) |

#### Return format

Same structure as a single item in `list_epics`.

---

### `create_epic`

Creates an epic inside a project.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |
| `titre` | string | required | Epic title |
| `description` | string | optional | Epic description |

#### Return format

Same structure as `get_epic`.

---

### `update_epic`

Updates the title or description of an epic.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `identifier` | string | required | Artifact identifier |
| `titre` | string | optional | New title |
| `description` | string | optional | New description |

#### Return format

Same structure as `get_epic`.

---

### `delete_epic`

Permanently deletes an epic and all its child stories (cascade).

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `identifier` | string | required | Artifact identifier |

#### Return format

```json
{ "message": "Epic deleted." }
```

---

## Stories

A story belongs to an epic and may contain child tasks. It receives its own artifact identifier.

### `list_stories`

Lists all stories in a project with optional filters.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |
| `type` | string | optional | Filter by type (`backend`, `frontend`, `devops`, `qa`) |
| `nature` | string | optional | Filter by nature (`feature`, `bug`, `improvement`, `spike`, `chore`) |
| `statut` | string | optional | Filter by status (`draft`, `open`, `closed`) |
| `priorite` | string | optional | Filter by priority (`critique`, `haute`, `moyenne`, `basse`) |
| `tags` | string | optional | Comma-separated tags to filter on |
| `q` | string | optional | Full-text search in title and description |
| `page` | integer | optional | Page number (default: 1) |
| `per_page` | integer | optional | Items per page, max 100 (default: 25) |

#### Return format

```json
{
  "data": [
    {
      "identifier": "MYAPP-2",
      "titre": "Login page",
      "description": "Build the login form",
      "type": "frontend",
      "nature": "feature",
      "statut": "open",
      "priorite": "haute",
      "ordre": 1,
      "story_points": 3,
      "reference_doc": null,
      "tags": ["auth", "ui"],
      "tasks_count": 2,
      "created_at": "2025-01-16T08:00:00+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 10,
    "last_page": 1
  }
}
```

---

### `list_epic_stories`

Lists stories belonging to a specific epic, ordered by `ordre`.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |
| `epic_identifier` | string | required | Epic artifact identifier |
| `page` | integer | optional | Page number (default: 1) |
| `per_page` | integer | optional | Items per page, max 100 (default: 25) |

#### Return format

Same structure as `list_stories`.

---

### `get_story`

Returns full details of a story, including dependency identifiers.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `identifier` | string | required | Artifact identifier |

#### Return format

```json
{
  "identifier": "MYAPP-2",
  "titre": "Login page",
  "description": "Build the login form",
  "type": "frontend",
  "nature": "feature",
  "statut": "open",
  "priorite": "haute",
  "ordre": 1,
  "story_points": 3,
  "reference_doc": null,
  "tags": ["auth", "ui"],
  "tasks_count": 2,
  "created_at": "2025-01-16T08:00:00+00:00",
  "blocked_by": ["MYAPP-1"],
  "blocks": []
}
```

---

### `create_story`

Creates a single story inside an epic.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |
| `epic_identifier` | string | required | Parent epic identifier |
| `titre` | string | required | Story title |
| `type` | string | required | Item type (`backend`, `frontend`, `devops`, `qa`) |
| `description` | string | optional | Story description |
| `nature` | string | optional | Work nature (`feature`, `bug`, `improvement`, `spike`, `chore`) |
| `priorite` | string | optional | Priority (default: `moyenne`) |
| `ordre` | integer | optional | Display order |
| `story_points` | integer | optional | Estimation in story points |
| `reference_doc` | string | optional | URL or reference to external documentation |
| `tags` | array | optional | Array of tag strings |

#### Return format

Same structure as `get_story` (without `blocked_by` / `blocks`).

---

### `create_stories`

Creates multiple stories in a single atomic transaction. All stories are validated before
any insertion; a validation failure on any item rolls back the entire operation.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |
| `epic_identifier` | string | required | Parent epic identifier |
| `stories` | array | required | Array of story objects (see `create_story` for item fields) |

Each item in `stories` must include `titre` and `type`. All other fields are optional.

#### Return format

```json
{
  "data": [
    { "identifier": "MYAPP-3", "titre": "Story A", "..." : "..." },
    { "identifier": "MYAPP-4", "titre": "Story B", "..." : "..." }
  ]
}
```

---

### `update_story`

Updates one or more fields of a story. Only supplied fields are modified.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `identifier` | string | required | Artifact identifier |
| `titre` | string | optional | New title |
| `description` | string | optional | New description |
| `type` | string | optional | New type |
| `nature` | string | optional | New nature |
| `priorite` | string | optional | New priority |
| `ordre` | integer | optional | New display order |
| `story_points` | integer | optional | New story points estimate |
| `reference_doc` | string | optional | New reference document |
| `tags` | array | optional | New tag list (replaces existing) |

#### Return format

Same structure as `get_story` (without dependency fields).

---

### `delete_story`

Permanently deletes a story and all its child tasks.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `identifier` | string | required | Artifact identifier |

#### Return format

```json
{ "message": "Story deleted." }
```

---

### `update_story_status`

Transitions the status of a story. Valid transitions: `draft` -> `open`, `open` -> `closed`,
`closed` -> `open`.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `identifier` | string | required | Artifact identifier |
| `statut` | string | required | Target status (`draft`, `open`, `closed`) |

#### Return format

Same structure as `get_story` (without dependency fields).

---

## Tasks

A task is the smallest unit of work. It can be standalone (attached directly to a project) or
a child of a story.

### `list_tasks`

Lists all tasks in a project (standalone and children) with optional filters.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |
| `type` | string | optional | Filter by type |
| `nature` | string | optional | Filter by nature |
| `statut` | string | optional | Filter by status |
| `priorite` | string | optional | Filter by priority |
| `tags` | string | optional | Comma-separated tags |
| `q` | string | optional | Full-text search in title and description |
| `page` | integer | optional | Page number (default: 1) |
| `per_page` | integer | optional | Items per page, max 100 (default: 25) |

#### Return format

```json
{
  "data": [
    {
      "identifier": "MYAPP-5",
      "titre": "Set up CI pipeline",
      "description": null,
      "type": "devops",
      "nature": "chore",
      "statut": "draft",
      "priorite": "moyenne",
      "ordre": null,
      "estimation_temps": 120,
      "tags": ["ci"],
      "story_identifier": null,
      "standalone": true,
      "created_at": "2025-01-17T09:00:00+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 5,
    "last_page": 1
  }
}
```

---

### `list_story_tasks`

Lists tasks belonging to a specific story, ordered by `ordre`.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |
| `story_identifier` | string | required | Parent story identifier |
| `page` | integer | optional | Page number (default: 1) |
| `per_page` | integer | optional | Items per page, max 100 (default: 25) |

#### Return format

Same structure as `list_tasks`.

---

### `get_task`

Returns full details of a task including dependency identifiers.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `identifier` | string | required | Artifact identifier |

#### Return format

```json
{
  "identifier": "MYAPP-5",
  "titre": "Set up CI pipeline",
  "description": null,
  "type": "devops",
  "nature": "chore",
  "statut": "draft",
  "priorite": "moyenne",
  "ordre": null,
  "estimation_temps": 120,
  "tags": ["ci"],
  "story_identifier": null,
  "standalone": true,
  "created_at": "2025-01-17T09:00:00+00:00",
  "blocked_by": [],
  "blocks": ["MYAPP-6"]
}
```

---

### `create_task`

Creates a single task. Omitting `story_identifier` creates a standalone task at project level.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |
| `titre` | string | required | Task title |
| `type` | string | required | Item type (`backend`, `frontend`, `devops`, `qa`) |
| `story_identifier` | string | optional | Parent story identifier; omit for standalone task |
| `description` | string | optional | Task description |
| `nature` | string | optional | Work nature |
| `priorite` | string | optional | Priority (default: `moyenne`) |
| `ordre` | integer | optional | Display order |
| `estimation_temps` | integer | optional | Time estimate in minutes |
| `tags` | array | optional | Array of tag strings |

#### Return format

Same structure as `get_task` (without dependency fields).

---

### `create_tasks`

Creates multiple tasks in a single atomic transaction. All tasks are validated before any
insertion.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |
| `tasks` | array | required | Array of task objects (see `create_task` for item fields) |
| `story_identifier` | string | optional | If provided, all tasks are children of this story |

Each item in `tasks` must include `titre` and `type`. All other fields are optional.

#### Return format

```json
{
  "data": [
    { "identifier": "MYAPP-7", "titre": "Task A", "...": "..." },
    { "identifier": "MYAPP-8", "titre": "Task B", "...": "..." }
  ]
}
```

---

### `update_task`

Updates one or more fields of a task. Only supplied fields are modified.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `identifier` | string | required | Artifact identifier |
| `titre` | string | optional | New title |
| `description` | string | optional | New description |
| `type` | string | optional | New type |
| `nature` | string | optional | New nature |
| `priorite` | string | optional | New priority |
| `ordre` | integer | optional | New display order |
| `estimation_temps` | integer | optional | New time estimate in minutes |
| `tags` | array | optional | New tag list (replaces existing) |

#### Return format

Same structure as `get_task` (without dependency fields).

---

### `delete_task`

Permanently deletes a task.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `identifier` | string | required | Artifact identifier |

#### Return format

```json
{ "message": "Task deleted." }
```

---

### `update_task_status`

Transitions the status of a task. Valid transitions: `draft` -> `open`, `open` -> `closed`,
`closed` -> `open`.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `identifier` | string | required | Artifact identifier |
| `statut` | string | required | Target status (`draft`, `open`, `closed`) |

#### Return format

Same structure as `get_task` (without dependency fields).

---

## Artifacts

Cross-type utilities for resolving and searching items by their identifier.

### `resolve_artifact`

Resolves an artifact identifier to its complete entity, regardless of type (epic, story, task).

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `identifier` | string | required | Artifact identifier (e.g. `MYAPP-3`) |

#### Return format — epic

```json
{
  "type": "epic",
  "identifier": "MYAPP-1",
  "titre": "User authentication",
  "description": "Everything related to auth",
  "stories_count": 4,
  "created_at": "2025-01-15T10:00:00+00:00"
}
```

#### Return format — story

```json
{
  "type": "story",
  "identifier": "MYAPP-2",
  "titre": "Login page",
  "description": "Build the login form",
  "type": "frontend",
  "nature": "feature",
  "statut": "open",
  "priorite": "haute",
  "ordre": 1,
  "story_points": 3,
  "tags": ["auth"],
  "tasks_count": 2,
  "created_at": "2025-01-16T08:00:00+00:00"
}
```

#### Return format — task

```json
{
  "type": "task",
  "identifier": "MYAPP-5",
  "titre": "Set up CI pipeline",
  "description": null,
  "type": "devops",
  "nature": "chore",
  "statut": "draft",
  "priorite": "moyenne",
  "ordre": null,
  "estimation_temps": 120,
  "tags": ["ci"],
  "standalone": true,
  "created_at": "2025-01-17T09:00:00+00:00"
}
```

---

### `search_artifacts`

Searches epics, stories, and tasks by keyword within a project. Returns a flat list sorted
by relevance.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |
| `q` | string | required | Search keyword (matches title and description) |
| `page` | integer | optional | Page number (default: 1) |
| `per_page` | integer | optional | Items per page, max 100 (default: 25) |

#### Return format

```json
{
  "data": [
    { "type": "epic",  "identifier": "MYAPP-1", "titre": "User authentication" },
    { "type": "story", "identifier": "MYAPP-2", "titre": "Login page" },
    { "type": "task",  "identifier": "MYAPP-5", "titre": "Set up CI pipeline" }
  ]
}
```

---

## Dependencies

Manage blocking relationships between epics, stories, and tasks. A dependency means that
item A (`blocked_identifier`) cannot proceed until item B (`blocking_identifier`) is resolved.

### `add_dependency`

Declares that one item is blocked by another.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `blocked_identifier` | string | required | Identifier of the item that is blocked |
| `blocking_identifier` | string | required | Identifier of the item that is blocking |

#### Return format

```json
{ "message": "MYAPP-3 is now blocked by MYAPP-2." }
```

---

### `remove_dependency`

Removes an existing blocking relationship between two items.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `blocked_identifier` | string | required | Identifier of the blocked item |
| `blocking_identifier` | string | required | Identifier of the blocking item |

#### Return format

```json
{ "message": "Dependency removed." }
```

---

### `list_dependencies`

Lists all dependencies of an item: what it blocks and what blocks it.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `identifier` | string | required | Artifact identifier |

#### Return format

```json
{
  "identifier": "MYAPP-3",
  "blocked_by": [
    { "identifier": "MYAPP-2", "titre": "Login page" }
  ],
  "blocks": [
    { "identifier": "MYAPP-4", "titre": "Dashboard" }
  ]
}
```

---

## Modules

Modules are optional feature extensions that can be activated per project. Only the project
owner can activate or deactivate modules. Module activation enforces dependency order: a module
that depends on another module requires that dependency to be active first.

### `list_available_modules`

Lists all modules registered on the platform.

#### Parameters

None.

#### Return format

```json
{
  "data": [
    {
      "slug": "kanban",
      "name": "Kanban Board",
      "description": "Visual board for task management",
      "dependencies": []
    }
  ]
}
```

---

### `list_project_modules`

Lists slugs of all modules currently active for a given project.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |

#### Return format

```json
{ "data": ["kanban"] }
```

---

### `activate_module`

Activates a module for a project. Fails if the module is already active or if its declared
dependencies are not yet active.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |
| `slug` | string | required | Module slug |

#### Return format

```json
{ "data": ["kanban", "reports"] }
```

---

### `deactivate_module`

Deactivates a module for a project. Fails if another active module depends on it.

#### Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| `project_code` | string | required | Project code |
| `slug` | string | required | Module slug |

#### Return format

```json
{ "message": "Module 'kanban' deactivated." }
```

---

## MCP Resources

Resources are read-only data sources identified by a URI template. They are accessed via the
JSON-RPC method `resources/read` with `params.uri` set to the resolved URI.

### `project://{code}/overview`

**Name:** Project Overview

**Description:** Project summary with statistics: counts of epics, stories, and tasks, plus
the list of active modules.

#### URI parameters

| Name | Description |
|---|---|
| `code` | Project code |

#### Return format

```json
{
  "project_code": "MYAPP",
  "titre": "My Application",
  "description": "A sample project",
  "epics_count": 5,
  "stories_count": 22,
  "tasks_count": 47,
  "active_modules": ["kanban"]
}
```

---

### `project://{code}/config`

**Name:** Project Configuration

**Description:** Returns all allowed business values (types, priorities, natures, statuts,
roles, OAuth scopes) and the project-specific list of active modules. Useful for agents that
need to validate input values before calling a tool.

#### URI parameters

| Name | Description |
|---|---|
| `code` | Project code |

#### Return format

```json
{
  "project_code": "MYAPP",
  "item_types": ["backend", "frontend", "devops", "qa"],
  "priorities": ["critique", "haute", "moyenne", "basse"],
  "default_priority": "moyenne",
  "statuts": ["draft", "open", "closed"],
  "default_statut": "draft",
  "work_natures": ["feature", "bug", "improvement", "spike", "chore"],
  "project_roles": ["owner", "member"],
  "oauth_scopes": ["projects:read", "projects:write", "admin"],
  "active_modules": ["kanban"]
}
```
