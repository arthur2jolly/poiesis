# Poiesis — Agile Workflow Guide

## Hierarchy
Project → Epics → Stories → Tasks

- **Project**: top-level container identified by a unique `code` (e.g. `POIESIS`).
- **Epic**: a large body of work grouping related stories. Identified as `PROJ-N` (e.g. `POIESIS-1`).
- **Story**: a user-facing feature or requirement within an epic. Identified as `PROJ-N`.
- **Task**: a concrete unit of work, either standalone or attached to a story. Identified as `PROJ-N`.

Artifacts (epics, stories, tasks) share a single identifier sequence per project.

## Statuses
All artifacts follow the same lifecycle:
`draft` → `open` → `closed`

- **draft**: created but not yet ready for work.
- **open**: actively being worked on.
- **closed**: done. Closing a story automatically closes all its child tasks.

Status transitions are one-directional except `closed → open` (re-open).

## Workflow steps
1. Create the **project** (`create_project`).
2. Create **epics** to group themes of work (`create_epic`).
3. Break epics into **stories** (`create_story` / `create_stories` for bulk).
4. Attach **tasks** to stories or create standalone tasks (`create_task` / `create_tasks`).
5. Open items when work starts (`update_story_status`, `update_task_status`).
6. Close items when done. Closing a story cascades to its tasks.

## Dependencies
Use `add_dependency` to declare that an artifact is blocked by another.
Always resolve blockers before opening a story or task.
Use `list_dependencies` to inspect the dependency graph.

## Modules
Modules extend project capabilities (e.g. additional workflows).
Activate with `activate_module`, deactivate with `deactivate_module`.
Some modules have dependencies that must be activated first.

## Conventions
- Use `resolve_artifact` when you only have an identifier and need full details.
- Use `search_artifacts` to find items by keyword before creating duplicates.
- Prefer `create_stories` / `create_tasks` for bulk creation — it is atomic.
- Always set a meaningful `titre` and `type`; `priorite` and `nature` improve filtering.
