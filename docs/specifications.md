# Functional Specifications — V2

## Poiesis — The Endless Coding

### Modular Agile Project Management Platform for AI Agents

---

## 1. Project Overview

### 1.1 Context

Poiesis is an agile project management platform designed to be driven exclusively by artificial intelligence agents. No human interacts directly with project data. All content creation, retrieval, and modification is performed by AI agents connected via the MCP (Model Context Protocol).

The platform provides the fundamental building blocks of agility (projects, epics, stories, tasks) without imposing any methodology. Processes (Scrum, Kanban, SAFe...) are provided by modules that can be independently enabled for each project.

### 1.2 Objectives

- Provide a minimal structural foundation for organizing work: projects, epics, stories, tasks.
- Enable AI agents to fully manage a project via the MCP protocol.
- Make the platform extensible through a module system that can be enabled per project.
- Guarantee unique identification of each work item through a system of human-readable identifiers.
- Secure access through token-based authentication and OAuth2.

### 1.3 Guiding Principles

- **The Core defines what we do. Modules define how we organize to do it.**
- **MCP is the sole entry point.** No human edits content. Only platform configuration is manually editable.
- **No methodology in the Core.** No workflow, no assignment, no sprint. These are module concepts. The Core only manages a basic status (draft, open, closed) and structural dependencies between items.
- **Extensibility through modules.** Each project enables the modules it needs.

---

## 2. Scope

### 2.1 Included (Core V2)

- Management of projects, epics, stories, and tasks
- Basic status for stories and tasks (draft, open, closed)
- Dependencies between stories and/or tasks (blocked_by / blocks)
- Relative ordering of stories within an epic and tasks within a story
- Batch operations (multiple creations in a single operation)
- Users, tokens, and OAuth2 authentication
- Unique identifier system
- Module system that can be enabled per project
- MCP server as the sole interface
- Business value configuration
- Search and filtering
- List pagination
- Markdown support in descriptions

### 2.2 Out of Scope (modules, not included in the Core)

- Workflow and process states (Scrum, Kanban)
- Sprints and iterations
- Task assignment
- Comments
- Time tracking
- Releases
- Notifications / webhooks
- Story templates
- Git integration
- Dashboards and statistics

---

## 3. Feature Summary

| Ref | Feature | Section |
|-----|---------|---------|
| F1 | Create a project | Projects |
| F2 | View a project | Projects |
| F3 | Update a project | Projects |
| F4 | Delete a project | Projects |
| F5 | List projects | Projects |
| F6 | Create a user | Users |
| F7 | Generate an access token | Authentication |
| F8 | Revoke an access token | Authentication |
| F9 | Authenticate via static token | Authentication |
| F10 | Authenticate via OAuth2 | Authentication |
| F11 | Register an OAuth2 client | Authentication |
| F12 | Refresh an OAuth2 token | Authentication |
| F13 | Add a member to a project | Members |
| F14 | Remove a member from a project | Members |
| F15 | Change a member's role | Members |
| F16 | List project members | Members |
| F17 | Create an epic | Epics |
| F18 | View an epic | Epics |
| F19 | Update an epic | Epics |
| F20 | Delete an epic | Epics |
| F21 | List epics for a project | Epics |
| F22 | Create a story | Stories |
| F23 | View a story | Stories |
| F24 | Update a story | Stories |
| F25 | Delete a story | Stories |
| F26 | List stories for a project | Stories |
| F27 | List stories for an epic | Stories |
| F28 | Filter stories | Stories |
| F29 | Create a standalone task | Tasks |
| F30 | Create a task linked to a story | Tasks |
| F31 | View a task | Tasks |
| F32 | Update a task | Tasks |
| F33 | Delete a task | Tasks |
| F34 | List tasks for a project | Tasks |
| F35 | List tasks for a story | Tasks |
| F36 | Filter tasks | Tasks |
| F37 | Automatic identifier assignment | Identifiers |
| F38 | Resolve an identifier | Identifiers |
| F39 | Search items by keyword | Identifiers |
| F40 | List available modules | Modules |
| F41 | Enable a module for a project | Modules |
| F42 | Disable a module for a project | Modules |
| F43 | List active modules for a project | Modules |
| F44 | View configuration values | Configuration |
| F45 | View a project summary | MCP Context |
| F46 | Paginate lists | Cross-cutting |
| F47 | Change the status of a story | Stories |
| F48 | Change the status of a task | Tasks |
| F49 | Order stories within an epic | Stories |
| F50 | Order tasks within a story | Tasks |
| F51 | Declare a dependency between items | Dependencies |
| F52 | Remove a dependency | Dependencies |
| F53 | View dependencies for an item | Dependencies |
| F54 | Create multiple stories in one operation | Batch |
| F55 | Create multiple tasks in one operation | Batch |
| F56 | Markdown support in descriptions | Cross-cutting |

