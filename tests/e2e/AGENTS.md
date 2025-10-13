<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2025-10-13 -->
## Overview
- Contains Playwright end-to-end tests for the SPA. Uses `@playwright/test` with configuration from `playwright.config.ts`.
- Tests should validate key user flows (feed browsing, media playback, accessibility checks).

## Setup & env
- Install Node dependencies via `npm install`. Ensure Playwright browsers are installed (`npx playwright install`).
- Tests expect the dev server or preview server to run at the URL defined in `playwright.config.ts` (`http://127.0.0.1:4173` by default). Adjust config if ports change.

## Build & tests
- Run headless tests: `npm run test:e2e`.
- Launch interactive UI runner: `npm run test:e2e:ui`.
- Target specific specs with `npx playwright test tests/e2e/<file>.spec.ts --project=chromium`.
- Capture and commit updated snapshots only when behaviour changes intentionally; review diffs carefully.

## Code style
- Use descriptive test titles (Given/When/Then style). Factor shared logic into `tests/e2e` helpers if duplication appears.
- Leverage Playwright test fixtures (`test.step`, `expect.poll`) for flakiness control.
- Include accessibility assertions using `expect(page).toHaveAttribute(...)` or integrate axe if available.

## Security
- Do not embed secrets in tests. Mask personal data in fixtures.
- Reset state between tests to avoid leaking user information across scenarios.

## PR/commit checklist
- Update tests when UI or routing changes. Ensure baseline snapshots and fixtures remain consistent.
- Record executed Playwright commands in PR descriptions.

## Good vs bad examples
- ✅ Good: Add a spec verifying that the “Erinnerungen teilen” button uses the accent colour, has focus styles, and passes axe checks.
- ❌ Bad: Disable assertions to “fix” flakiness without investigating timing or race conditions.

## When stuck
- Run tests with `DEBUG=pw:api` for verbose logging. Capture traces via `npx playwright test --trace on`.
- Consult Playwright docs for authentication helpers or storage state reuse.

## House Rules
- Keep specs deterministic; avoid reliance on external services. Mock network calls with Playwright routing where needed.
