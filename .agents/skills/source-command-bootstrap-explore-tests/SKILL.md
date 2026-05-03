---
name: "source-command-bootstrap-explore-tests"
description: "Phase 1d — Explore la stratégie de tests du projet (frameworks, structure, couverture, commandes). Produit docs/bootstrap/04-tests.md. Exécuter dans une nouvelle conversation."
---

# source-command-bootstrap-explore-tests

Use this skill when the user asks to run the migrated source command `bootstrap-explore-tests`.

## Command Template

Utilise le subagent bootstrap-explorer pour comprendre la stratégie de tests existante.

## Ce que tu cherches

### Structure des fichiers de tests
```bash
find . -name "*.spec.*" -o -name "*.test.*" | grep -v node_modules | head -30
find . -name "__tests__" -type d | grep -v node_modules
```
→ Où vivent les tests ? Colocalisés avec le code ou dans un dossier séparé ?

### Lire 2 fichiers de tests représentatifs
Un test unitaire (service) et un test d'intégration (endpoint) si disponibles.
→ Observer : structure du `describe`/`it`, mocks utilisés, setup/teardown, assertions

### Couverture et CI
```bash
cat jest.config.* 2>/dev/null || cat vitest.config.* 2>/dev/null
grep -r "coverage" package.json
```
→ Y a-t-il un seuil de coverage configuré ?

### Base de données de test
```bash
grep -r "test.*database\|sqlite\|testdb\|:memory:" src/ --include="*.ts" -i -l | head -10
find . -name "*.env.test" -o -name ".env.test" 2>/dev/null
```
→ Comment les tests gèrent-ils la DB ? DB séparée, mocks, SQLite in-memory ?

## Output attendu

Sauvegarder dans `docs/bootstrap/04-tests.md`.

Inclure une section spéciale :

```markdown
## Protocole de tests à appliquer dans le pipeline
- Commande tests unitaires : [commande exacte ou À COMPLÉTER]
- Commande tests intégration : [commande exacte ou À COMPLÉTER]
- Commande tests d'un fichier seul : [commande exacte ou À COMPLÉTER]
- Setup DB de test requis : [oui/non/À VALIDER]
- Convention de nommage des fichiers de test : [pattern observé]
- Convention cohabitation : [colocalisé / dossier séparé]
```

Afficher à la fin : "✅ docs/bootstrap/04-tests.md créé. Prochaine étape : `/bootstrap-synthesize-claudemd` dans une nouvelle conversation."