---

## 4. Detailed Feature Descriptions

### Section 1 — Projects

#### F1 — Create a project

An AI agent creates a new project by providing a unique code, a title, and an optional description.

**Required data:**
- Project code: unique identifier, between 2 and 25 characters, composed of letters, digits, and hyphens
- Project title

**Optional data:**
- Description

**Result:** the project is created. Its code is final and cannot be changed. No module is enabled by default. The agent that creates the project automatically becomes its owner.

---

#### F2 — View a project

An AI agent views a project's information by providing its code.

**Returned information:**
- Code, title, description
- List of active modules
- Creation date

---

#### F3 — Update a project

An AI agent updates the title or description of a project. The project code cannot be changed.

---

#### F4 — Delete a project

An AI agent deletes a project. Deletion triggers the removal of all project entities: epics, stories, tasks, identifiers, and data from active modules.

**Condition:** only a project owner can delete it.

---

#### F5 — List projects

An AI agent views the list of projects it has access to (as owner or member).

---

### Section 2 — Users

#### F6 — Create a user

A user is created with a name. It represents an AI agent or a human operator.

**Required data:**
- Name

---

### Section 3 — Authentication

#### F7 — Generate an access token

A user generates an access token to connect to the platform. The token can be named (e.g., "token-dev", "agent-claude-1") and have an optional expiration date.

**Result:** the raw token is displayed once only. It will no longer be viewable afterward. The platform only stores a hash of the token.

---

#### F8 — Revoke an access token

A user revokes one of their tokens. The token becomes immediately unusable.

---

#### F9 — Authenticate via static token

An AI agent connects to the remote server by transmitting its token in the connection configuration. This mode is intended for command-line agents (Claude Code), CI scripts, and autonomous agents.

The client configures the remote server address and transmits the token with each request. The platform identifies the user from the token, verifies its validity and expiration date, then updates the last-used date.

---

#### F10 — Authenticate via OAuth2

An interactive client (e.g., Claude Desktop, Claude Code, third-party applications) connects to the platform via an OAuth2 flow. This mode is suited for clients that can handle an interactive authorization flow.

**Flow:**
1. The client redirects to an authorization screen hosted by the server
2. The user grants access
3. The client receives an authorization code
4. The client exchanges the code for an access token and a refresh token
5. The client uses the access token for MCP requests

**Rules:**
- PKCE verification is mandatory
- The access token has a short lifetime (configurable, 1 hour by default)
- The refresh token has a long lifetime (configurable, 30 days by default)
- Access scopes limit the available actions

---

#### F11 — Register an OAuth2 client

A client (e.g., Claude Desktop) can automatically register with the platform to obtain its OAuth2 connection credentials.

---

#### F12 — Refresh an OAuth2 token

A client exchanges a valid refresh token for a new access token, without re-requesting user authorization.

---

### Section 4 — Project Members

#### F13 — Add a member to a project

An owner adds a user as a project member by assigning them a role.

**Available roles:**
- **Owner**: full rights over the project (deletion, member management, module activation)
- **Member**: read and write access to project entities

The default role is "member". Available roles are configurable.

---

#### F14 — Remove a member from a project

An owner removes a member from the project. A project must always retain at least one owner.

---

#### F15 — Change a member's role

An owner changes the role of an existing member.

**Restriction:** if the member is the last owner, their role cannot be downgraded.

---

#### F16 — List project members

An AI agent views the list of a project's members along with their roles.

---

### Section 5 — Epics

#### F17 — Create an epic

An AI agent creates an epic within a project by providing a title and an optional description. A unique identifier is automatically assigned (see F37).

---

#### F18 — View an epic

An AI agent views an epic's information via its identifier.

**Returned information:**
- Identifier, title, description
- Number of associated stories
- Creation date

---

#### F19 — Update an epic

