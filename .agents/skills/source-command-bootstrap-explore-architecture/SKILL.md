---
name: "source-command-bootstrap-explore-architecture"
description: "Phase 1b — Explore l'architecture du projet, les patterns d'implémentation, les conventions observées, et identifie les fichiers de référence par type de tâche. Produit docs/bootstrap/02-architecture.md. Exécuter dans une nouvelle conversation."
---

# source-command-bootstrap-explore-architecture

Use this skill when the user asks to run the migrated source command `bootstrap-explore-architecture`.

## Command Template

Utilise le subagent bootstrap-explorer pour analyser l'architecture et les patterns d'implémentation.

## Stratégie d'exploration

### Étape 1 — Carte de la structure
Utilise Glob pour cartographier les dossiers src/ (ou app/, lib/) sur 3 niveaux max.
Identifie le pattern architectural dominant : MVC, modules, feature-based, layers...

### Étape 2 — Sélectionner 2 modules représentatifs
Choisis 2 modules/features qui semblent complets (pas des stubs). Préfère des modules de taille moyenne, pas le plus petit ni le plus grand.

### Étape 3 — Lire un module de A à Z
Pour chaque module sélectionné, lire dans l'ordre :
- Le fichier d'entrée (index, module, routes)
- Le service / logique métier
- Le controller / handler / vue
- Le DTO / schéma de validation
- Le fichier de test si présent
- La migration ou modèle DB si présent

### Étape 4 — Observer les patterns
À travers ces lectures, noter :
- Convention de nommage des fichiers
- Structure interne des classes/fonctions
- Comment les dépendances sont injectées
- Comment les erreurs sont gérées et retournées
- Comment les réponses sont formatées
- Présence de décorateurs, middlewares, hooks

## Output attendu

Sauvegarder dans `docs/bootstrap/02-architecture.md`.

Inclure une section critique :

```markdown
## Fichiers de référence identifiés
| Type de tâche | Fichier de référence (chemin exact) |
|---------------|-------------------------------------|
| Nouveau service | [chemin] |
| Nouveau endpoint GET | [chemin] |
| Nouveau endpoint POST | [chemin] |
| Nouvelle migration DB | [chemin] |
| Nouveau DTO/schéma | [chemin] |
| Nouveau composant | [chemin si applicable] |
| Nouveau test unitaire | [chemin] |
| Nouveau test intégration | [chemin si applicable] |
```

Ne remplir une ligne que si tu as un chemin réel à fournir. Laisser vide sinon.

Afficher à la fin : "✅ docs/bootstrap/02-architecture.md créé. Prochaine étape : `/bootstrap-explore-constraints` dans une nouvelle conversation."
