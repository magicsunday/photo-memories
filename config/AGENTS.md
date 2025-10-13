<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2025-10-13 -->
## Overview
- Holds Symfony configuration (`services.yaml`, `parameters.yaml`, etc.) that bootstraps the console application and Doctrine ORM.
- YAML definitions complement PHP classes under `src/`; keep them in sync with autowired services and tagged workflows.

## Setup & env
- Configuration values support environment overrides via `%env()%`. Reflect new settings in `.env.example` (if introduced) and document them in `docs/configuration-files.md`.
- Ensure YAML remains UTF-8 with LF endings (see `.editorconfig`).

## Build & tests
- Validate configuration by running `composer ci:test:php:lint` (syntax) and `composer ci:test:php:phpstan` (wiring) after edits.
- Clear `var/cache/DependencyContainer.php` if Symfony fails to pick up changes.

## Code style
- Use two-space indentation, lowercase keys, and explicit service IDs where needed.
- Prefer autowiring/autoconfiguration; only define aliases or bindings when necessary.
- Group tags logically (e.g., metadata extractors, geocoding workflows) and keep comments concise.

## Security
- Never commit secrets; load sensitive defaults via `%env()%`. Restrict external service endpoints to configurable parameters.
- When adding HTTP clients, set sensible timeouts and user agents.

## PR/commit checklist
- Update related PHP services/entities to match configuration changes.
- Add regression tests or adjust existing ones if new parameters change behaviour.
- Mention configuration changes in release notes and `docs/decision-log.md`.

## Good vs bad examples
- ✅ Good: Add a service definition with explicit arguments referencing `%memories.thumbnail_dir%` and tag it for the thumbnail pipeline.
- ❌ Bad: Hard-code a filesystem path inside YAML without env overrides or omit tags required for auto-registration.

## When stuck
- Compare with existing definitions in `services.yaml` for patterns (tags, factory usage).
- Consult Symfony DI docs for complex scenarios (factories, service subscribers).
- Use `php bin/console debug:container <service>` (via `bin/php`) to inspect the compiled container.

## House Rules
- Keep parameter names descriptive (`memories.*`). Document defaults in `docs/configuration-files.md` when they change.
