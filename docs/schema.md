# Database Schema — Poiesis Core

## Tables Overview

| Table | Description | PK |
|-------|-------------|-----|
| `projects` | Root entity, container for all work | uuid v7 |
| `users` | AI agent or operator identity | uuid v7 |
| `api_tokens` | Static Bearer tokens for authentication | uuid v7 |
| `oauth_clients` | OAuth2 registered clients | uuid v7 |
| `oauth_authorization_codes` | Short-lived OAuth2 authorization codes | uuid v7 |
| `oauth_access_tokens` | OAuth2 access tokens | uuid v7 |
| `oauth_refresh_tokens` | OAuth2 refresh tokens | uuid v7 |
| `project_members` | Pivot: user membership in projects | uuid v7 |
| `epics` | Functional grouping of stories | uuid v7 |
| `stories` | Work units within an epic | uuid v7 |
| `tasks` | Technical sub-units (standalone or child of story) | uuid v7 |
| `artifacts` | Centralized registry of business identifiers | uuid v7 |
| `item_dependencies` | Blocking relationships between stories/tasks | uuid v7 |

## Relationships Diagram

```
users
  |
  |-- 1:N --> api_tokens              (user_id FK, CASCADE)
  |-- 1:N --> oauth_clients           (user_id FK, CASCADE, NULLABLE)
  |-- N:M --> projects                (via project_members)
  |
  |-- 1:N --> oauth_authorization_codes (user_id FK, CASCADE)
  |-- 1:N --> oauth_access_tokens       (user_id FK, CASCADE)

oauth_clients
  |-- 1:N --> oauth_authorization_codes (oauth_client_id FK, CASCADE)
  |-- 1:N --> oauth_access_tokens       (oauth_client_id FK, CASCADE)

oauth_access_tokens
  |-- 1:1 --> oauth_refresh_tokens      (access_token_id FK, CASCADE)

projects
  |-- 1:N --> epics                   (project_id FK, CASCADE)
  |-- 1:N --> tasks [standalone]      (project_id FK, CASCADE, story_id NULL)
  |-- 1:N --> artifacts               (project_id FK, CASCADE)
  |-- N:M --> users                   (via project_members)

project_members
  |-- N:1 --> projects                (project_id FK, CASCADE)
  |-- N:1 --> users                   (user_id FK, CASCADE)
  |-- UNIQUE(project_id, user_id)

epics
  |-- N:1 --> projects                (project_id FK, CASCADE)
  |-- 1:N --> stories                 (epic_id FK, CASCADE)

stories
  |-- N:1 --> epics                   (epic_id FK, CASCADE)
  |-- 1:N --> tasks [children]        (story_id FK, CASCADE)

tasks
  |-- N:1 --> projects                (project_id FK, CASCADE)
  |-- N:1 --> stories [optional]      (story_id FK, CASCADE, NULLABLE)

artifacts (polymorphic)
  |-- N:1 --> projects                (project_id FK, CASCADE)
  |-- morphTo --> epics | stories | tasks  (artifactable_id + artifactable_type)
  |-- UNIQUE(identifier)
  |-- UNIQUE(project_id, sequence_number)

item_dependencies (polymorphic)
  |-- item_id + item_type         --> stories | tasks  (blocked item)
  |-- depends_on_id + depends_on_type --> stories | tasks  (blocking item)
  |-- UNIQUE(item_id, item_type, depends_on_id, depends_on_type)
```

## Cascade Deletion Chain

```
Project --> Epics --> Stories --> Tasks (children)
Project --> Tasks (standalone)
Project --> Artifacts
Project --> ProjectMembers

User --> ApiTokens
User --> OAuthClients --> OAuthAuthorizationCodes
User --> OAuthAccessTokens --> OAuthRefreshTokens
User --> ProjectMembers
```

## Key Constraints

- `projects.code`: UNIQUE, immutable after creation, 2-25 chars [A-Za-z0-9-]
- `project_members`: UNIQUE(project_id, user_id) — one entry per user per project
- `artifacts.identifier`: UNIQUE globally — format `{CODE}-{N}`
- `artifacts(project_id, sequence_number)`: UNIQUE — sequential per project
- `item_dependencies`: UNIQUE on all 4 polymorphic columns — no duplicate dependencies
- All business values (type, nature, statut, priorite, role) are varchar(20) — configured in `config/core.php`, not DB enums
