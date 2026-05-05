---
name: "source-command-greenfield-personas"
description: "Phase 2b du processus greenfield — approfondit les personas esquissés dans 00-vision.md pour produire docs/personas.md. Exécuter après /greenfield-domains et avant /greenfield-specs. Nouvelle conversation."
---

# source-command-greenfield-personas

Use this skill when the user asks to run the migrated source command `greenfield-personas`.

## Command Template

Utilise l'agent persona-builder pour définir les personas du projet en profondeur.

## Prérequis — vérifier avant de commencer

Vérifier que ces fichiers existent et sont validés (✅) :
- `docs/foundation/00-vision.md`
- `docs/foundation/02-domains.md`

Si non → STOP et afficher les fichiers manquants.

## Contexte à fournir à l'agent

L'agent doit lire `docs/foundation/00-vision.md` pour partir des personas déjà esquissés dans la section "Les personas". Il ne repart pas de zéro — il approfondit ce qui existe.

## Comportement attendu

1. Lire `docs/foundation/00-vision.md` — identifier les personas déjà nommés
2. Présenter à l'humain ce qui a été inféré et demander validation
3. Approfondir chaque persona avec les questions prioritaires
4. Identifier et nommer les tensions entre personas
5. Générer `docs/personas.md`

## Message de fin

```
✅ docs/personas.md généré
[N] personas primaires | [N] personas secondaires | [N] tensions identifiées

✋ VALIDATION REQUISE :
1. Lis chaque persona — est-ce que tu reconnais de vraies personnes ?
2. Les "Implications produit" sont-elles correctes ?
3. Les tensions sont-elles bien identifiées et les arbitrages tranchés ?
4. Mets à jour "Validé par l'humain : ✅"

Quand validé → premier cahier des charges :
/greenfield-specs [premier-domaine] [NN]   ← nouvelle conversation
```
