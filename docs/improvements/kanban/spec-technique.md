# Spec technique -- Module Kanban

## Resume

Cette spec couvre l'implementation du module Kanban : trois tables de base de donnees (`kanban_boards`, `kanban_columns`, `kanban_board_task`), trois modeles Eloquent, un listener sur `Project::saved` pour la creation du board par defaut, un listener sur `Task` pour les sorties automatiques du board, une classe MCP `KanbanTools` exposant 12 tools, et une vue Dashboard en lecture seule.

## Schema de base de donnees

### Table `kanban_boards`

```
kanban_boards
├── id            UUID PRIMARY KEY
├── project_id    UUID NOT NULL  FK -> projects(id) CASCADE DELETE
├── name          VARCHAR(255) NOT NULL
├── created_at    TIMESTAMP
└── updated_at    TIMESTAMP

INDEX: project_id
```

Migration : `app/Modules/Kanban/Database/Migrations/2026_03_29_000001_create_kanban_boards_table.php`

```php
Schema::create('kanban_boards', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('project_id');
    $table->string('name', 255);
    $table->timestamps();

    $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
    $table->index('project_id');
});
```

Pas de `tenant_id` (QO-1). Pas de contrainte d'unicite sur `name` (QO-4).

### Table `kanban_columns`

```
kanban_columns
├── id             UUID PRIMARY KEY
├── board_id       UUID NOT NULL  FK -> kanban_boards(id) CASCADE DELETE
├── name           VARCHAR(255) NOT NULL
├── position       INTEGER NOT NULL DEFAULT 0
├── limit_warning  INTEGER NULLABLE
├── limit_hard     INTEGER NULLABLE
├── created_at     TIMESTAMP
└── updated_at     TIMESTAMP

INDEX: board_id
INDEX: (board_id, position)
```

Migration : `app/Modules/Kanban/Database/Migrations/2026_03_29_000002_create_kanban_columns_table.php`

```php
Schema::create('kanban_columns', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('board_id');
    $table->string('name', 255);
    $table->unsignedInteger('position')->default(0);
    $table->unsignedInteger('limit_warning')->nullable();
    $table->unsignedInteger('limit_hard')->nullable();
    $table->timestamps();

    $table->foreign('board_id')->references('id')->on('kanban_boards')->cascadeOnDelete();
    $table->index('board_id');
    $table->index(['board_id', 'position']);
});
```

### Table `kanban_board_task`

```
kanban_board_task
├── id          UUID PRIMARY KEY
├── column_id   UUID NOT NULL  FK -> kanban_columns(id) CASCADE DELETE
├── task_id     UUID NOT NULL  FK -> tasks(id) CASCADE DELETE  UNIQUE
├── position    INTEGER NOT NULL DEFAULT 0
├── created_at  TIMESTAMP
└── updated_at  TIMESTAMP

UNIQUE: task_id
INDEX: (column_id, position)
```

Migration : `app/Modules/Kanban/Database/Migrations/2026_03_29_000003_create_kanban_board_task_table.php`

```php
Schema::create('kanban_board_task', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('column_id');
    $table->uuid('task_id');
    $table->unsignedInteger('position')->default(0);
    $table->timestamps();

    $table->foreign('column_id')->references('id')->on('kanban_columns')->cascadeOnDelete();
    $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
    $table->unique('task_id');
    $table->index(['column_id', 'position']);
});
```

La contrainte `UNIQUE(task_id)` garantit RM-10 au niveau base de donnees.

## Modeles / Entites

### `App\Modules\Kanban\Models\KanbanBoard`

```
Traits   : HasUuids
Table    : kanban_boards
Fillable : project_id, name
Relations:
  - project()  -> BelongsTo<Project>
  - columns()  -> HasMany<KanbanColumn> (orderBy position)
```

Methode `format()`:
```php
[
    'id'         => string,
    'project_id' => string,
    'name'       => string,
    'columns'    => array (optionnel, si charge),
    'created_at' => ISO 8601,
    'updated_at' => ISO 8601,
]
```

### `App\Modules\Kanban\Models\KanbanColumn`

```
Traits   : HasUuids
Table    : kanban_columns
Fillable : board_id, name, position, limit_warning, limit_hard
Casts    : position -> integer, limit_warning -> integer (nullable), limit_hard -> integer (nullable)
Relations:
  - board() -> BelongsTo<KanbanBoard>
  - boardTasks() -> HasMany<KanbanBoardTask> (orderBy position)
  - tasks() -> HasManyThrough<Task> via KanbanBoardTask
```

