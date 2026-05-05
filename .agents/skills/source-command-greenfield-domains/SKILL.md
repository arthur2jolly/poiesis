---
name: "source-command-greenfield-domains"
description: "Phase 2 du processus greenfield — analyse la vision et l'architecture pour proposer la carte complète des domaines fonctionnels, leurs entités, et leurs dépendances. Produit docs/foundation/02-domains.md. Exécuter dans une nouvelle conversation après validation de 01-architecture.md."
---

# source-command-greenfield-domains

Use this skill when the user asks to run the migrated source command `greenfield-domains`.

## Command Template

Utilise l'agent greenfield-domain-mapper pour établir la carte des domaines.

## Prérequis — vérifier avant de commencer

Vérifier que ces deux fichiers existent et contiennent `Validé par l'humain : ✅` :
- `docs/foundation/00-vision.md`
- `docs/foundation/01-architecture.md`

Si non → STOP et afficher les fichiers manquants ou non validés.

## Comportement attendu

L'agent doit :

1. Lire `docs/foundation/00-vision.md` et `docs/foundation/01-architecture.md`
2. Identifier tous les candidats de domaines depuis la vision
3. Proposer la carte des domaines à l'humain **avant de documenter** — avec le diagramme de dépendances et l'ordre de scaffolding
4. Permettre à l'humain d'ajuster les frontières (fusionner, diviser, renommer)
5. Une fois validée, sauvegarder dans `docs/foundation/02-domains.md`

## Message de fin

```
✅ docs/foundation/02-domains.md généré
[N] domaines définis | Ordre de scaffolding établi

✋ VALIDATION REQUISE :
1. Vérifie que les frontières de domaines correspondent à ta vision
2. Vérifie qu'il n'y a pas de dépendances circulaires
3. Confirme l'ordre de scaffolding
4. Mets à jour "Validé par l'humain : ✅"

Quand validé → définir les personas :
/greenfield-personas   ← nouvelle conversation (obligatoire avant les cahiers des charges)
```