An AI agent updates the title or description of an epic.

---

#### F20 — Delete an epic

An AI agent deletes an epic. Deletion triggers the removal of all its stories and their child tasks.

---

#### F21 — List epics for a project

An AI agent views the list of epics for a project.

---

### Section 6 — Stories

#### F22 — Create a story

An AI agent creates a story within an epic by providing the following information.

**Required data:**
- Title
- Type (value from the configured list, e.g., backend, frontend, devops, qa)

**Optional data:**
- Description (supports Markdown, see F56)
- Work nature (e.g., feature, bug, improvement, spike, chore)
- Priority (e.g., critical, high, medium, low) — default: medium
- Order (relative position within the epic, see F49)
- Story points (complexity estimate)
- Reference document (link or path to a document)
- Tags (free-form list of labels)

The initial status is "draft" (see F47). A unique identifier is automatically assigned (see F37).

---

#### F23 — View a story

An AI agent views a story's information via its identifier.

**Returned information:**
- Identifier, title, description, type, nature, status, priority, order, story points, reference document, tags
- Parent epic
- Number of associated tasks
- Dependencies: blocking items (blocked_by) and blocked items (blocks)
- Creation date
- Additional data added by active modules

---

#### F24 — Update a story

An AI agent updates one or more properties of a story. The type, nature, priority, order, story points, reference document, and tags are editable. The identifier and parent epic are not editable. The status is changed via F47.

---

#### F25 — Delete a story

An AI agent deletes a story. Deletion triggers the removal of all its child tasks.

---

#### F26 — List stories for a project

An AI agent views the list of all stories for a project, across all epics.

---

#### F27 — List stories for an epic

An AI agent views the list of stories associated with a given epic.

---

#### F28 — Filter stories

An AI agent filters a project's stories by one or more criteria:
- By type
- By nature
- By status
- By priority
- By tag
- By text search in title and description

Criteria can be combined.

---

### Section 7 — Tasks

#### F29 — Create a standalone task

An AI agent creates a task directly within a project, without linking it to a story. This mode is suited for isolated bugs, hotfixes, or technical debt items.

**Required data:**
- Title
- Type

**Optional data:**
- Description (supports Markdown, see F56), nature, priority, order, time estimate (in minutes), tags

The initial status is "draft" (see F48). A unique identifier is automatically assigned (see F37).

---

#### F30 — Create a task linked to a story

An AI agent creates a task as a sub-item of an existing story.

Same data as F29. The task is linked to the story and will be deleted if the story is deleted.

---

#### F31 — View a task

An AI agent views a task's information via its identifier.

**Returned information:**
- Identifier, title, description, type, nature, status, priority, order, time estimate, tags
- Parent story (if applicable)
- Dependencies: blocking items (blocked_by) and blocked items (blocks)
- Creation date
- Additional data added by active modules

---

#### F32 — Update a task

An AI agent updates one or more properties of a task. The identifier is not editable. The link to a story is not editable after creation.

---

#### F33 — Delete a task

An AI agent deletes a task.

---

#### F34 — List tasks for a project

An AI agent views the list of all tasks for a project (both standalone and story children).

---

#### F35 — List tasks for a story

An AI agent views the list of tasks associated with a given story.

---

#### F36 — Filter tasks

An AI agent filters a project's tasks using the same criteria as stories (see F28): type, nature, status, priority, tag, text search.

---

### Section 8 — Identifiers

#### F37 — Automatic identifier assignment

When each epic, story, or task is created, the platform automatically assigns a unique identifier in the format `{PROJECT_CODE}-{N}` (e.g., `AGENTMG-1`, `AGENTMG-2`, `AGENTMG-3`).

**Rules:**
- The counter `N` is global to the project: it is shared across epics, stories, and tasks
- The counter is strictly increasing with no gaps
- Two items in the same project can never have the same identifier, even if they are of different types
- The identifier is final and cannot be changed
- Assignment is atomic: in case of simultaneous creation, no duplicates are possible

---

#### F38 — Resolve an identifier

An AI agent provides an identifier (e.g., `AGENTMG-3`) and the platform returns the corresponding complete item (epic, story, or task), regardless of its type.

---

#### F39 — Search items by keyword

An AI agent searches for items within a project by keyword. The search covers the titles and descriptions of epics, stories, and tasks.

---

### Section 9 — Modules

#### F40 — List available modules

