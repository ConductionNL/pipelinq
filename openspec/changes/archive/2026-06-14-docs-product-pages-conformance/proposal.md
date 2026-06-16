# Proposal: Docs Product Pages Conformance

## Problem

The Pipelinq documentation site does not conform to the canonical `@conduction/docusaurus-preset` product-pages structure (audited 2026-05-13). Folder taxonomy is wrong-cased (`docs/features/` instead of `docs/Features/`), root-level markdown files belong in dedicated subdirectories, `installation.md` is missing, Redocusaurus is not mounted, the Dutch locale is disabled with stale metadata that causes SSR failures (per ADR-030), and two em-dash violations exist in a feature document. The shillinq migration (PR #83) established the pattern; pipelinq is next in the queue.

## Proposed Change

- **Rename** `docs/features/` → `docs/Features/` (40 files, preserves history via `git mv`)
- **Rename** `docs/tutorials/` → `docs/user-guide/` (21 items including `_category_.json` files, preserves history)
- **Rename** `docs/static/screenshots/tutorials/` → `docs/static/screenshots/user-guide/` and update all image references in tutorial markdown files
- **Move** `docs/ARCHITECTURE.md` → `docs/Technical/architecture.md`
- **Move** `docs/development.md` → `docs/Technical/development.md`
- **Move** `docs/DESIGN-REFERENCES.md` → `docs/Technical/design-references.md`
- **Move** `docs/FEATURES.md` → `docs/Technical/market-analysis.md` (strategic market analysis, not product docs)
- **Move** `docs/GOVERNMENT-FEATURES.md` → `docs/Features/government-compliance.md` (fix 3 em-dashes during move)
- **Create** `docs/UseCases/index.md` — `draft: true` stub citing issue #353
- **Create** `docs/Integrations/index.md` — `draft: true` stub citing issue #353
- **Create** `docs/installation.md` — real install steps (prerequisites, App Store, initial config, troubleshooting)
- **Delete** stale `docs/i18n/nl/` metadata files (code.json, plugin-content-docs/, theme-classic/) that block Dutch SSR per ADR-030
- **Re-enable** `nl` locale in `docs/docusaurus.config.js` (escape hatch: revert to `['en']` if SSR fails, cite #354)
- **Add** `redocusaurus@^2.0.0` to `docs/package.json`
- **Create** `docs/static/oas/pipelinq.json` — OpenAPI placeholder shim (`{"openapi":"3.0.0","info":{"title":"Pipelinq","version":"0.0.0"},"paths":{}}`)
- **Mount** Redocusaurus plugin at `/api` in `docs/docusaurus.config.js`, fed by the placeholder shim
- **Add** `API Documentation` navbar link pointing to `/api`
- **Fix** em-dash gate: `git grep -E '—' docs/` must return 0 after all moves

### New Capabilities

- `docs-product-pages-conformance`: Canonical product-pages folder taxonomy, installation guide, API documentation route via Redocusaurus, Dutch locale, em-dash-free content

### Out of Scope

- Authoring content for `UseCases/` or `Integrations/` (#353)
- Dutch translation pass (#354)
- Authoring real OpenAPI spec (#355)
- Fixing `docs/src/pages/index.js` orange-color count (#356)
- Any PHP, Vue, or OpenRegister schema changes

## Impact

- **docs/**: ~60 files touched (40 renames in features/, 21 renames in tutorials/, 5 root MD moves, 3 new stubs, config edits)
- **docs/package.json**: `redocusaurus@^2.0.0` added
- **docs/docusaurus.config.js**: Redocusaurus plugin mount, API navbar item, nl locale re-enabled
- **docs/static/**: new `oas/` directory with placeholder JSON
- **No PHP, no Vue, no OpenRegister schema changes** — documentation-only
- **CI**: docs-deploy workflow will rebuild and re-deploy pipelinq.conduction.nl from `development`
