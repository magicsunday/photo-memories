<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2025-10-13 -->
## Overview
- Contains architecture notes, workflow guides, and operational documentation. Use these files to explain behaviour, configuration, and process updates.
- `docs/decision-log.md` tracks noteworthy engineering decisions tied to commits/PRs.

## Setup & env
- Markdown files use UTF-8, LF endings, and English unless the document targets German-speaking end users (follow existing language choice per file).
- Reference configuration keys exactly as defined in `config/parameters.yaml`.

## Build & tests
- Run spelling or linting tools if introduced in the future; currently no automated doc checks exist. Ensure any command snippets are executable before publishing.

## Code style
- Prefer level-2 headings for major sections, followed by lists or tables. Keep code fences annotated with language identifiers.
- Document new env vars, CLI options, and data flows in the same change that introduces them.
- Link to runbooks or external resources instead of duplicating large excerpts.

## Security
- Do not store secrets or internal-only credentials. Redact PII from examples.
- Flag any security-sensitive procedures with clear warnings and escalation paths.

## PR/commit checklist
- Update or create documentation whenever behaviour, interfaces, or operational processes change.
- Append a Decision Log entry summarising intent, trade-offs, and references to related issues/PRs.
- Ensure screenshots or diagrams comply with branding (see `public/app/AGENTS.md`).

## Good vs bad examples
- ✅ Good: Add a section to `configuration-files.md` describing a new `%env()%` parameter with default, override instructions, and related command usage.
- ❌ Bad: Merge feature changes without updating docs or referencing outdated command names.

## When stuck
- Review existing docs for structure/terminology. Check commit history for similar updates.
- Coordinate with maintainers before reorganising large doc sections.

## House Rules
- Decision Log entries follow the template: date, author, context, decision, alternatives, follow-up actions.