An AI agent views the list of all modules installed on the platform, with each module's name, description, and required dependencies.

---

#### F41 — Enable a module for a project

An owner enables a module for their project.

**Rules:**
- If the module depends on other modules, those must be active beforehand
- A module that is already active cannot be enabled a second time

---

#### F42 — Disable a module for a project

An owner disables a module for their project.

**Rules:**
- If other active modules depend on this module, disabling is refused
- Disabling does not delete the module's data — it simply becomes inaccessible

---

#### F43 — List active modules for a project

An AI agent views the list of currently active modules for a given project.

---

### Section 10 — Configuration

#### F44 — View configuration values

An AI agent views the allowed values for the platform's business fields:
- Item types (e.g., backend, frontend, devops, qa)
- Statuses (e.g., draft, open, closed)
- Priorities (e.g., critical, high, medium, low)
- Work natures (e.g., feature, bug, improvement, spike, chore)
- Project roles (e.g., owner, member)
- Available OAuth2 scopes

These values are configurable by an administrator without modifying the data.

---

### Section 11 — MCP Context

#### F45 — View a project summary

An AI agent views a concise summary of a project: number of epics, number of stories, number of tasks, active modules. This summary serves as initial context for the agent.

---

### Section 12 — Cross-cutting

#### F46 — Paginate lists

All item lists (projects, epics, stories, tasks, members) are paginated. The AI agent can specify the page number and the number of items per page.

**Returned pagination information:**
- Current page
- Number of items per page
- Total number of items
- Total number of pages

---

### Section 13 — Basic Status

#### F47 — Change the status of a story

An AI agent changes the status of a story. The possible statuses are defined in the configuration (by default: draft, open, closed).

**Allowed transitions:**
- `draft` -> `open`: the item is ready to be worked on
- `open` -> `closed`: the item is completed or cancelled
- `closed` -> `open`: reopening a closed item

**Rules:**
- The initial status at creation is "draft"
- Only the listed transitions are allowed (no return to "draft" from "open")
- The Core status is distinct from module workflow states. A Workflow module can add intermediate process states (in_progress, in_review, etc.) without modifying the Core status

---

#### F48 — Change the status of a task

Same behavior as F47, applied to a task.

---

### Section 14 — Ordering

#### F49 — Order stories within an epic

An AI agent defines the relative order of stories within an epic. The order is a positive integer that determines the story's position in the sequence.

**Use cases:**
- Express sequential phases (Phase 1, Phase 2, Phase 3)
- Define an execution priority beyond the business priority (critical/high/medium/low)

**Rules:**
- The order is optional. Stories without an order have no defined position
- The order can be changed at any time via F24
- Multiple stories can have the same order (stable sort by creation date in case of ties)

---

#### F50 — Order tasks within a story

Same behavior as F49, applied to tasks within a story.

---

### Section 15 — Dependencies

#### F51 — Declare a dependency between items

An AI agent declares that an item (story or task) depends on another item (story or task). The dependent item is "blocked by" the item it depends on.

**Required data:**
- Identifier of the blocked item
- Identifier of the blocking item

**Rules:**
- Dependencies are cross-type: a story can depend on a task and vice versa
- An item can have multiple dependencies
- Circular dependencies are forbidden (A blocked by B, B blocked by A)
- Dependencies are informational — they do not prevent status changes

**Example:** story PROJ-22 (Phase 3) is blocked by PROJ-20 (Phase 2) and PROJ-21 (Phase 2).

---

#### F52 — Remove a dependency

An AI agent removes a dependency between two items.

---

#### F53 — View dependencies for an item

An AI agent views the dependencies of an item.

**Returned information:**
- List of blocking items (blocked_by): items that this item depends on
- List of blocked items (blocks): items that depend on this item

---

### Section 16 — Batch Operations

#### F54 — Create multiple stories in one operation

An AI agent creates multiple stories within an epic in a single operation, instead of making one call per story.

**Required data:**
- Target epic
- List of stories (each with the same data as F22)

**Rules:**
- All stories are created in the same operation (all or nothing)
- Identifiers are assigned sequentially
- If a validation error occurs on one story, no stories are created and the error specifies the faulty item
- The response returns the complete list of created stories with their identifiers

---

#### F55 — Create multiple tasks in one operation

Same behavior as F54, applied to tasks. Tasks can be created as children of a story or as standalone tasks.

