# Cahier des charges -- Module Kanban

## Contexte et objectif

Poiesis gere le cycle de vie des artifacts (epics, stories, tasks) via un statut lineaire `draft -> open -> closed`. Ce modele est suffisant pour la planification et le suivi, mais ne repond pas au besoin de **gestion de flux visuel** pour les tasks standalone.

Le module Kanban introduit des boards visuels avec colonnes personnalisables pour piloter le flux des tasks standalone. Il permet aux agents IA (via MCP) de deplacer les tasks entre colonnes, de poser des limites de travail en cours (WIP limits), et de visualiser l'avancement dans le Dashboard (lecture seule).

**Valeur apportee :**
- Visibilite en temps reel du flux de travail sur les tasks standalone
- Limitation du travail en cours (WIP) pour eviter la surcharge
- Separation explicite entre le statut core d'une task (`draft`/`open`/`closed`) et sa position dans le flux de travail (colonne du board)

**Ce que le module Kanban n'est PAS :**
- Ce n'est pas un outil de sprint (pas de contrainte temporelle, c'est du flux pur)
- Ce n'est pas un remplacement du systeme de statuts core -- les deux coexistent

## Personas concernes

### Pour Greg (Coach Agile / Chef de projet IA)
Greg utilise les boards Kanban pour organiser visuellement le flux des tasks standalone d'un projet. Il cree les boards, configure les colonnes et les WIP limits, et deplace les tasks au fil de l'avancement. Il utilise le bulk close pour fermer toutes les tasks arrivees en "Done". Le board lui donne une vue synthetique de la charge en cours.

### Pour Sam / Joyce (Developpeur IA -- Backend)
Sam et Joyce consultent le board pour identifier les tasks a prendre (colonne "To Do"), signalent leur travail en cours en deplacant les tasks vers "WIP", et poussent les tasks vers "Done" une fois terminees. Ils ne gerent pas les colonnes ni les limites -- c'est le role de Greg.

### Pour Alice (QA / Tester IA)
Alice consulte le board pour voir quelles tasks sont en "Done" et pretes a etre validees. Elle peut deplacer les tasks entre colonnes si necessaire (ex: renvoyer une task de "Done" vers "WIP" si un defaut est detecte).

### Pour l'Operateur de Tenant
L'operateur active le module Kanban sur les projets via la configuration des modules. Il n'interagit pas directement avec les boards.

## Regles metier

### RM-01 -- Board lie a un projet
Un board Kanban appartient a exactement un projet. Un projet peut avoir plusieurs boards. Le board porte un nom (chaine libre, non vide).

### RM-02 -- Board par defaut a l'activation du module
Quand le module Kanban est active sur un projet, un board par defaut nomme "Kanban board" est automatiquement cree avec 3 colonnes dans l'ordre : "To Do" (position 0), "WIP" (position 1), "Done" (position 2). Aucune limite n'est posee par defaut sur ces colonnes.

### RM-03 -- Suppression d'un board
Un board ne peut etre supprime que s'il ne contient aucune task (toutes les colonnes du board sont vides). Toute tentative de suppression d'un board non vide est refusee avec une erreur explicite.