Methode `taskCount()` : retourne `$this->boardTasks()->count()`.

Methode `isAtHardLimit()` : retourne `$this->limit_hard !== null && $this->taskCount() >= $this->limit_hard`.

Methode `isAtWarningLimit()` : retourne `$this->limit_warning !== null && $this->taskCount() >= $this->limit_warning`.

Methode `format()`:
```php
[
    'id'            => string,
    'board_id'      => string,
    'name'          => string,
    'position'      => int,
    'limit_warning' => int|null,
    'limit_hard'    => int|null,
    'task_count'    => int,
    'at_warning'    => bool,
    'at_hard_limit' => bool,
]
```

### `App\Modules\Kanban\Models\KanbanBoardTask`

```
Traits   : HasUuids
Table    : kanban_board_task
Fillable : column_id, task_id, position
Casts    : position -> integer
Relations:
  - column() -> BelongsTo<KanbanColumn>
  - task()   -> BelongsTo<Task>
```

Methode `format()`:
```php
[
    'id'          => string,
    'column_id'   => string,
    'column_name' => string,
    'task'        => array (Task::format()),
    'position'    => int,
]
```

## Listeners

### `KanbanProjectSavedListener`

Fichier : `App\Modules\Kanban\Listeners\KanbanProjectSavedListener`

Declencheur : `Project::saved` (Eloquent event), enregistre via `KanbanModule::registerListeners()` avec `Event::listen(...)` ou `Project::saved(callback)`.

Logique :
1. Recuperer le champ `modules` avant et apres la sauvegarde (`$project->getOriginal('modules')` vs `$project->modules`).
2. Si `'kanban'` est present dans le nouveau tableau mais absent de l'ancien (ou si l'ancien est `null`) :
   - Creer un `KanbanBoard` avec `name = 'Kanban board'` et `project_id = $project->id`.
   - Creer 3 `KanbanColumn` dans ce board : `('To Do', position 0)`, `('WIP', position 1)`, `('Done', position 2)`. Aucune limite.
3. Ne rien faire si `'kanban'` etait deja present.

### `KanbanTaskObserver`

Fichier : `App\Modules\Kanban\Listeners\KanbanTaskObserver`

Enregistre via `KanbanModule::registerListeners()` avec `Task::observe(KanbanTaskObserver::class)`.

Methode `updated(Task $task)` :

1. **Sortie sur fermeture (RM-13)** : si `$task->isDirty('statut')` et `$task->statut === 'closed'`, supprimer l'entree `KanbanBoardTask` correspondante (si existante), puis recompacter les positions dans la colonne.
2. **Sortie sur rattachement a une story (RM-15)** : si `$task->isDirty('story_id')` et `$task->story_id !== null` et `$task->getOriginal('story_id') === null`, supprimer l'entree `KanbanBoardTask` correspondante (si existante), puis recompacter les positions dans la colonne.

Methode de recompactage des positions : pour la colonne impactee, recuperer toutes les `KanbanBoardTask` ordonnees par `position`, puis reassigner les positions de 0 a N-1 en batch update.

## Tools MCP

Classe : `App\Modules\Kanban\Mcp\KanbanTools` implementant `McpToolInterface`.

Enregistree via `KanbanModule::mcpTools()` retournant `[new KanbanTools]`.

### Helpers partages

Les helpers suivent le pattern de `DocumentTools` :

- `findProjectWithAccess(string $code, User $user): Project` -- resout le projet et verifie l'appartenance via `ProjectMember`.
- `assertCanManageBoard(User $user): void` -- verifie `Role::canCrudArtifacts($user->role)` (Admin, Manager, Developer). Les Viewer ne peuvent pas manipuler.
- `findBoard(string $boardId, string $projectId): KanbanBoard` -- resout le board et verifie qu'il appartient au projet.
- `findColumn(string $columnId, string $boardId): KanbanColumn` -- resout la colonne et verifie qu'elle appartient au board.
- `recompactPositions(string $columnId): void` -- reassigne les positions 0..N-1 dans une colonne.
- `recompactColumnPositions(string $boardId): void` -- reassigne les positions 0..N-1 des colonnes d'un board.

### Tool `kanban_board_create`

