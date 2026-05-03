---
name: "source-command-bootstrap-personas"
description: "Processus bootstrap — définit les personas utilisateurs à partir du code existant et du contexte humain. Exécuter après /bootstrap-synthesize-claudemd et avant /bootstrap-synthesize-domain. Produit docs/personas.md. Nouvelle conversation."
---

# source-command-bootstrap-personas

Use this skill when the user asks to run the migrated source command `bootstrap-personas`.

## Command Template

Utilise l'agent persona-builder pour définir les personas du projet à partir du code existant.

## Prérequis — vérifier avant de commencer

Vérifier que ces fichiers existent :
- `AGENTS.md` (généré par bootstrap-synthesize-claudemd)
- Au moins 1 fichier dans `docs/bootstrap/` (rapports d'exploration)

## Contexte spécifique au bootstrap

Dans le bootstrap, les personas ne peuvent pas être inférés directement depuis le code — le code montre *ce qui est fait*, pas *pour qui ni pourquoi*. L'agent doit :

1. Lire les rapports disponibles dans `docs/bootstrap/` pour identifier les indices d'usage (endpoints, rôles, permissions observés)
2. Lire `docs/cahier-des-charges/` si des fichiers existent déjà
3. Présenter à l'humain les personas inférés avec leur niveau de certitude
4. Poser les questions pour remplir ce que le code ne peut pas révéler

L'agent doit être explicite sur ce qui vient du code vs ce que l'humain apporte.

## Comportement attendu

1. Lire les rapports bootstrap disponibles — chercher les indices de personas (rôles, niveaux d'accès, types d'opérations)
2. Présenter à l'humain : "Voici ce que le code suggère, voici ce qui manque"
3. Conduire le dialogue pour compléter les profils
4. Générer `docs/personas.md`

## Message de fin

```
✅ docs/personas.md généré
[N] personas définis | Source : [code + dialogue humain]

⚠️ Personas issus d'un codebase existant — valider avec l'équipe :
Les profils sont basés sur ce que le code montre.
Certains comportements peuvent avoir évolué depuis l'implémentation initiale.

✋ VALIDATION REQUISE avant de continuer les synthèses de domaines :
1. Valider chaque persona avec quelqu'un qui connaît les vrais utilisateurs
2. Corriger les [À VALIDER] dans le fichier
3. Mets à jour "Validé par l'humain : ✅"

Quand validé → reprendre les synthèses de domaines :
/bootstrap-synthesize-domain [domaine] [NN]
```