### RM-04 -- Suppression automatique d'un board sans colonnes
Si un board se retrouve avec 0 colonnes (apres suppression de la derniere colonne), il est automatiquement supprime. Cela implique que le board etait deja vide (RM-06 empeche la suppression d'une colonne non vide).

### RM-05 -- Colonnes ordonnees et personnalisables
Chaque board possede des colonnes ordonnees par un champ `position` (entier, 0-indexed). Les operations autorisees sur les colonnes sont : ajouter, renommer, reordonner, supprimer.

### RM-06 -- Suppression d'une colonne
Une colonne ne peut etre supprimee que si elle est vide (aucune task dedans). Toute tentative de suppression d'une colonne non vide est refusee avec une erreur explicite.

### RM-07 -- Limites WIP sur les colonnes
Chaque colonne possede deux limites optionnelles (nullable) :
- `limit_warning` (integer, nullable) : seuil d'avertissement (soft limit). Quand le nombre de tasks dans la colonne atteint ou depasse ce seuil, un indicateur visuel est affiche dans le Dashboard. Aucun blocage.
- `limit_hard` (integer, nullable) : seuil bloquant (hard limit). Quand le nombre de tasks dans la colonne atteint ce seuil, toute tentative d'ajouter ou deplacer une task vers cette colonne est refusee.

**Contrainte de coherence :** si les deux limites sont definies, `limit_warning` doit etre strictement inferieur a `limit_hard`. Si `limit_warning` est defini mais pas `limit_hard`, c'est valide. Si `limit_hard` est defini mais pas `limit_warning`, c'est valide.

### RM-08 -- Seules les tasks standalone entrent dans un board
Seules les tasks dont `story_id IS NULL` (standalone) peuvent etre ajoutees a un board. Toute tentative d'ajouter une task rattachee a une story est refusee.

### RM-09 -- Auto-transition draft vers open
Si une task en statut `draft` est ajoutee a un board, elle est automatiquement transitionnee vers `open` via `transitionStatus('open')` avant son insertion dans le board.

### RM-10 -- Une task dans un seul board a la fois
Une task ne peut etre presente que dans un seul board a la fois. Si une task deja presente dans le board A est ajoutee au board B, elle est retiree du board A et placee dans la premiere colonne (position 0) du board B, en derniere position dans cette colonne.

### RM-11 -- Deplacement entre colonnes sans changement de statut
Deplacer une task d'une colonne a une autre au sein d'un board ne modifie pas son statut core. La task reste `open` quel que soit le nom de la colonne.

### RM-12 -- Pas de fermeture automatique en colonne "Done"
Les tasks placees dans une colonne nommee "Done" (ou toute autre colonne) ne sont pas automatiquement fermees. La fermeture est un acte explicite via un tool MCP dedie (bulk close ou fermeture individuelle via le tool core existant).

### RM-13 -- Sortie automatique du board a la fermeture
Quand une task passe en statut `closed` (par n'importe quel moyen : tool MCP core ou bulk close Kanban), elle est automatiquement retiree du board. Les positions des tasks restantes dans la colonne sont recompactees.

### RM-14 -- Reajout d'une task reopen au board
Une task qui repasse en `open` (depuis `closed`) peut etre ajoutee a un board comme n'importe quelle task standalone open.

### RM-15 -- Sortie automatique du board si rattachee a une story
Si une task standalone presente dans un board est rattachee a une story (`story_id` passe de NULL a une valeur), elle est automatiquement retiree du board. Les positions des tasks restantes dans la colonne sont recompactees.

### RM-16 -- Ordre des tasks dans une colonne
Les tasks dans une colonne ont un ordre (champ `position`, entier, 0-indexed). Les tools MCP permettent de reordonner les tasks au sein d'une colonne. Lors de l'ajout d'une task a une colonne, elle est placee en derniere position.

### RM-17 -- Permissions
Les utilisateurs avec le role `Manager` ou `Developer` (roles globaux 2 et 3 dans `config/core.php`) et qui sont membres du projet peuvent manipuler les boards, colonnes et tasks via MCP. Les `Viewer` (role 4) ne peuvent que consulter le board dans le Dashboard. Les `Administrator` (role 1) ont tous les droits.

### RM-18 -- Hard limit et deplacement
La verification de la hard limit lors d'un deplacement de task vers une colonne cible prend en compte le nombre actuel de tasks dans la colonne cible. Si ce nombre est egal ou superieur a `limit_hard`, le deplacement est refuse. Le deplacement au sein de la meme colonne (reordonnement) n'est pas soumis a la hard limit.

### RM-19 -- Bulk close
Le tool MCP "bulk close" ferme toutes les tasks d'une colonne donnee. Pour chaque task, la transition `open -> closed` est appliquee, et la task sort du board (RM-13). Si une task ne peut pas etre transitionnee (etat incoherent), elle est ignoree et signalee dans la reponse.

## Cas limites identifies

| # | Cas | Comportement attendu |
|---|-----|---------------------|
| CL-01 | Task `draft` ajoutee au board | Auto-transition vers `open` (RM-09), puis insertion dans le board |
| CL-02 | Task `closed` ajoutee au board | Refuse -- une task `closed` ne peut pas etre ajoutee. Elle doit d'abord etre reopen |
| CL-03 | Task deja dans board A, ajoutee au board B | Retiree du board A, placee en premiere colonne du board B en derniere position (RM-10) |
| CL-04 | Suppression colonne avec tasks | Refuse -- la colonne doit etre vide (RM-06) |
| CL-05 | Suppression board avec tasks | Refuse -- le board doit etre vide (RM-03) |
| CL-06 | Board avec 0 colonnes | Board automatiquement supprime (RM-04) |
| CL-07 | Task rattachee a une story alors qu'elle est dans un board | Sort automatiquement du board (RM-15) |
| CL-08 | Hard limit atteinte sur colonne cible | Refuse d'ajouter/deplacer une task vers cette colonne (RM-18) |
| CL-09 | Warning limit atteinte | Pas de blocage, indicateur visuel dans le Dashboard uniquement |
| CL-10 | Ajout d'une task non-standalone au board | Refuse -- seules les tasks standalone sont acceptees (RM-08) |
| CL-11 | Suppression du board par defaut | Autorise comme tout board, sous reserve qu'il soit vide (RM-03) |
| CL-12 | Reordonnement des colonnes avec colonnes non consecutives | Le tool MCP recoit l'ordre complet des colonnes et reassigne les positions de 0 a N-1 |
| CL-13 | Task supprimee alors qu'elle est dans un board | La relation board-task doit etre supprimee en cascade |
| CL-14 | Projet supprime avec des boards actifs | Suppression en cascade : projet -> boards -> colonnes -> relations board-task |
| CL-15 | `limit_warning` >= `limit_hard` | Refuse a la creation/mise a jour de la colonne (RM-07) |
| CL-16 | `limit_warning` = 0 ou `limit_hard` = 0 | Les limites doivent etre des entiers strictement positifs si definies |
| CL-17 | Bulk close sur colonne vide | Succes avec 0 tasks fermees, pas d'erreur |
| CL-18 | Deux agents ajoutent une task au meme board simultanement | La contrainte d'unicite task-board en base de donnees gere la concurrence. Un seul insert reussit, l'autre recoit une erreur de doublon |

## Impacts sur les cahiers des charges existants

### Schema de base de donnees (`docs/schema.md`)
Ajout de 3 nouvelles tables :
- `kanban_boards` (id, project_id, name, created_at, updated_at) -- FK project_id CASCADE
- `kanban_columns` (id, board_id, name, position, limit_warning, limit_hard, created_at, updated_at) -- FK board_id CASCADE
- `kanban_board_task` (id, column_id, task_id, position, created_at, updated_at) -- FK column_id CASCADE, FK task_id CASCADE, UNIQUE(task_id)

La contrainte `UNIQUE(task_id)` sur `kanban_board_task` garantit RM-10 (une task dans un seul board a la fois).

### Modele Task (`app/Core/Models/Task.php`)
Le modele Task ne doit pas etre modifie directement. La relation avec le board est geree par le module Kanban via un modele pivot dedie. Cependant, un **listener** est necessaire pour :
- Detecter le passage a `closed` -> retirer du board (RM-13)
- Detecter le rattachement a une story (`story_id` passe de NULL a une valeur) -> retirer du board (RM-15)

### Module Dashboard
Si le module Kanban est active sur un projet, un onglet "Kanban" doit apparaitre dans le menu de navigation du projet dans le Dashboard. La vue est 100% lecture seule (pas de create, update, delete via l'IHM). L'onglet affiche les boards avec leurs colonnes et cards, ainsi que les indicateurs visuels de limites.

### Config modules (`config/modules.php`)
Ajout de l'entree `'kanban' => KanbanModule::class`.

## Outils MCP

### Boards
| Tool | Description |
|------|-------------|
| `kanban_board_create` | Cree un board dans un projet |
| `kanban_board_list` | Liste les boards d'un projet |
| `kanban_board_get` | Retourne un board avec ses colonnes et le nombre de tasks par colonne |
| `kanban_board_update` | Renomme un board |
| `kanban_board_delete` | Supprime un board (doit etre vide) |

### Colonnes
| Tool | Description |
|------|-------------|
| `kanban_column_create` | Ajoute une colonne a un board (en derniere position) |
| `kanban_column_list` | Liste les colonnes d'un board avec le nombre de tasks |
| `kanban_column_update` | Renomme, reordonne, ou modifie les limites d'une colonne |
| `kanban_column_delete` | Supprime une colonne (doit etre vide) |
| `kanban_column_reorder` | Reordonne toutes les colonnes d'un board (recoit la liste ordonnee des IDs) |

### Tasks dans le board
| Tool | Description |
|------|-------------|
| `kanban_task_add` | Ajoute une task standalone a un board (premiere colonne, derniere position) |
| `kanban_task_remove` | Retire une task d'un board |
| `kanban_task_move` | Deplace une task vers une colonne cible a une position donnee |
| `kanban_task_reorder` | Reordonne les tasks dans une colonne (recoit la liste ordonnee des IDs) |
| `kanban_task_list` | Liste les tasks d'un board ou d'une colonne specifique |

### Actions bulk
| Tool | Description |
|------|-------------|
| `kanban_column_close_tasks` | Ferme toutes les tasks d'une colonne (RM-19) |

## Decisions sur les questions ouvertes

| # | Question | Decision |
|---|----------|----------|
| QO-1 | tenant_id sur kanban_boards ? | **Option A retenue.** Pas de tenant_id, isolation via project_id. Coherent avec epics/stories/tasks. |
| QO-2 | Creation du board par defaut ? | **Option C retenue.** Hook dans `registerListeners()` du module : listener Eloquent sur `Project::saved` qui detecte l'ajout du slug `kanban` dans le champ `modules` et cree le board par defaut. |
| QO-3 | Colonne cible a l'ajout ? | **Option B retenue.** Colonne cible optionnelle en parametre de `kanban_task_add`, defaut = premiere colonne (position 0). |
| QO-4 | Unicite du nom de board ? | **Option B retenue.** Pas de contrainte d'unicite sur le nom. |
| QO-5 | Mecanisme d'activation | L'activation passe par `ModuleController::activate()` et le tool MCP `activate_module` qui ajoutent le slug dans `$project->modules` (JSON) puis `$project->save()`. Le listener Eloquent sur `Project::saved` detectera ce changement. |

## Changelog

| Date | Auteur | Description |
|------|--------|-------------|
| 2026-03-29 | product agent | Version initiale |
| 2026-03-29 | arthur | Decisions QO-1 a QO-5 validees |
