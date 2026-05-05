---
name: "source-command-bootstrap-explore-stack"
description: "Phase 1a — Explore la stack technique, les commandes de build/test/lint, et les dépendances principales. Produit docs/bootstrap/01-stack.md. Exécuter dans une nouvelle conversation."
---

# source-command-bootstrap-explore-stack

Use this skill when the user asks to run the migrated source command `bootstrap-explore-stack`.

## Command Template

Utilise le subagent bootstrap-explorer pour analyser la stack technique de ce projet.

## Fichiers à examiner (dans cet ordre)

1. Fichiers de dépendances : `package.json`, `package-lock.json`, `composer.json`, `requirements.txt`, `go.mod`, `Gemfile`, `pyproject.toml` — selon ce qui existe
2. Fichiers de configuration : `tsconfig.json`, `webpack.config.*`, `vite.config.*`, `jest.config.*`, `.eslintrc.*`, `.prettierrc.*`
3. Scripts disponibles : section `scripts` du package.json ou Makefile
4. CI/CD : `.github/workflows/*.yml`, `Dockerfile`, `docker-compose.yml`
5. Variables d'environnement attendues : `.env.example` ou `.env.template` (jamais `.env` réel)

## Ce que tu cherches

- **Runtime et version** (Node.js 20, Python 3.12, Go 1.21...)
- **Framework principal** (NestJS, FastAPI, Laravel, Next.js...)
- **ORM / accès DB** (Prisma, TypeORM, SQLAlchemy, Eloquent...)
- **Framework de tests** (Jest, Vitest, Pytest, PHPUnit...)
- **Commandes exactes** pour : build, test unitaire, test intégration, lint, format, migration DB
- **Variables d'env requises** (sans leurs valeurs)

## Output attendu

Sauvegarder le rapport dans `docs/bootstrap/01-stack.md` en suivant le format standard du subagent.

Inclure une section spéciale :

```markdown
## Commandes vérifiées
| Action | Commande exacte | Vérifiée |
|--------|----------------|---------|
| Build | [commande] | [oui/non] |
| Tests unitaires | [commande] | [oui/non] |
| Tests intégration | [commande] | [oui/non] |
| Lint | [commande] | [oui/non] |
| Migration DB | [commande] | [oui/non] |
```

Afficher à la fin : "✅ docs/bootstrap/01-stack.md créé. Prochaine étape : `/bootstrap-explore-architecture` dans une nouvelle conversation."
