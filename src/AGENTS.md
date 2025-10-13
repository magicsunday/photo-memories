<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2025-10-13 -->
## Overview
- Contains production PHP code under the `MagicSunday\\Memories` namespace, including console commands, services, Doctrine entities, repositories, and support utilities.
- Symfony's DI container autowires everything except Doctrine entities; `src/Dependencies.php` and `config/services.yaml` define wiring. `src/Application.php` exposes commands to the console kernel in `src/Memories.php`.
- Keep services cohesive; prefer composition. Update the matching YAML definitions when adding new classes.

## Setup & env
- Ensure PHP ≥8.4 with required extensions is available. Run `composer install` before executing or testing code.
- Runtime parameters resolve from `%env()%` placeholders configured in `config/parameters.yaml`. Update defaults there and document new env vars in `docs/`.
- If DI updates do not take effect, delete `var/cache/DependencyContainer.php` to rebuild the container.

## Build & tests
- Run targeted QA for backend changes:
  - Lint: `composer ci:test:php:lint`
  - Coding standards: `composer ci:test:php:cgl`
  - Static analysis: `composer ci:test:php:phpstan`
  - Refactoring safety: `composer ci:test:php:rector` and `composer ci:test:php:fractor`
  - Unit tests: `composer ci:test:php:unit`
- For regression fixes, write a failing PHPUnit test in `test/Unit` or `test/Integration` before applying the fix.
- Capture executed commands in PR descriptions.

## Code style
- Declare `strict_types=1` and include the standard project header comment in every PHP file. Keep namespaces aligned with directory structure.
- Avoid `mixed` types; prefer concrete return/argument types and generics where applicable. Do not use `empty()`—check intent explicitly.
- Inject dependencies via constructors or service factories; never fetch from the container at runtime.
- Document complex logic with PHPDoc in English. User-facing console output remains in German; exceptions/messages stay in English.
- Use attributes (e.g., Doctrine mappings, Symfony configuration) instead of legacy annotations.
- Keep classes short, following SOLID/KISS/DRY; prefer modern language features (readonly properties, enums, first-class callables).

## Security
- Sanitize file system and HTTP inputs in the relevant services. Respect rate limits when calling external APIs (Nominatim/Overpass) and throttle retries.
- Never hard-code credentials; read from env vars or parameters. Redact PII from logs.

## PR/commit checklist
- Update `config/services.yaml`, Doctrine mappings, and DTOs when introducing new services/entities.
- Provide/adjust PHPUnit coverage for new code paths (aim ≥80% line coverage of touched classes).
- Update `docs/` (architecture notes, configuration guides) when behaviour or configuration changes.
- Log noteworthy design decisions in `docs/decision-log.md`.

## Good vs bad examples
- ✅ Good: Add `Service/Metadata/LivePhotoEnricher` with constructor-injected dependencies, register via tag in `config/services.yaml`, and cover with a unit test under `test/Unit/Service/Metadata/LivePhotoEnricherTest.php`.
- ❌ Bad: Instantiate new services inside commands via `new`, omit service registration, and leave behaviour undocumented and untested.

## When stuck
- Review existing services under `Service/` for established patterns. Check Doctrine repositories for query best practices.
- Inspect `.build/phpstan.neon` and `.build/.php-cs-fixer.dist.php` for rule specifics.
- Use `composer dump-autoload -o` after adding namespaces if autoloading fails.

## House Rules
- Backend changes must retain streaming-friendly logging (no dumping of large payloads). Emit structured context arrays when logging.