---

### Section 17 — Cross-cutting (continued)

#### F56 — Markdown support in descriptions

The description fields of epics, stories, and tasks accept content in Markdown format.

**Rules:**
- Content is stored as plain text
- Markdown interpretation and rendering are the responsibility of the client (AI agent, interface)
- Supported Markdown elements: headings, lists, bold, italic, code (inline and blocks), links, tables
- No transformation or sanitization is applied by the platform

---

## 5. Cross-cutting Business Rules

| Ref | Rule |
|-----|------|
| R1 | A project must always have at least one owner |
| R2 | A project code is final and cannot be changed after creation |
| R3 | Item identifiers (`{CODE}-{N}`) are final and cannot be changed |
| R4 | Deleting a parent item triggers the deletion of all its children: project -> epics + standalone tasks, epic -> stories, story -> child tasks |
| R5 | A user can only access projects they are a member of |
| R6 | Only an owner can delete a project, manage its members, and enable/disable modules |
| R7 | Enabling a module verifies that its dependencies are satisfied |
| R8 | Disabling a module is refused if other active modules depend on it |
| R9 | Allowed values for type, nature, priority, and role fields are defined in configuration, not in the data |
| R10 | The MCP server is the sole entry point. No direct interaction with the data is possible outside of MCP |
| R11 | Tasks can exist as standalone (without a story) or as a child of a story |
| R12 | The same user can only appear once in a project's member list |
| R13 | A task's link to a story cannot be changed after creation |
| R14 | Every story and every task has a basic status (draft, open, closed). This status is distinct from module workflow states |
| R15 | Status transitions are constrained: draft -> open -> closed, and closed -> open (reopening). No return to draft |
| R16 | Dependencies between items are informational: they do not prevent status changes but express blocking relationships |
| R17 | Circular dependencies are forbidden |
| R18 | Batch operations are atomic: if an error occurs on one item, no items in the batch are created |

---

## 6. Edge Cases

### CL1 — Removal of the last owner

**Situation:** an owner attempts to remove themselves or be downgraded to member, while they are the only owner of the project.
**Expected behavior:** the operation is refused. The project must always have at least one owner. Another member must first be promoted to owner.

### CL2 — Enabling a module with missing dependencies

**Situation:** an agent attempts to enable the "Sprint" module which depends on the "Workflow" module, but "Workflow" is not active.
**Expected behavior:** enablement is refused with a message indicating the missing dependencies.

### CL3 — Disabling a module that other modules depend on

**Situation:** an agent attempts to disable "Workflow" while "Sprint" (which depends on it) is active.
**Expected behavior:** disabling is refused with a message listing the dependent modules.

### CL4 — Simultaneous item creation (identifier conflict)

**Situation:** two agents each create a story in the same project at the same time.
**Expected behavior:** each item receives a unique identifier. The assignment mechanism is atomic — no duplicates are possible, even under concurrency.

### CL5 — Expired token

**Situation:** an agent uses a token whose expiration date has passed.
**Expected behavior:** access is denied. The agent must generate a new token or refresh their OAuth2 token.

### CL6 — Expired OAuth2 token with valid refresh token

**Situation:** the OAuth2 access token has expired but the refresh token is still valid.
**Expected behavior:** the client exchanges the refresh token for a new access token without user intervention (F12).

### CL7 — Expired OAuth2 token with expired refresh token

**Situation:** both tokens (access and refresh) have expired.
**Expected behavior:** the client must restart the full authorization flow (F10).

### CL8 — Deletion of an epic containing stories with tasks

**Situation:** an epic contains stories, which themselves contain tasks.
**Expected behavior:** cascading deletion applies at all levels: epic -> stories -> tasks. All child entities and their identifiers are deleted.

### CL9 — Deletion of a story whose tasks have module data

**Situation:** a story contains tasks that have module data (e.g., status, assignment, comments via active modules).
**Expected behavior:** cascading deletion includes module data. Each module is responsible for cleaning up its own data when a Core entity is deleted.

### CL10 — Creating a standalone task then linking it to a story

**Situation:** an agent creates a standalone task and later wants to link it to a story.
**Expected behavior:** the link to a story is not editable after creation (R13). The agent must delete the standalone task and create a new one linked to the story.

### CL11 — Accessing a disabled module's endpoint

