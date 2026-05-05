---
name: "source-command-bootstrap-explore-constraints"
description: "Phase 1c — Explore les contraintes transversales du projet (auth, multi-tenant, soft delete, validation, gestion d'erreurs, format de réponse). Produit docs/bootstrap/03-contraintes.md. Exécuter dans une nouvelle conversation."
---

# source-command-bootstrap-explore-constraints

Use this skill when the user asks to run the migrated source command `bootstrap-explore-constraints`.

## Command Template

Utilise le subagent bootstrap-explorer pour détecter les contraintes transversales — les règles qui s'appliquent à tout le codebase, pas juste à un module.

## Ce que tu cherches, et comment le trouver

### Authentification et autorisation
```bash
grep -r "middleware" src/ --include="*.ts" -l
grep -r "guard\|auth\|jwt\|token\|bearer" src/ --include="*.ts" -l -i
```
→ Comment les endpoints sont-ils protégés ? Y a-t-il un pattern systématique ?

### Multi-tenancy
```bash
grep -r "organizationId\|tenantId\|companyId\|org_id\|tenant_id" src/ --include="*.ts" -l
```
→ Est-ce que chaque query filtre par tenant ? Comment ? Via un middleware, un service, ou manuellement ?

### Soft delete
```bash
grep -r "deletedAt\|deleted_at\|isDeleted\|is_deleted\|archived" src/ --include="*.ts" -l
```
→ Est-ce un pattern systématique ou ad hoc ? Y a-t-il un filtre automatique ?

### Validation des inputs
```bash
grep -r "zod\|class-validator\|joi\|yup\|validate\|schema" src/ --include="*.ts" -l -i
```
→ Quel outil ? Où est appliqué la validation (middleware, service, DTO) ?

### Format de réponse standard
Lire 3-5 controllers et noter le format des réponses succès et erreur.
→ Y a-t-il un wrapper standard `{ data: ..., meta: ... }` ? Un format d'erreur `{ error: ..., code: ... }` ?

### Pagination
```bash
grep -r "cursor\|offset\|limit\|page\|paginate" src/ --include="*.ts" -l -i
```
→ Cursor-based ou offset ? Y a-t-il un helper partagé ?

### Logging
```bash
grep -r "logger\|console\|log\." src/ --include="*.ts" -l -i | head -20
```
→ Quel système de logging ? Y a-t-il des règles sur ce qui doit être loggé ?

## Output attendu

Sauvegarder dans `docs/bootstrap/03-contraintes.md`.

Pour chaque contrainte, indiquer le niveau de certitude :
- ✅ **Confirmé** : pattern systématique observé dans ≥3 fichiers
- ⚠️ **Partiel** : observé dans certains endroits, pas tous — `[À VALIDER]`
- ❓ **Absent ou inconnu** : aucune trace — `[À COMPLÉTER]`

Afficher à la fin : "✅ docs/bootstrap/03-contraintes.md créé. Prochaine étape : `/bootstrap-explore-tests` dans une nouvelle conversation."