**Description** : Create a Kanban board in a project.

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "name": { "type": "string", "description": "Board name" }
  },
  "required": ["project_code", "name"]
}
```

**Logique** :
1. `assertCanManageBoard($user)`
2. `findProjectWithAccess($params['project_code'], $user)`
3. Valider que `name` est non vide (trim).
4. Creer `KanbanBoard` avec `project_id` et `name`.
5. Retourner `$board->format()`.

**Reponse** : `KanbanBoard::format()`

**Erreurs** :
- `name` vide -> `ValidationException` `{'name': ['Board name is required.']}`
- Pas d'acces au projet -> `ValidationException` `{'project': ['Access denied.']}`
- Role Viewer -> `ValidationException` `{'board': ['You do not have permission to manage boards.']}`

### Tool `kanban_board_list`

**Description** : List Kanban boards of a project.

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" }
  },
  "required": ["project_code"]
}
```

**Logique** :
1. `findProjectWithAccess($params['project_code'], $user)`
2. Recuperer tous les boards du projet avec `withCount` sur les colonnes, ordonne par `created_at`.
3. Pour chaque board, charger les colonnes avec leur `task_count` (via `withCount('boardTasks')`).

**Reponse** :
```json
{
  "data": [
    {
      "id": "uuid",
      "name": "string",
      "columns": [
        { "id": "uuid", "name": "string", "position": 0, "task_count": 3, "limit_warning": null, "limit_hard": null, "at_warning": false, "at_hard_limit": false }
      ],
      "created_at": "ISO 8601",
      "updated_at": "ISO 8601"
    }
  ]
}
```

**Pas de restriction de role** pour la lecture : tous les membres du projet (y compris Viewer) peuvent lister les boards.

### Tool `kanban_board_get`

**Description** : Get a Kanban board with its columns and task counts.

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "board_id": { "type": "string", "description": "Board UUID" }
  },
  "required": ["project_code", "board_id"]
}
```

**Logique** :
1. `findProjectWithAccess`
2. `findBoard($params['board_id'], $project->id)`
3. Eager load `columns.boardTasks.task` ordonnees par position.
4. Retourner le board avec ses colonnes et les tasks formatees dans chaque colonne.

**Reponse** :
```json
{
  "id": "uuid",
  "name": "string",
  "project_id": "uuid",
  "columns": [
    {
      "id": "uuid",
      "name": "string",
      "position": 0,
      "limit_warning": null,
      "limit_hard": null,
      "task_count": 2,
      "at_warning": false,
      "at_hard_limit": false,
      "tasks": [
        { "identifier": "PROJ-12", "titre": "...", "position": 0 }
      ]
    }
  ],
  "created_at": "ISO 8601",
  "updated_at": "ISO 8601"
}
```

Les tasks dans la reponse incluent les champs de `Task::format()` enrichis du champ `position` (position dans la colonne).

### Tool `kanban_board_update`

**Description** : Rename a Kanban board.

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "board_id": { "type": "string", "description": "Board UUID" },
    "name": { "type": "string", "description": "New board name" }
  },
  "required": ["project_code", "board_id", "name"]
}
```

**Logique** :
1. `assertCanManageBoard($user)`
2. `findProjectWithAccess`, `findBoard`
3. Valider que `name` est non vide.
4. `$board->update(['name' => $params['name']])`
5. Retourner `$board->format()`.

### Tool `kanban_board_delete`

**Description** : Delete a Kanban board (must be empty).

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "board_id": { "type": "string", "description": "Board UUID" }
  },
  "required": ["project_code", "board_id"]
}
```

**Logique** :
1. `assertCanManageBoard($user)`
2. `findProjectWithAccess`, `findBoard`
3. Verifier que le board n'a aucune task : `KanbanBoardTask::whereIn('column_id', $board->columns()->pluck('id'))->exists()`. Si `true`, refuser.
4. `$board->delete()` (cascade supprime les colonnes).
5. Retourner `{'message': 'Board deleted.'}`.

**Erreur** : `ValidationException` `{'board': ['Cannot delete a board that contains tasks. Remove all tasks first.']}`

### Tool `kanban_column_create`

**Description** : Add a column to a Kanban board (appended at the end).

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "board_id": { "type": "string", "description": "Board UUID" },
    "name": { "type": "string", "description": "Column name" },
    "limit_warning": { "type": "integer", "description": "Warning threshold (optional)" },
    "limit_hard": { "type": "integer", "description": "Hard limit (optional)" }
  },
  "required": ["project_code", "board_id", "name"]
}
```

