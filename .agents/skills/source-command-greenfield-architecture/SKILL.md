---
name: "source-command-greenfield-architecture"
description: "Phase 1 du processus greenfield — analyse la vision pour recommander une stack adaptée, documente les décisions dans docs/foundation/01-architecture.md, et génère le AGENTS.md initial. Exécuter dans une nouvelle conversation après validation de 00-vision.md."
---

# source-command-greenfield-architecture

Use this skill when the user asks to run the migrated source command `greenfield-architecture`.

## Command Template

Utilise l'agent greenfield-architect pour analyser la vision et recommander l'architecture.

## Prérequis — vérifier avant de commencer

Vérifier que `docs/foundation/00-vision.md` existe et contient `Validé par l'humain : ✅`.

Si non → STOP et afficher :
```
❌ docs/foundation/00-vision.md non trouvé ou non validé.
Exécuter d'abord /greenfield-vision et valider le fichier produit.
```

Vérifier aussi qu'il ne reste pas de `[VD-N]` non résolus dans le fichier.
Si oui → lister les VD non résolus et demander à l'humain de les résoudre avant de continuer.

## Comportement attendu

L'agent doit :

1. Lire `docs/foundation/00-vision.md` intégralement
2. Identifier les caractéristiques déterminantes (multi-tenant, volume, temps réel, contraintes réglementaires, compétences équipe)
3. **Présenter à l'humain** la recommandation avec justifications et trade-offs — ne pas documenter directement
4. Attendre la validation ou les ajustements de l'humain
5. Une fois validée, documenter dans `docs/foundation/01-architecture.md`
6. Générer `AGENTS.md` à la racine avec les vraies valeurs (pas de placeholders)

## Message de fin

```
✅ docs/foundation/01-architecture.md généré
✅ AGENTS.md généré (initial — fichiers de référence à compléter après scaffolding)

✋ VALIDATION REQUISE :
1. Lis 01-architecture.md et confirme que toutes les décisions sont correctes
2. Mets à jour "Validé par l'humain : ✅"
3. Ajuste AGENTS.md si nécessaire

Quand validé → /greenfield-domains  (dans une nouvelle conversation)
```
