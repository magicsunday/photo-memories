<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2025-10-13 -->
## Overview
- Photo Memories is a PHP 8.4 console and lightweight web application for organising media libraries. Symfony Console commands are bootstrapped via `src/Memories.php`, with services wired through `config/services.yaml` and Doctrine ORM. Front-end assets live under `public/app` and are built with Vite.
- Instruction precedence: the nearest `AGENTS.md` governs a file. Start here, then follow scoped files (see index below) for folder-specific rules.
- Scoped guides:
  - `config/AGENTS.md` – Symfony and parameter YAML conventions.
  - `docs/AGENTS.md` – documentation, decision logs, and runbooks.
  - `public/app/AGENTS.md` – Vite SPA, branding tokens, and Playwright usage.
  - `src/AGENTS.md` – PHP source, DI container rules, and testing expectations.
  - `test/AGENTS.md` – PHPUnit suites.
  - `tests/e2e/AGENTS.md` – Playwright end-to-end tests.
- Decision Log: record noteworthy choices in `docs/decision-log.md` alongside related PRs or commits.
- Sources merged: prior root `AGENTS.md`, `README.md`, `composer.json`, `Make/*.mk`, and `package.json`.

## Setup & env
- Use PHP ≥8.4 with required extensions (`dom`, `exif`, `fileinfo`, `pdo`, `pdo_mysql`). Run `composer install` to pull dependencies into `.build/vendor`.
- Node 20+ is recommended for Vite/Playwright tooling; install npm packages before running web targets.
- Load project environment via direnv: `direnv allow` (see `.envrc`). Runtime configuration lives in `.env[.local]` and `config/parameters.yaml`.
- Clear Symfony caches (e.g., delete `var/cache/DependencyContainer.php`) if service wiring changes.

## Build & tests
- CLI help: `make help`.
- Full PHP quality gate: `composer ci:test` (runs linting, static analysis, coding style, Rector/Fractor dry-runs, and PHPUnit).
- Targeted PHP checks (see scoped guides for details):
  - `composer ci:test:php:lint`
  - `composer ci:test:php:phpstan`
  - `composer ci:test:php:cgl`
  - `composer ci:test:php:rector`
  - `composer ci:test:php:fractor`
  - `composer ci:test:php:unit`
- Front-end tooling: `npm run dev`, `npm run build`, `npm run preview`, and `npm run test:e2e`. `make web-*` mirrors these commands.
- Document the exact commands executed in PRs.

## Code style
- Default PHP style is PSR-12 plus project rules enforced by `.build/.php-cs-fixer.dist.php`. Follow namespace `MagicSunday\Memories\…` and prefer constructor injection.
- JavaScript/TypeScript follows Vite defaults with Prettier-friendly formatting; align with CSS tokens defined in the front-end scope.
- Strings presented to end users default to German, while exceptions and errors stay in English.
- Update docs/runbooks when behaviour, interfaces, or configuration change.

## Security
- Keep secrets out of version control; rely on environment variables or secret managers.
- Validate external input early; log without leaking personal data. Respect Symfony HTTP client timeouts and rate limits.
- Run dependency and static analysis checks before merging.

## PR/commit checklist
- Use Conventional Commits with ticket IDs when available (e.g., `feat(core): ISSUE-123 add geocoding cache`).
- Keep changesets small (≈≤300 net LOC) and atomic. Include tests/docs alongside code.
- Note decisions in `docs/decision-log.md`.
- Ensure `composer ci:test` and relevant npm/Playwright commands pass locally.

## Good vs bad examples
- ✅ Good: “feat(feed): ISSUE-42 expose story ordering
  - add scoring service under `src/Service/Feed`
  - extend `config/services.yaml` tags
  - update docs/feed-controller-ui-ux-review.md with new weighting
  - recorded rationale in decision log”
- ❌ Bad: “misc updates” with untested PHP changes, no documentation updates, and missing Conventional Commit prefix.

## When stuck
- Check `README.md`, architecture docs in `docs/`, and service wiring in `config/services.yaml`.
- Inspect `Make/helper/help.mk` for available automation.
- For PHP diagnostics, enable verbose Symfony logs or rerun the command with `-vvv`.
- Reach out via existing project communication channels or open a draft PR describing the issue.

## House Rules
- Global defaults from the canonical prompt apply (strict types, SOLID, DRY/KISS, SemVer, Docker-first, ≥80% coverage on touched code, WCAG 2.2 AA, etc.).
- No additional global overrides beyond what’s listed above; rely on scoped guides for specifics.