**Logique** :
1. `assertCanManageBoard($user)`
2. `findProjectWithAccess`, `findBoard`
3. Valider `name` non vide.
4. Valider les limites (voir section "Validation des limites WIP").
5. Calculer la position : `$board->columns()->max('position') + 1` (ou 0 si aucune colonne).
6. Creer `KanbanColumn`.
7. Retourner `$column->format()`.

### Tool `kanban_column_list`

**Description** : List columns of a Kanban board with task counts.

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "board_id": { "type": "string", "description": "Board UUID" }
  },
  "required": ["project_code", "board_id"]
}
```

**Logique** :
1. `findProjectWithAccess`, `findBoard`
2. Recuperer les colonnes du board ordonnees par `position`, avec `withCount('boardTasks')`.
3. Retourner `{'data': [KanbanColumn::format(), ...]}`.

### Tool `kanban_column_update`

**Description** : Update a column (name, limits).

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "board_id": { "type": "string", "description": "Board UUID" },
    "column_id": { "type": "string", "description": "Column UUID" },
    "name": { "type": "string", "description": "New column name (optional)" },
    "limit_warning": { "type": ["integer", "null"], "description": "Warning threshold (null to remove)" },
    "limit_hard": { "type": ["integer", "null"], "description": "Hard limit (null to remove)" }
  },
  "required": ["project_code", "board_id", "column_id"]
}
```

**Logique** :
1. `assertCanManageBoard($user)`
2. `findProjectWithAccess`, `findBoard`, `findColumn`
3. Construire le tableau de donnees a mettre a jour (seulement les cles presentes dans `$params`).
4. Valider les limites avec les valeurs finales (fusionner les nouvelles valeurs avec les valeurs existantes).
5. Si `name` est present, valider non vide.
6. `$column->update($data)`
7. Retourner `$column->format()`.

Note : pour permettre de mettre a `null` une limite existante, le tool accepte `null` comme valeur valide pour `limit_warning` et `limit_hard`. La detection de "cle presente" se fait via `array_key_exists` et non `isset`.

### Tool `kanban_column_delete`

**Description** : Delete a column (must be empty).

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "board_id": { "type": "string", "description": "Board UUID" },
    "column_id": { "type": "string", "description": "Column UUID" }
  },
  "required": ["project_code", "board_id", "column_id"]
}
```

**Logique** :
1. `assertCanManageBoard($user)`
2. `findProjectWithAccess`, `findBoard`, `findColumn`
3. Verifier que la colonne est vide : `$column->boardTasks()->exists()`. Si `true`, refuser.
4. Sauvegarder `$boardId = $column->board_id`.
5. `$column->delete()`
6. Recompacter les positions des colonnes restantes du board.
7. **RM-04** : si `KanbanColumn::where('board_id', $boardId)->count() === 0`, supprimer le board.
8. Retourner `{'message': 'Column deleted.'}` ou `{'message': 'Column deleted. Board was empty and has been deleted.'}` selon le cas.

**Erreur** : `ValidationException` `{'column': ['Cannot delete a column that contains tasks. Remove all tasks first.']}`

### Tool `kanban_column_reorder`

**Description** : Reorder all columns of a board.

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "board_id": { "type": "string", "description": "Board UUID" },
    "column_ids": { "type": "array", "items": { "type": "string" }, "description": "Ordered list of all column UUIDs" }
  },
  "required": ["project_code", "board_id", "column_ids"]
}
```

**Logique** :
1. `assertCanManageBoard($user)`
2. `findProjectWithAccess`, `findBoard`
3. Verifier que `column_ids` contient exactement les memes UUIDs que les colonnes du board (ni plus, ni moins).
4. Pour chaque ID dans `column_ids`, assigner `position = index` (0-indexed).
5. Retourner la liste des colonnes formatees dans le nouvel ordre.

**Erreurs** :
- IDs manquants ou en trop -> `ValidationException` `{'column_ids': ['The provided column IDs do not match the board columns.']}`

### Tool `kanban_task_add`

