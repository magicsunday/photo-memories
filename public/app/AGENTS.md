<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2025-10-13 -->
## Overview
- Contains the Vite-powered single-page app served from `public/index.php`. Source files reside in `public/app/src/` (JS/CSS) alongside HTML entrypoints.
- UI must align with the netresearch branding palette and typography defined in the global house rules.

## Setup & env
- Install dependencies with `npm install` (Node 20+). Use `npm run dev` for live reload during development.
- Environment variables can be exposed via Vite (`import.meta.env`). Document any new variables in `docs/` and ensure server-side counterparts exist if required.

## Build & tests
- Build production assets with `npm run build`; preview using `npm run preview`.
- Run Playwright end-to-end tests via `npm run test:e2e` (headless) or `npm run test:e2e:ui` (interactive). Tests live under `tests/e2e`.
- For visual changes, provide before/after screenshots (use browser tooling) and verify WCAG 2.2 AA contrast (e.g., with axe DevTools).

## Code style
- Use ES modules, modern JavaScript, and functional composition. Format files with Prettier defaults (2-space indentation).
- Define and reference CSS custom properties:
  - `--nr-color-primary: #2f99a4`
  - `--nr-color-text: #585961`
  - `--nr-color-box: #cccdcc`
  - `--nr-color-accent: #ff4d00`
  - Font stack: `Raleway, Calibri, 'Open Sans', sans-serif`
- Avoid inline styles when possible; centralise tokens in a shared stylesheet.
- Reserve “Ink Free” font for short accents only—never body text.

## Security
- Sanitize user inputs before rendering. Avoid embedding secrets or access tokens in client code.
- When calling backend APIs, handle errors gracefully without exposing stack traces.

## PR/commit checklist
- Update Playwright tests or add new ones for interactive changes.
- Run `npm run build` to ensure the bundle compiles.
- Attach accessible screenshots for UI changes and describe accessibility considerations.
- Document new UI affordances in relevant docs or release notes.

## Good vs bad examples
- ✅ Good: Introduce a new CTA button using `--nr-color-accent`, ensure focus styles meet WCAG AA, update Playwright coverage, and provide screenshots.
- ❌ Bad: Hard-code hex colors directly in components, omit tests, and ignore accessibility contrast.

## When stuck
- Check `vite.config.js` for bundler configuration. Consult existing components in `public/app/src/` for patterns.
- Use `npm run dev -- --host` to expose the dev server inside containers.
- Inspect browser console/network logs for debugging.

## House Rules
- Keep SPA payload lean; prefer lazy-loading heavy modules and compressing images/videos before committing.
