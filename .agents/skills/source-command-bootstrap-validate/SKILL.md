---
name: "source-command-bootstrap-validate"
description: "Phase 3 — Validation finale du setup bootstrap. Vérifie la complétude des fichiers générés, identifie les À COMPLÉTER restants, et teste le pipeline feature sur une micro-tâche réelle pour confirmer que le setup fonctionne."
---

# source-command-bootstrap-validate

Use this skill when the user asks to run the migrated source command `bootstrap-validate`.

## Command Template

## Objectif

Valider que le bootstrap est opérationnel avant de passer en production avec le pipeline feature.

## Étape 1 — Audit de complétude

Scanner tous les fichiers générés par le bootstrap et compter les items non résolus :

```bash
grep -r "\[À COMPLÉTER\]\|\[À VALIDER\]\|\[QO-" docs/cahier-des-charges/ docs/specs-techniques/ AGENTS.md .Codex/rules/ 2>/dev/null
```

Produire un tableau récapitulatif :

```markdown
## État du bootstrap

| Fichier | À COMPLÉTER | À VALIDER | Questions ouvertes |
|---------|------------|-----------|-------------------|
| AGENTS.md | [N] | [N] | - |
| .Codex/rules/*.md | [N] | [N] | - |
| docs/cahier-des-charges/* | [N] | [N] | [N] |
| docs/specs-techniques/* | [N] | [N] | - |
| **TOTAL** | **[N]** | **[N]** | **[N]** |
```

## Étape 2 — Vérification de la structure

Vérifier que la structure complète est en place :

```
✅ ou ❌ AGENTS.md (racine)
✅ ou ❌ docs/personas.md (recommandé — sinon suggérer /bootstrap-personas)
✅ ou ❌ .Codex/agents/product.md
✅ ou ❌ .Codex/agents/spec.md
✅ ou ❌ .Codex/agents/coder.md
✅ ou ❌ .Codex/commands/feature-product.md
✅ ou ❌ .Codex/commands/feature-spec.md
✅ ou ❌ .Codex/commands/feature-plan.md
✅ ou ❌ .Codex/commands/feature-coder.md
✅ ou ❌ .Codex/commands/feature-retro.md
✅ ou ❌ .Codex/rules/ (au moins 1 fichier)
✅ ou ❌ docs/cahier-des-charges/ (au moins 1 fichier)
✅ ou ❌ docs/specs-techniques/ (au moins 1 fichier)
✅ ou ❌ docs/improvements/ (dossier vide OK)
```

Si des agents/commands du pipeline feature manquent → afficher les instructions pour les ajouter depuis le package bootstrap.

## Étape 3 — Test de calibrage

Proposer à l'humain le test de calibrage suivant :

```markdown
## 🧪 Test de calibrage recommandé

Pour valider que le setup fonctionne réellement avant de l'utiliser en production,
exécuter ce test dans une nouvelle conversation :

1. Choisir une micro-tâche simple que tu connais bien
   (ex: ajouter un champ à une entité existante, ou ajouter un endpoint simple)

2. Lancer le pipeline normalement :
   /feature-product [micro-feature] "[description simple]"

3. Valider que l'agent :
   ✓ Identifie correctement les bons fichiers à modifier
   ✓ Respecte les contraintes de AGENTS.md sans qu'on les rappelle
   ✓ Ne pose pas de questions sur des choses déjà documentées
   ✗ S'il pose trop de questions → AGENTS.md insuffisant, les rules manquent
   ✗ S'il dévie sur les patterns → les fichiers de référence sont incorrects

4. Ne pas implémenter la micro-tâche — juste valider le cahier des charges
   et la spec générés (étapes 1 et 2 seulement)
```

## Étape 4 — Mettre à jour docs/bootstrap/00-plan.md

```markdown
## Phase 3 — Validation
✅ Audit de complétude : [N] items À COMPLÉTER restants
[✅ ou ⏳] Test de calibrage : [fait / à faire]
[✅ ou ⏳] Setup opérationnel pour le pipeline feature

## Prochaines étapes recommandées
1. Résoudre les [N] items À COMPLÉTER prioritaires (voir liste ci-dessous)
2. Effectuer le test de calibrage si pas encore fait
3. Premier feature réel : /feature-product [feature] "[besoin]"
```

## Output final

Afficher :
```
═══════════════════════════════════════════
  BOOTSTRAP TERMINÉ
═══════════════════════════════════════════

📊 État : [N] items À COMPLÉTER | [N] À VALIDER | [N] Questions ouvertes

[Si N > 0 :]
⚠️  Le setup est fonctionnel mais incomplet.
   Priorité : résoudre les À COMPLÉTER dans AGENTS.md avant le premier feature.

[Si N = 0 :]
✅ Setup complet et prêt pour le pipeline feature.

📋 Prochaine action : test de calibrage (voir docs/bootstrap/00-plan.md)
🚀 Premier feature : /feature-product [feature] "[besoin]"
═══════════════════════════════════════════
```