**Description** : Add a standalone task to a Kanban board.

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "board_id": { "type": "string", "description": "Board UUID" },
    "task_identifier": { "type": "string", "description": "Task artifact identifier (e.g. PROJ-12)" },
    "column_id": { "type": "string", "description": "Target column UUID (optional, defaults to first column)" }
  },
  "required": ["project_code", "board_id", "task_identifier"]
}
```

**Logique** :
1. `assertCanManageBoard($user)`
2. `findProjectWithAccess`, `findBoard`
3. Resoudre la task via `Artifact::resolveIdentifier($params['task_identifier'])`. Verifier que c'est une instance de `Task`.
4. Verifier que la task appartient au meme projet.
5. **RM-08** : verifier `$task->isStandalone()`. Si non, refuser.
6. **CL-02** : verifier que `$task->statut !== 'closed'`. Si `closed`, refuser avec message explicite.
7. **RM-09** : si `$task->statut === 'draft'`, appeler `$task->transitionStatus('open')`.
8. Determiner la colonne cible (QO-3) :
   - Si `column_id` fourni : `findColumn($params['column_id'], $board->id)`.
   - Sinon : `$board->columns()->orderBy('position')->first()`. Si le board n'a aucune colonne, refuser.
9. **RM-18** : verifier `$targetColumn->isAtHardLimit()`. Si oui, refuser.
10. **RM-10** : verifier si la task est deja dans un board. Si oui, supprimer l'entree existante et recompacter la colonne source.
11. Calculer la position : `$targetColumn->boardTasks()->max('position') + 1` (ou 0).
12. Creer `KanbanBoardTask` avec `column_id`, `task_id`, `position`. Gerer `QueryException` pour la contrainte unique (CL-18 / concurrence).
13. Retourner la `KanbanBoardTask::format()`.

**Erreurs** :
- Task non standalone -> `ValidationException` `{'task': ['Only standalone tasks (not linked to a story) can be added to a board.']}`
- Task closed -> `ValidationException` `{'task': ['Cannot add a closed task to a board. Reopen it first.']}`
- Hard limit atteinte -> `ValidationException` `{'column': ['Column has reached its hard limit (N). Cannot add more tasks.']}`
- Board sans colonnes -> `ValidationException` `{'board': ['Board has no columns. Add a column first.']}`
- Erreur concurrence (UNIQUE violation) -> `ValidationException` `{'task': ['Task is already on a board (concurrent operation).']}`

### Tool `kanban_task_remove`

**Description** : Remove a task from a Kanban board.

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "board_id": { "type": "string", "description": "Board UUID" },
    "task_identifier": { "type": "string", "description": "Task artifact identifier" }
  },
  "required": ["project_code", "board_id", "task_identifier"]
}
```

**Logique** :
1. `assertCanManageBoard($user)`
2. `findProjectWithAccess`, `findBoard`
3. Resoudre la task.
4. Trouver `KanbanBoardTask` pour cette task dans une colonne du board. Si non trouve, refuser.
5. Sauvegarder `$columnId`.
6. Supprimer l'entree.
7. Recompacter les positions de la colonne.
8. Retourner `{'message': 'Task removed from board.'}`.

**Erreur** : `ValidationException` `{'task': ['Task is not on this board.']}`

### Tool `kanban_task_move`

**Description** : Move a task to a target column at a given position.

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "board_id": { "type": "string", "description": "Board UUID" },
    "task_identifier": { "type": "string", "description": "Task artifact identifier" },
    "column_id": { "type": "string", "description": "Target column UUID" },
    "position": { "type": "integer", "description": "Target position in column (0-indexed, optional, defaults to last)" }
  },
  "required": ["project_code", "board_id", "task_identifier", "column_id"]
}
```

**Logique** :
1. `assertCanManageBoard($user)`
2. `findProjectWithAccess`, `findBoard`, `findColumn($params['column_id'], $board->id)`
3. Resoudre la task. Trouver son `KanbanBoardTask`. Si non trouve, refuser.
4. Determiner si c'est un deplacement inter-colonnes ou un reordonnement intra-colonne.
5. **RM-18** : si deplacement vers une colonne differente, verifier `$targetColumn->isAtHardLimit()`. Si oui, refuser. Le reordonnement intra-colonne n'est pas soumis a la hard limit.
6. Mettre a jour `$boardTask->column_id` et `$boardTask->position`.
7. Pour la position cible :
   - Si `position` fourni : inserer a cette position. Decaler toutes les tasks avec `position >= target` de +1 dans la colonne cible (avant l'insertion). Puis assigner la position cible.
   - Si `position` non fourni : placer en dernier (`max(position) + 1`).
8. Recompacter les positions de la colonne source (si changement de colonne).
9. Recompacter les positions de la colonne cible.
10. Retourner la `KanbanBoardTask::format()` mise a jour.

**Erreur hard limit** : `ValidationException` `{'column': ['Column has reached its hard limit (N). Cannot move tasks here.']}`

### Tool `kanban_task_reorder`

**Description** : Reorder tasks within a column.

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "board_id": { "type": "string", "description": "Board UUID" },
    "column_id": { "type": "string", "description": "Column UUID" },
    "task_ids": { "type": "array", "items": { "type": "string" }, "description": "Ordered list of task UUIDs (task IDs, not identifiers)" }
  },
  "required": ["project_code", "board_id", "column_id", "task_ids"]
}
```

