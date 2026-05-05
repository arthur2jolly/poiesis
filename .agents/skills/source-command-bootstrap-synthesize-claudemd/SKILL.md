---
name: "source-command-bootstrap-synthesize-claudemd"
description: "Phase 1e — Synthétise les 4 rapports d'exploration pour produire AGENTS.md et les .Codex/rules/ correspondants. Exécuter dans une nouvelle conversation après que les 4 rapports bootstrap existent."
---

# source-command-bootstrap-synthesize-claudemd

Use this skill when the user asks to run the migrated source command `bootstrap-synthesize-claudemd`.

## Command Template

Utilise le subagent bootstrap-synthesizer pour générer AGENTS.md et les rules.

## Prérequis — vérifier avant de commencer

Vérifier que ces 4 fichiers existent :
- `docs/bootstrap/01-stack.md`
- `docs/bootstrap/02-architecture.md`
- `docs/bootstrap/03-contraintes.md`
- `docs/bootstrap/04-tests.md`

Si l'un manque → STOP et afficher : "❌ Fichier manquant : [fichier]. Exécuter d'abord `/bootstrap-explore-[phase]`."

## Ce que tu génères

### 1. AGENTS.md (racine du projet)

Lire les 4 rapports et synthétiser selon le format défini dans bootstrap-synthesizer.
- Maximum 150 lignes
- Chaque information doit être tracée à un rapport source
- Les `[À COMPLÉTER]` restent visibles — ils guident la révision humaine
- Inclure la section "Pipeline feature" et "Bootstrap" exactement comme dans le template
- Inclure la section "Tests" avec les commandes exactes trouvées en phase 1d

### 2. .Codex/rules/ — un fichier par domaine détecté

Pour chaque domaine technique identifié dans 02-architecture.md et 03-contraintes.md :

**Si API/Backend :** créer `.Codex/rules/api.md`
→ Règles de validation, format de réponse, pagination, auth — depuis 03-contraintes.md

**Si Base de données :** créer `.Codex/rules/database.md`
→ Conventions de migration, soft delete, contraintes, index — depuis 02-architecture.md + 03-contraintes.md

**Si Frontend :** créer `.Codex/rules/frontend.md`
→ Conventions de composants, routing, state — depuis 02-architecture.md

**Si Tests :** créer `.Codex/rules/tests.md`
→ Convention de nommage, structure, commandes — depuis 04-tests.md

Chaque rule file utilise le frontmatter `paths:` avec les globs appropriés (observés dans 02-architecture.md).

### 3. Mettre à jour docs/bootstrap/00-plan.md

Marquer Phase 1 comme complétée :
```markdown
## Phase 1 — Stack & architecture
✅ Complétée le [date]
- AGENTS.md généré
- Rules générées : [liste des fichiers créés]
- [N] items [À COMPLÉTER] à réviser par l'humain
```

## Output final

Afficher un résumé :
```
✅ AGENTS.md généré ([N] lignes, [N] items À COMPLÉTER)
✅ .Codex/rules/ :
   - api.md ([N] règles, [N] À VALIDER)
   - [autres fichiers créés]

✋ RÉVISION HUMAINE REQUISE avant de continuer :
1. Lire AGENTS.md et corriger/compléter les [À COMPLÉTER]
2. Valider les [À VALIDER] dans chaque rule
3. Ajouter les règles implicites que le code ne montrait pas

Quand la révision est faite → définir les personas :
/bootstrap-personas   ← nouvelle conversation (recommandé avant les synthèses de domaines)

Ou si vous connaissez déjà bien vos utilisateurs, lancer directement :
/bootstrap-explore-domain [premier-domaine]
```
