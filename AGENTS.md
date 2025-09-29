## Project Snapshot
- **Purpose:** Photo Memories is a PHP 8.4 console application that indexes, enriches, and organises photo/video libraries. It relies on Symfony Console commands that are assembled by `src/Application.php` and wired through the dependency injection container defined in `src/Dependencies.php`.
- **Runtime entry point:** Development runs use `php src/Memories.php <command>` which boots the cached DI container under `var/cache/`. Release builds package the same console kernel into a standalone binary via `.build/build`.
- **Service wiring:** Symfony's container is configured in `config/services.yaml`. Autowiring/autoconfiguration is enabled for the `MagicSunday\Memories\` namespace, with console commands tagged automatically and the Doctrine entity manager registered explicitly.

## Repository Layout
| Path | Notes |
| --- | --- |
| `src/` | Production PHP code. Key areas include `Command/` for CLI commands, `Service/Metadata` for EXIF/XMP extraction, `Service/Geocoding` for Nominatim/Overpass clients, `Service/Thumbnail` for thumbnail generation, and `Entity/` + `Repository/` for Doctrine models. |
| `config/` | YAML service definitions (`services.yaml`), parameter defaults (`parameters.yaml`), and supporting configuration. |
| `var/cache/` | Symfony container cache generated at runtime; safe to delete when troubleshooting. |
| `.build/` | Build scripts, CI tool configuration, and static-analysis baselines (`phpstan.neon`, `rector.php`, `.php-cs-fixer.dist.php`, `phpunit.xml`). |
| `Make/` & `Makefile` | Helper targets such as `make init`, `make build`, and release tooling. |
| `scripts/` | Project automation scripts (e.g. `create-version`). |
| `test/` | PHPUnit tests under the `MagicSunday\Memories\Test\` namespace. |

## Environment & Configuration
- Environment variables are loaded by `EnvironmentBootstrap::boot()` in `src/EnvironmentBootstrap.php`. The loader searches for a `.env` file in the working directory, alongside a packaged PHAR, or at the repository root. `.env.local` variants are respected.
- Core parameters live in `config/parameters.yaml`. Notable variables you may need to set include geocoding credentials (`NOMINATIM_BASE_URL`, `NOMINATIM_EMAIL`), thumbnail output directories (`MEMORIES_THUMBNAIL_DIR`), media hashing defaults, and clustering thresholds.
- When introducing new services that require configuration, add defaults to `config/parameters.yaml` and expose environment overrides via `%env()%` placeholders.

## Code Style & Conventions
- Follow **PHP-4**, **PSR-4** and **PSR-12** plus the rules defined in `Build/.php-cs-fixer.dist.php`.
- Files use strict types, project header docblocks, and Symfony-style imports (global namespace imports are allowed).
- Keep functions/methods short and focused. Document non-trivial behavior with docblocks or inline comments.
- Every PHP file must begin with the standard package header comment (enforced via PHP-CS-Fixer).
- Keep namespaces aligned with their directory structure under `MagicSunday\Memories\…`.
- Prefer constructor injection; register new services in `config/services.yaml`. Commands should remain autoconfigured (tagged as `command`). Entities under `src/Entity/` are excluded from automatic service registration.
- Keep Doctrine mappings (`src/Entity/`) and repositories (`src/Repository/`) in sync when modifying persistence logic.
- Update accompanying documentation (README, examples, CLI help) in the same commit when public behaviour or configuration changes.

### Coding Guidelines
- Do not use "mixed" types, but rather "strict types"
- Always use comments and PHPdoc blocks in English
- Text, e.g., labels and user output in German, except for error messages and exceptions
- Do not use "empty"
- Use modern design patterns
- Use attributes
- Do not use outdated libraries
- Adhere to the SOLID principle
- Adhere to KISS (Keep It Simple, Stupid)
- Adhere to DRY (Don't Repeat Yourself)
- Adhere to Separation of Concerns

## Testing & QA
- Install dependencies with `composer install` (uses `.build/vendor`).
- Main QA commands (see `composer.json`):
    - Code style: `composer ci:test:php:cgl`
    - Linting: `composer ci:test:php:lint`
    - Static analysis: `composer ci:test:php:phpstan`
    - Rector/Fractor dry runs: `composer ci:test:php:rector`, `composer ci:test:php:fractor`
    - Unit tests: `composer ci:test:php:unit`
    - Full suite: `composer ci:test`
- Run what’s relevant for your change and report the commands you executed.
- Always run `composer ci:test` before committing.

## Git Workflow Essentials
- Branch from `main` with a descriptive name: `feature/<slug>` or `bugfix/<slug>`.
- Run `composer ci:test` locally **before** committing.
- Force pushes **allowed only** on your feature branch using
  `git push --force-with-lease`. Never force-push `main`.
- Keep commits atomic; prefer checkpoints (`FEATURE: …`, `TEST: …`, , `BUGFIX: …`).

## Agent Checklist
- Look for additional `AGENTS.md` files in subdirectories before editing files (none exist today, but new ones may be added).
- Delete `var/cache/DependencyContainer.php` if DI changes are not reflected during testing.
- Keep new features configurable via DI parameters and `%env()%` placeholders to avoid hard-coded credentials.
- Place new tests under `test/Unit/...` (or matching suites) and mirror the existing namespace conventions.
- Ensure binary/release changes go through `.build/build` and update versioning scripts where necessary.
- Always run `composer ci:test` before committing.
