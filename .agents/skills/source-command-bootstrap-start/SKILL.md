---
name: "source-command-bootstrap-start"
description: "Démarre le processus de bootstrap d'un codebase existant. Crée la structure docs/bootstrap/, lance /init pour un premier AGENTS.md, et affiche le plan de bootstrap complet avec les commandes à exécuter dans l'ordre."
---

# source-command-bootstrap-start

Use this skill when the user asks to run the migrated source command `bootstrap-start`.

## Command Template

## Objectif

Initialiser le processus de bootstrap sur ce codebase existant. Ce processus produira, dans l'ordre :
1. `AGENTS.md` + `.Codex/rules/` (mémoire technique permanente)
2. `docs/cahier-des-charges/` (mémoire fonctionnelle par domaine)
3. `docs/specs-techniques/` (mémoire technique par domaine)

Ces fichiers sont identiques en format à ceux produits par le pipeline feature — les deux processus sont interchangeables.

## Actions à exécuter

### Étape 1 — Créer la structure de dossiers bootstrap

```bash
mkdir -p docs/bootstrap
mkdir -p docs/cahier-des-charges
mkdir -p docs/specs-techniques
mkdir -p docs/improvements
```

### Étape 2 — Explorer la structure de haut niveau

Utilise Glob et Read pour :
1. Lister les dossiers de premier niveau
2. Lire package.json (ou composer.json, requirements.txt, go.mod selon le projet)
3. Lire le README s'il existe
4. Identifier les domaines fonctionnels apparents (noms de modules, controllers, routes)

### Étape 3 — Créer docs/bootstrap/00-plan.md

Génère ce fichier avec :

```markdown
# Plan de bootstrap — [nom du projet détecté]
> Créé le [date]

## Codebase détecté
- Langage / Runtime : [détecté]
- Framework principal : [détecté]
- Domaines fonctionnels identifiés : [liste]

## Phase 1 — Stack & architecture (AGENTS.md + rules)
Exécuter dans l'ordre, chaque commande dans une nouvelle conversation :

/bootstrap-explore-stack
/bootstrap-explore-architecture
/bootstrap-explore-constraints
/bootstrap-explore-tests
/bootstrap-synthesize-claudemd

✋ RÉVISION HUMAINE : valider AGENTS.md et .Codex/rules/ avant de continuer

## Phase 2 — Domaines fonctionnels (cahiers des charges + specs)
Pour chaque domaine listé ci-dessous, exécuter dans l'ordre :

[Pour chaque domaine détecté :]
/bootstrap-explore-domain [domaine]
/bootstrap-synthesize-domain [domaine]
✋ RÉVISION HUMAINE : valider et compléter les [À VALIDER] avant le domaine suivant

Domaines à traiter :
[liste numérotée des domaines détectés, ex:]
- [ ] 01 - [domaine-1]
- [ ] 02 - [domaine-2]
- [ ] 03 - [domaine-3]

## Phase 3 — Validation
/bootstrap-validate

✋ RÉVISION HUMAINE FINALE : tester avec une vraie tâche avant d'utiliser en production

## Règle fondamentale
Chaque commande s'exécute dans une conversation séparée.
Ne jamais enchaîner deux explorations dans le même contexte.
Les [À VALIDER] et [À COMPLÉTER] sont des instructions pour toi — pas des erreurs.
```

### Étape 4 — Afficher le résumé

Affiche à l'humain :
- Le contenu de docs/bootstrap/00-plan.md
- La prochaine commande à taper : `/bootstrap-explore-stack`
- Le rappel : ouvrir une nouvelle conversation avant de continuer