**Logique** :
1. `assertCanManageBoard($user)`
2. `findProjectWithAccess`, `findBoard`, `findColumn`
3. Verifier que `task_ids` contient exactement les memes task IDs que les `KanbanBoardTask` de la colonne.
4. Pour chaque task ID, assigner `position = index`.
5. Retourner la liste des tasks formatees dans le nouvel ordre.

### Tool `kanban_task_list`

**Description** : List tasks on a board or in a specific column.

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "board_id": { "type": "string", "description": "Board UUID" },
    "column_id": { "type": "string", "description": "Column UUID (optional, list all board tasks if omitted)" }
  },
  "required": ["project_code", "board_id"]
}
```

**Logique** :
1. `findProjectWithAccess`, `findBoard`
2. Si `column_id` fourni : `findColumn`, recuperer les `KanbanBoardTask` de la colonne avec la task eager-loaded, ordonnees par `position`.
3. Sinon : recuperer toutes les `KanbanBoardTask` du board (via les colonnes), groupees par colonne, ordonnees par `position`.
4. Retourner `{'data': [...]}`.

### Tool `kanban_column_close_tasks`

**Description** : Close all tasks in a column (bulk close). Tasks are transitioned to `closed` and removed from the board.

**inputSchema** :
```json
{
  "type": "object",
  "properties": {
    "project_code": { "type": "string", "description": "Project code" },
    "board_id": { "type": "string", "description": "Board UUID" },
    "column_id": { "type": "string", "description": "Column UUID" }
  },
  "required": ["project_code", "board_id", "column_id"]
}
```

**Logique** :
1. `assertCanManageBoard($user)`
2. `findProjectWithAccess`, `findBoard`, `findColumn`
3. Recuperer toutes les `KanbanBoardTask` de la colonne avec leur task.
4. Pour chaque entry :
   a. Tenter `$task->transitionStatus('closed')`.
   b. Si succes : la task sort du board automatiquement via `KanbanTaskObserver` (RM-13). Incrementer compteur `closed`.
   c. Si `ValidationException` (etat incoherent) : ajouter le `task_identifier` a la liste `skipped` avec la raison.
5. Retourner :
```json
{
  "closed_count": 5,
  "skipped": [
    { "identifier": "PROJ-99", "reason": "Transition from 'draft' to 'closed' is not allowed." }
  ]
}
```

**CL-17** : si la colonne est vide, retourner `{"closed_count": 0, "skipped": []}`.

Note : la boucle de fermeture ne doit PAS etre wrappee dans une transaction globale. Chaque task est traitee individuellement afin que les succes partiels soient preserves (RM-19).

## Regles metier techniques

### Validation des limites WIP

Appliquee lors de `kanban_column_create` et `kanban_column_update` :

1. Si `limit_warning` est fourni et non null : doit etre un entier strictement positif (>= 1). Sinon `ValidationException` `{'limit_warning': ['Warning limit must be a positive integer.']}`.
2. Si `limit_hard` est fourni et non null : doit etre un entier strictement positif (>= 1). Sinon `ValidationException` `{'limit_hard': ['Hard limit must be a positive integer.']}`.
3. Si les deux limites sont definies (non null) apres fusion avec les valeurs existantes : `limit_warning` doit etre strictement inferieur a `limit_hard`. Sinon `ValidationException` `{'limit_warning': ['Warning limit must be less than hard limit.']}`.

### Recompactage des positions

Utilise apres toute operation qui peut creer des trous dans la sequence de positions (suppression de task du board, deplacement, suppression de colonne). Algorithme :

```
SELECT id FROM kanban_board_task WHERE column_id = ? ORDER BY position ASC
Pour chaque row a l'index i : UPDATE SET position = i
```

Meme logique pour les positions de colonnes dans un board.

### Verification hard limit

La hard limit est verifiee avant l'insertion/deplacement. Le nombre de tasks dans la colonne cible est calcule au moment de la verification. En cas de concurrence, la contrainte UNIQUE sur `task_id` empeche les doublons, mais deux tasks differentes pourraient depasser la hard limit simultanement. Ce risque est accepte comme negligeable (pas de lock pessimiste).

### Resolution de task par identifier

Toutes les operations sur les tasks dans le board utilisent l'identifier artifact (`PROJ-12`) et non le task UUID. La resolution passe par `Artifact::resolveIdentifier()` suivi d'une verification `instanceof Task`.

Exception : `kanban_task_reorder` utilise les task UUIDs (pas les identifiers) car il recoit une liste ordonnee et les UUIDs sont plus efficaces pour le batch update.

## Cas limites techniques

| # | Cas | Implementation |
|---|-----|----------------|
| CL-01 | Task `draft` ajoutee | `kanban_task_add` appelle `$task->transitionStatus('open')` avant insertion |
| CL-02 | Task `closed` ajoutee | `kanban_task_add` refuse avec ValidationException |
| CL-03 | Task deja dans un board | `kanban_task_add` supprime l'entree existante (dans n'importe quel board), recompacte, puis insere dans le nouveau board |
| CL-04 | Suppression colonne non vide | `kanban_column_delete` verifie `boardTasks()->exists()` et refuse |
| CL-05 | Suppression board non vide | `kanban_board_delete` verifie via `KanbanBoardTask::whereIn(...)` et refuse |
| CL-06 | Board avec 0 colonnes | `kanban_column_delete` verifie le count apres suppression et supprime le board |
| CL-07 | Task rattachee a une story | `KanbanTaskObserver::updated()` detecte le changement `story_id` et supprime l'entree |
| CL-08 | Hard limit atteinte | `kanban_task_add` et `kanban_task_move` verifient `isAtHardLimit()` avant operation |
| CL-09 | Warning limit | Pas de blocage. L'information `at_warning` est exposee dans `KanbanColumn::format()` et affichee visuellement dans le Dashboard |
| CL-10 | Task non standalone | `kanban_task_add` verifie `$task->isStandalone()` |
| CL-11 | Suppression board par defaut | Aucune logique speciale, le board par defaut est traite comme tout autre board |
| CL-12 | Reordonnement colonnes | `kanban_column_reorder` reassigne les positions 0..N-1 selon l'ordre fourni |
| CL-13 | Task supprimee (hard delete) | `CASCADE DELETE` sur `kanban_board_task.task_id` supprime l'entree automatiquement |
| CL-14 | Projet supprime | `CASCADE DELETE` : `projects -> kanban_boards -> kanban_columns -> kanban_board_task` |
| CL-15 | `limit_warning >= limit_hard` | Validation refuse avec `ValidationException` |
| CL-16 | Limites a 0 | Validation refuse (doit etre >= 1) |
| CL-17 | Bulk close sur colonne vide | Retourne `{"closed_count": 0, "skipped": []}` |
| CL-18 | Concurrence sur ajout | Contrainte `UNIQUE(task_id)` en BD. Catch `QueryException` et retourne erreur |

## Integration Dashboard

### Route

Ajouter dans `DashboardModule::registerRoutes()` :
```
GET /dashboard/{code}/kanban -> DashboardController::kanban()
```
Nom de route : `dashboard.kanban`.

### Controleur

Ajouter la methode `kanban(Request $request, string $code): View` dans `DashboardController` :
1. Resoudre le projet (meme pattern que `documents()`).
2. Verifier que le module `kanban` est actif sur le projet (`in_array('kanban', $project->modules ?? [], true)`).
3. Charger les boards du projet avec colonnes et `boardTasks` count.
4. Retourner la vue `dashboard::project.kanban`.

### Onglet navigation

Modifier `project-tabs.blade.php` pour ajouter un onglet conditionnel :
```php
$hasKanban = in_array('kanban', $project->modules ?? [], true);
// Ajouter apres l'onglet Documents :
if ($hasKanban) {
    $tabs[] = ['route' => route('dashboard.kanban', $project->code), 'label' => 'Kanban', 'key' => 'kanban'];
}
```

### Vue

Fichier : `app/Modules/Dashboard/Resources/views/project/kanban.blade.php`

Vue en lecture seule affichant :
- Liste des boards du projet.
- Pour chaque board : les colonnes en disposition horizontale (flex row).
- Pour chaque colonne : header avec nom, indicateurs WIP (warning = badge orange, hard limit = badge rouge), et les cards de tasks.
- Chaque card affiche : identifier, titre, priorite, statut.
- Pas de drag & drop, pas de formulaire, pas d'action -- 100% lecture seule.

## Contraintes transversales applicables

- **Multi-tenant** : l'isolation est garantie par `project_id`. Toutes les requetes passent par la verification d'acces projet (`ProjectMember`). Pas de `tenant_id` sur les tables Kanban (QO-1).
- **Auth** : toutes les operations MCP sont protegees par `auth.bearer` + `project.access` + `module.active:kanban` (gere par le middleware stack).
- **Roles** : les operations de lecture (`board_list`, `board_get`, `column_list`, `task_list`) sont ouvertes a tous les membres. Les operations d'ecriture verifient `Role::canCrudArtifacts()` (Admin, Manager, Developer). Les Viewer ne peuvent que consulter via Dashboard.
- **Pas de soft delete** : les entites Kanban n'utilisent pas le soft delete. Les suppressions sont definitives avec cascade.
- **UUIDs** : toutes les tables utilisent des UUID v4 comme cles primaires (trait `HasUuids`).

## Structure des fichiers

```
app/Modules/Kanban/
├── KanbanModule.php
├── Database/
│   └── Migrations/
│       ├── 2026_03_29_000001_create_kanban_boards_table.php
│       ├── 2026_03_29_000002_create_kanban_columns_table.php
│       └── 2026_03_29_000003_create_kanban_board_task_table.php
├── Listeners/
│   ├── KanbanProjectSavedListener.php
│   └── KanbanTaskObserver.php
├── Mcp/
│   └── KanbanTools.php
└── Models/
    ├── KanbanBoard.php
    ├── KanbanColumn.php
    └── KanbanBoardTask.php