**Situation:** an agent attempts to use an MCP tool associated with a module that is not active for the project.
**Expected behavior:** the operation is refused with a message indicating that the module is not active for this project.

### CL12 — Project code with special characters

**Situation:** an agent attempts to create a project with a code containing unauthorized characters (accents, spaces, underscores, etc.).
**Expected behavior:** creation is refused. Only letters (A-Z, a-z), digits (0-9), and hyphens (-) are allowed, between 2 and 25 characters.

### CL13 — Duplicate project code

**Situation:** an agent attempts to create a project with a code already used by another project.
**Expected behavior:** creation is refused. The project code is unique across the entire platform.

### CL14 — Modifying type or priority with an unconfigured value

**Situation:** an agent updates a story by providing a type or priority that does not exist in the configuration.
**Expected behavior:** the update is refused. Only values present in the configuration are accepted.

### CL15 — Pagination beyond results

**Situation:** an agent requests page 99 when there are only 2 pages of results.
**Expected behavior:** an empty list is returned with pagination metadata indicating the total number of pages.

### CL16 — Disabling a module: what happens to the data?

**Situation:** a project has the "Comments" module active with comments on its stories. The owner disables the module.
**Expected behavior:** the module's data remains in the database but becomes inaccessible. If the module is re-enabled, the data becomes visible again. No data is deleted upon disabling.

### CL17 — Agent without project access

**Situation:** an authenticated agent attempts to access a project they are not a member of.
**Expected behavior:** access is denied. The agent can only see projects they are a member of.

### CL18 — Dynamic OAuth2 registration with malicious redirect URI

**Situation:** a client attempts to register with a redirect URI pointing to an unauthorized third-party domain.
**Expected behavior:** registration is accepted (the client declares its own URIs), but the user sees the redirect URI on the consent screen and can refuse authorization.

### CL19 — Invalid status transition

**Situation:** an agent attempts to change a story from "open" to "draft".
**Expected behavior:** the operation is refused. The allowed transitions are: draft -> open, open -> closed, closed -> open.

### CL20 — Circular dependency

**Situation:** story A depends on B, and an agent attempts to declare that B depends on A.
**Expected behavior:** the operation is refused. Circular dependencies (direct or transitive) are forbidden.

### CL21 — Dependency on a non-existent item

**Situation:** an agent declares a dependency on an identifier that does not exist.
**Expected behavior:** the operation is refused with a message indicating that the item does not exist.

### CL22 — Batch creation with an invalid item

**Situation:** an agent creates 5 stories in a batch, but the 3rd has an invalid type.
**Expected behavior:** no stories are created. The error specifies the index of the faulty item (index 2) and the field in error.

### CL23 — Deletion of an item that blocks other items

**Situation:** story A blocks stories B and C. An agent deletes story A.
**Expected behavior:** story A is deleted. Dependencies pointing to A are automatically removed. Stories B and C are no longer blocked by A.

---

## 7. Glossary

| Term | Definition |
|------|-----------|
| AI Agent | Artificial intelligence program interacting with the platform via MCP |
| MCP | Model Context Protocol — communication protocol between an AI agent and a tool server |
| Project | Root entity grouping all of a team's work |
| Epic | Functional grouping of stories around a business theme |
| Story | Work unit describing a feature, a bug, or an improvement |
| Task | Technical work sub-unit, standalone or linked to a story |
| Standalone task | Task linked directly to the project, without a parent story |
| Identifier | Unique code in the format `{PROJECT_CODE}-{N}` assigned to each epic, story, and task |
| Module | Per-project extension that adds functionality to the Core |
| Token | Authentication key allowing an agent to connect to the platform |
| OAuth2 | Authorization protocol allowing interactive clients to connect |
| PKCE | Security method for the OAuth2 flow for public clients |
| Owner | Project role granting full rights (deletion, member management, modules) |
| Member | Project role granting read/write access to entities |
| Scope | Limited access right assigned to an OAuth2 token |
| Status | Basic state of a story or task: draft, open, closed |
| Dependency | Blocking relationship between two items: one item depends on another to proceed |
| Order | Relative position of an item within its container (story in epic, task in story) |
| Batch | Grouped operation allowing multiple items to be created in a single request |
| Markdown | Lightweight text format used in item descriptions |
| Configuration | Set of business values (types, statuses, priorities, natures, roles) editable by an administrator |
