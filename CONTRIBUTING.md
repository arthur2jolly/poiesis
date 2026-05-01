# Contributing to Poiesis

Thank you for your interest in contributing! Please read these guidelines before submitting issues or pull requests.

## Code of Conduct

Be respectful and constructive. We follow the [Contributor Covenant](https://www.contributor-covenant.org/).

## Getting Started

### Prerequisites

- PHP 8.4+
- Composer
- Docker + DDEV

### Local Setup

```bash
git clone <repository-url>
cd Poiesis
ddev start
ddev composer install
cp .env.example .env
ddev artisan key:generate
ddev artisan migrate
```

## Development Workflow

### Branching

- `main` — stable, production-ready
- `feature/<ticket-id>-short-description` — new features
- `fix/<ticket-id>-short-description` — bug fixes

### Commit Style

Use conventional commits:

```
feat(MCP): add bulk task creation tool
fix(Auth): resolve token expiry edge case
test(Projects): add authorization test suite
```

### Before Submitting

Run all quality checks:

```bash
ddev exec php artisan pint          # PSR-12 formatting
ddev exec php artisan stan          # PHPStan level 8
ddev exec php artisan test          # Pest test suite
```

All checks must pass before a PR is reviewed.

## Pull Request Guidelines

1. One feature or fix per PR
2. Include or update tests for every change
3. Update documentation if behavior changes
4. Reference the related issue/ticket in the PR description

## Code Standards

- **PSR-12** for all PHP code
- **SOLID** principles — keep classes focused and dependencies injected
- **PHPStan level 8** — strict types enforced
- No direct `DB::` calls outside repositories/services
- No logic in controllers beyond request validation and delegation

## Adding a New MCP Tool

See the [README — Adding a New Tool](README.md#adding-a-new-tool) section for the full implementation guide.

Quick checklist:

- [ ] Implement `McpToolInterface`
- [ ] Register in `CoreServiceProvider`
- [ ] Add feature tests covering success and authorization cases
- [ ] Document the tool name and parameters

## Adding a New Module

See the [README — Creating a New Module](README.md#creating-a-new-module) section.

Quick checklist:

- [ ] Create module directory under `app/Modules/<Name>/`
- [ ] Implement `McpToolInterface` for each tool provider
- [ ] Create a `ServiceProvider` for registration
- [ ] Add migration(s) if the module requires new tables
- [ ] Register module metadata in `ModuleRegistry`
- [ ] Write integration tests

## Testing

Tests live in `tests/` and use [Pest](https://pestphp.com/).

```bash
ddev exec php artisan test                      # Full suite
ddev exec php artisan test --filter ToolName    # Single test
ddev exec php artisan test --coverage           # With coverage
```

Every new feature or bug fix must ship with a corresponding test.

## Reporting Issues

Please include:

- Steps to reproduce
- Expected vs actual behavior
- PHP version, OS
- Relevant logs from `storage/logs/`

## License

By contributing, you agree your contributions are licensed under the [Apache License 2.0](LICENSE).
