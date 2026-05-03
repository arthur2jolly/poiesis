---
name: "source-command-define-personas"
description: "Pipeline feature — crée docs/personas.md pour un projet existant qui n'en a pas encore. À exécuter une seule fois, avant le prochain /feature-product. Nouvelle conversation."
---

# source-command-define-personas

Use this skill when the user asks to run the migrated source command `define-personas`.

## Command Template

Utilise l'agent persona-builder pour définir les personas du projet.

## Contexte spécifique

Ce projet existe déjà — il y a du code et des cahiers des charges. L'agent doit inférer les personas depuis ce qui a déjà été construit, puis enrichir avec le dialogue humain.

## Prérequis — vérifier avant de commencer

Vérifier qu'au moins l'un de ces éléments existe :
- `docs/cahier-des-charges/*.md`
- `docs/improvements/*/cahier-des-charges.md`

Si aucun des deux n'existe → STOP :
```
❌ Aucune documentation existante trouvée.
Si c'est un nouveau projet, utiliser /greenfield-vision à la place.
Si c'est un projet existant sans documentation, utiliser /bootstrap-start à la place.
```

Vérifier que `docs/personas.md` n'existe pas déjà — si oui, demander confirmation avant d'écraser.

## Comportement attendu

L'agent doit :

1. Lire `docs/cahier-des-charges/*.md` et `docs/improvements/*/cahier-des-charges.md` — chercher les personas déjà mentionnés dans les sections "Personas concernés"
2. Présenter à l'humain ce qui a été inféré : "Voici les personas que je vois dans vos cahiers des charges existants — est-ce complet ?"
3. Poser les questions pour enrichir les profils (contexte d'usage, frustrations, comportements)
4. Identifier les tensions entre personas si elles existent
5. Générer `docs/personas.md`

## Message de fin

```
✅ docs/personas.md généré
[N] personas définis

✋ VALIDATION REQUISE :
1. Chaque persona correspond-il à de vraies personnes que tu connais ?
2. Les "Implications produit" sont-elles correctes ?
3. Mets à jour "Validé par l'humain : ✅"

Dès que validé, les prochains /feature-product utiliseront automatiquement
ces personas dans les cahiers des charges.
```
