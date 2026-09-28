<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2025-10-13 -->
## Overview
- Photo Memories is a PHP 8.4 console and lightweight web application for organising media libraries. Symfony Console commands are bootstrapped via `src/Memories.php`, with services wired through `config/services.yaml` and Doctrine ORM. Front-end assets live under `public/app` and are built with Vite.
- Instruction precedence: the nearest `AGENTS.md` governs a file. Start here, then follow scoped files (see index below) for folder-specific rules.
- Scoped guides:
  - `config/AGENTS.md` – Symfony and parameter YAML conventions.
  - `docs/AGENTS.md` – documentation, decision logs, and runbooks.
  - `public/app/AGENTS.md` – Vite SPA, branding tokens, and Playwright usage.
  - `src/AGENTS.md` – PHP source, DI container rules, and testing expectations.
  - `tests/AGENTS.md` – PHPUnit suites.
  - `tests/e2e/AGENTS.md` – Playwright end-to-end tests.
- Decision Log: record noteworthy choices in `docs/decision-log.md` alongside related PRs or commits.
- Sources merged: prior root `AGENTS.md`, `README.md`, `composer.json`, `Make/*.mk`, and `package.json`.

## Setup & env
- Use PHP ≥8.4 with required extensions (`dom`, `exif`, `fileinfo`, `pdo`, `pdo_mysql`). Run `composer install` to pull dependencies into `vendor/`.
- Node 20+ is recommended for Vite/Playwright tooling; install npm packages before running web targets.
- Load project environment via direnv: `direnv allow` (see `.envrc`). Runtime configuration lives in `.env[.local]` and `config/parameters.yaml`.
- Clear Symfony caches (e.g., delete `var/cache/DependencyContainer.php`) if service wiring changes.

## Build & tests
- CLI help: `make help`.
- The QA tooling comes from the shared `magicsunday/coding-standard` package (`require-dev`), which delivers php-cs-fixer, PHPStan with its rule packs, Rector, phplint, Deptrac and PHPUnit. The configs at the repository root import its shared parts from `vendor/magicsunday/coding-standard/…` (`.php-cs-fixer.dist.php`, `phpstan.neon`, `rector.php`, `deptrac.yaml`); `phpunit.xml`, `.phplint.yml`, `.jscpd.json`, `.editorconfig` and `.gitattributes` are adapted copies of its templates, kept in step by `composer ci:test:php:templates`. Adoption is tracked in #783: PHPStan runs on `level: 8` until the level-max typing work lands, and the Deptrac layer map is still open, so several gates are red until the follow-up work.
- Full PHP quality gate: `composer ci:test` (runs every `ci:test:php:*` gate below).
- Targeted PHP checks (see scoped guides for details); CI runs each as its own step:
  - `composer ci:test:php:lint` – phplint
  - `composer ci:test:php:cgl` – php-cs-fixer dry-run
  - `composer ci:test:php:rector` – Rector dry-run
  - `composer ci:test:php:fractor` – Fractor dry-run
  - `composer ci:test:php:phpstan` – PHPStan
  - `composer ci:test:php:deptrac` – Deptrac layers, unassigned classes and the layer-cycle gate
  - `composer ci:test:php:templates` – template lockstep check against the shared canon
  - `composer ci:test:php:unit` – PHPUnit (`phpunit.xml`, runs `tests/` except `tests/e2e`)
  - `composer ci:test:php:cpd` – jscpd copy-paste detection (PHP, JavaScript, TypeScript)
- Front-end tooling: `npm run dev`, `npm run build`, `npm run preview`, and `npm run test:e2e`. `make web-*` mirrors these commands.
- Document the exact commands executed in PRs.

## Code style
- Default PHP style is PSR-12 plus project rules enforced by `.php-cs-fixer.dist.php` (shared `magicsunday/coding-standard` ruleset). Follow namespace `MagicSunday\Memories\…` and prefer constructor injection.
- JavaScript/TypeScript follows Vite defaults with Prettier-friendly formatting; align with CSS tokens defined in the front-end scope.
- Strings presented to end users default to German, while exceptions and errors stay in English.
- Update docs/runbooks when behaviour, interfaces, or configuration change.

## Security
- Keep secrets out of version control; rely on environment variables or secret managers.
- Validate external input early; log without leaking personal data. Respect Symfony HTTP client timeouts and rate limits.
- Run dependency and static analysis checks before merging.

## PR/commit checklist
- Commit subjects and the PR title follow the shared `commit-convention` gate (see **Git flow** below); Conventional-Commit prefixes such as `feat:` are rejected.
- Keep changesets small (≈≤300 net LOC) and atomic. Include tests/docs alongside code.
- Note decisions in `docs/decision-log.md`.
- Ensure `composer ci:test` and relevant npm/Playwright commands pass locally.

## Good vs bad examples
- ✅ Good: “Expose story ordering
  - add scoring service under `src/Service/Feed`
  - extend `config/services.yaml` tags
  - update docs/feed-controller-ui-ux-review.md with new weighting
  - recorded rationale in decision log”
- ❌ Bad: “misc updates” with untested PHP changes, no documentation updates, and a subject the `commit-convention` gate would reject.

## When stuck
- Check `README.md`, architecture docs in `docs/`, and service wiring in `config/services.yaml`.
- Inspect `Make/helper/help.mk` for available automation.
- For PHP diagnostics, enable verbose Symfony logs or rerun the command with `-vvv`.
- Reach out via existing project communication channels or open a draft PR describing the issue.

## House Rules
- Global defaults from the canonical prompt apply (strict types, SOLID, DRY/KISS, SemVer, Docker-first, ≥80% coverage on touched code, WCAG 2.2 AA, etc.).
- No additional global overrides beyond what’s listed above; rely on scoped guides for specifics.

## Git flow

- Commit subjects — and the pull-request title — are governed by the shared `commit-convention` gate; the normative rule and its full rationale live in `magicsunday/.github/.github/workflows/commit-convention.yml@main`, which self-tests a decision table before applying it. In short: a `GH-`-prefixed subject must match `^GH-\d+: [A-Z]`, every other subject `^[A-Z]` — a capitalised English imperative — and conventional-commit prefixes (`feat:`, `Fix:`, …) as well as path-like starts (`src/…: …`) are rejected whatever their case. It runs on every pull request via `.github/workflows/commit-lint.yml`, advisory until `commit-convention / Commit convention` is a required context in branch protection.
- Branches for an issue are named exactly `GH-<N>`; the `GH-<N>: ` prefix marks work that belongs to that issue, so a drive-by fix on the branch keeps its own unprefixed subject.
- The pull-request body closes the issue with `Closes #<N>` — the `GH-<N>: ` subject prefix is not a GitHub link and closes nothing.
- Never add a `Co-Authored-By:` trailer or any other AI attribution.