```

Modifications dans les fichiers existants :
- `config/modules.php` : ajout de `'kanban' => KanbanModule::class`
- `app/Modules/Dashboard/Http/Controllers/DashboardController.php` : ajout methode `kanban()`
- `app/Modules/Dashboard/DashboardModule.php` : ajout route `/dashboard/{code}/kanban`
- `app/Modules/Dashboard/Resources/views/components/project-tabs.blade.php` : ajout onglet conditionnel
- `app/Modules/Dashboard/Resources/views/project/kanban.blade.php` : nouvelle vue

## Fichiers de reference

| Fichier | Role |
|---------|------|
| `app/Modules/Document/DocumentModule.php` | Modele pour `KanbanModule.php` |
| `app/Modules/Document/Mcp/DocumentTools.php` | Modele pour `KanbanTools.php` (pattern helpers, inputSchema) |
| `app/Modules/Document/Models/Document.php` | Modele pour les modeles Kanban (HasUuids, format()) |
| `app/Modules/Document/Database/Migrations/2026_03_22_000001_create_documents_table.php` | Modele pour les migrations |
| `app/Core/Models/Task.php` | Modele Task avec `isStandalone()`, `transitionStatus()`, `format()` |
| `app/Core/Models/Concerns/HasStatusTransitions.php` | Trait de transitions de statut |
| `app/Core/Support/Role.php` | Helpers de roles (`canCrudArtifacts`) |
| `app/Core/Mcp/Tools/MemberTools.php` | Modele pour les tools avec verifications de permissions |
| `app/Modules/Dashboard/Http/Controllers/DashboardController.php` | Modele pour la methode `kanban()` |
| `app/Modules/Dashboard/Resources/views/components/project-tabs.blade.php` | Onglet a modifier |

## Dependances

- Le module `Dashboard` doit etre present pour l'onglet Kanban (mais le module Kanban peut fonctionner sans Dashboard -- l'onglet est conditionnel).
- Le modele `Task` et le trait `HasStatusTransitions` sont des dependances core.
- Le systeme `Artifact::resolveIdentifier()` est une dependance core.
- Aucune dependance externe (package Composer) n'est requise.

## Changelog

- 2026-03-29 Creation initiale
