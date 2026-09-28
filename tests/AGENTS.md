<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2025-10-13 -->
## Overview
- Houses PHPUnit tests under the `MagicSunday\\Memories\\Test` namespace (`Unit`, `Integration`, shared `Support`, and `TestCase.php`).
- Mirrors the structure of `src/`; place tests alongside the functionality they validate.

## Setup & env
- Install dev dependencies via `composer install`. PHPUnit config lives in `phpunit.xml` at the repository root (strict flags from the shared `magicsunday/coding-standard` template, including `requireCoverageMetadata`: every test class needs `#[CoversClass]` or `#[CoversNothing]`). It runs `tests/` except the Playwright suite under `tests/e2e`.
- Integration tests may require a configured database; use the same DSN parameters defined for the application and document any new fixtures.

## Build & tests
- Run the suite with `composer ci:test:php:unit`.
- For focused runs, execute `vendor/bin/phpunit --configuration phpunit.xml --filter <TestName>`.
- Generate coverage (≥80% on changed paths) using `composer ci:test:php:unit:coverage` (`XDEBUG_MODE=coverage`, HTML report in `.build/coverage/`) when needed.

## Code style
- Follow Arrange-Act-Assert structure. Use data providers where helpful.
- Keep test method names descriptive (e.g., `testItEnforcesClusterSizeLimit`).
- Prefer Symfony/Doctrine test utilities instead of custom bootstrapping when possible.

## Security
- Avoid hard-coded credentials. Mask or anonymise sample data that could resemble PII.

## PR/commit checklist
- Add regression tests before fixing bugs. Remove obsolete fixtures cautiously and update docs when test coverage highlights behaviour changes.
- Ensure new fixtures live under `tests/Support` and are re-used rather than duplicated.

## Good vs bad examples
- ✅ Good: Add a unit test for a metadata enricher that validates German console output and error handling.
- ❌ Bad: Write integration tests that depend on developer-specific paths or require manual DB setup without documentation.

## When stuck
- Check `TestCase.php` for helper methods, and examine similar tests within `Unit` or `Integration` for patterns.
- Run PHPUnit with `-v` for detailed output.

## House Rules
- Keep test data lightweight; large media files belong in external storage or mocks.
