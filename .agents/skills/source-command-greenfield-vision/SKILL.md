---
name: "source-command-greenfield-vision"
description: "Phase 0 du processus greenfield — dialogue guidé pour capturer la vision produit. L'agent pose les questions manquantes et synthétise en docs/foundation/00-vision.md. Point de départ obligatoire de tout nouveau projet."
---

# source-command-greenfield-vision

Use this skill when the user asks to run the migrated source command `greenfield-vision`.

## Command Template

Utilise l'agent greenfield-interviewer pour conduire le dialogue de vision produit.

## Initialisation

Avant de commencer le dialogue, créer la structure de dossiers :

```bash
mkdir -p docs/foundation
mkdir -p docs/cahier-des-charges
mkdir -p docs/specs-techniques
mkdir -p docs/improvements
```

## Comportement attendu

L'agent doit :

1. Accueillir l'humain et lui demander de décrire son projet librement, sans structure imposée — laisser parler avant de poser des questions
2. Identifier ce qui est déjà couvert dans la description initiale
3. Poser les questions manquantes par blocs (voir l'agent), en commençant par les questions critiques pour l'architecture (multi-tenant ? volume ? contraintes réglementaires ?)
4. Signaler explicitement les tensions ou contradictions détectées
5. Quand les 5 blocs sont couverts, proposer de synthétiser
6. Générer `docs/foundation/00-vision.md`
7. Demander à l'humain de le valider et de mettre à jour la ligne `Validé par l'humain : ⏳ en attente` → `✅`

## Message de fin

Après avoir généré `docs/foundation/00-vision.md`, afficher :

```
✅ docs/foundation/00-vision.md généré

✋ VALIDATION REQUISE :
1. Lis le fichier et vérifie que ta vision est correctement capturée
2. Corrige directement dans le fichier ce qui serait inexact ou incomplet
3. Résous les [VD-N] (décisions non tranchées) si possible
4. Mets à jour la ligne "Validé par l'humain : ✅" quand c'est bon

Quand validé → /greenfield-architecture  (dans une nouvelle conversation)
```
