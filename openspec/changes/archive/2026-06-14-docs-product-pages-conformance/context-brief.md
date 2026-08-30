## Why

The Pipelinq documentation site does not conform to the canonical `@conduction/docusaurus-preset` product-pages structure (audited 2026-05-13). Folder taxonomy is wrong-cased, root-level markdown files belong in dedicated subdirectories, `installation.md` is missing, Redocusaurus is not mounted, the Dutch locale is disabled with stale metadata, and two em-dash violations exist in a feature document. The shillinq migration (PR #83) established the pattern; pipelinq is next in the queue.

## What Changes

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
- **Delete** stale `docs/i18n/nl/` metadata files (code.json, plugin-content-docs/, theme-classic/) that broke Dutch SSR per ADR-030
- **Re-enable** `nl` locale in `docs/docusaurus.config.js` (escape hatch: revert to `['en']` if SSR fails, cite #354)
- **Add** `redocusaurus@^2.0.0` to `docs/package.json`
- **Create** `docs/static/oas/pipelinq.json` — OpenAPI placeholder shim (`{"openapi":"3.0.0","info":{"title":"Pipelinq","version":"0.0.0"},"paths":{}}`)
- **Mount** Redocusaurus plugin at `/api` in `docs/docusaurus.config.js`, fed by the placeholder shim
- **Add** `API Documentation` navbar link pointing to `/api`
- **Fix** em-dash gate: `git grep -E '—' docs/` must return 0 after all moves

## Capabilities

### New Capabilities

- `docs-product-pages-conformance`: Canonical product-pages folder taxonomy, installation guide, API documentation route via Redocusaurus, Dutch locale, em-dash-free content

### Modified Capabilities

_(none — this change is purely documentation/configuration, it does not alter any existing feature spec requirements)_

## Impact

- **docs/**: ~60 files touched (40 renames in features/, 21 renames in tutorials/, 5 root MD moves, 3 new stubs, config edits)
- **docs/package.json**: `redocusaurus@^2.0.0` added
- **docs/docusaurus.config.js**: Redocusaurus plugin mount, API navbar item, nl locale re-enabled
- **docs/static/**: new `oas/` directory with placeholder JSON
- **No PHP, no Vue, no OpenRegister schema changes** — documentation-only
- **CI**: docs-deploy workflow will rebuild and re-deploy pipelinq.conduction.nl from `development`



## Design

## Context

Pipelinq's docs site (`docs/`) is built on `@conduction/docusaurus-preset ^2.6.1` and deployed to `pipelinq.conduction.nl`. The preset defines a canonical folder taxonomy that all Conduction product-pages must follow. An audit on 2026-05-13 found four conformance gaps: wrong-cased/wrong-named folders, root-level markdown files that belong in subdirectories, missing `installation.md`, missing Redocusaurus mount, stale Dutch locale metadata, and two em-dash violations. The shillinq migration (PR #83) is the directly preceding pilot; pipelinq's changes mirror that pattern at larger scale (rename-heavy due to 40 feature files).

## Goals / Non-Goals

**Goals:**
- Adopt canonical folder taxonomy: `Features/`, `user-guide/`, `UseCases/`, `Integrations/`, `Technical/`
- Move all root-level standalone MDs into their canonical homes
- Create `installation.md` with real install steps
- Mount Redocusaurus at `/api` with a placeholder OAS shim (real spec tracked in #355)
- Re-enable `nl` locale in config (delete stale metadata first to clear ADR-030 SSR blocker)
- Pass em-dash gate: `git grep -E '—' docs/` returns 0
- All changes via `git mv` (preserves rename history) + Edit tool (no sed/awk/python)

**Non-Goals:**
- Authoring content for `UseCases/` or `Integrations/` (#353)
- Dutch translation pass (#354)
- Authoring real OpenAPI spec (#355)
- Fixing `docs/src/pages/index.js` orange-color count (#356)
- Any PHP, Vue, or OpenRegister schema changes

## Decisions

### D1: `docs/FEATURES.md` → `Technical/market-analysis.md`, not `Features/index.md`
`docs/FEATURES.md` is a competitive landscape and feature demand matrix (strategic reference for sales/procurement). `docs/features/README.md` is already the product feature index. Putting FEATURES.md into `Features/index.md` would shadow the README and confuse the autogenerated sidebar. Moving it to `Technical/market-analysis.md` keeps it accessible but signals its audience correctly.

_Alternative considered_: Delete FEATURES.md entirely. Rejected — it contains real market intelligence useful to procurement readers and opsx's spec-to-feature mapping table.

### D2: Stale `i18n/nl/` metadata deleted before re-enabling locale
The ADR-030 escape-hatch comment in `docusaurus.config.js` cites stale translation strings as the SSR blocker. The right fix is to delete the stale JSON files (code.json, docusaurus-plugin-content-docs/ tree, docusaurus-theme-classic/ tree) and re-enable `locales: ['en', 'nl']`. If the build still fails after cleanup, we revert locales to `['en']` with a comment citing #354 — the escape hatch is preserved.

_Alternative considered_: Leave locale disabled and rely on the existing comment. Rejected — the audit explicitly requires nl re-enable as Tier-2, and clearing stale metadata is the least-invasive fix.

### D3: Screenshot directories renamed to match tutorial → user-guide rename
PR #352 (merged today) added `docs/static/screenshots/tutorials/` with admin/ and user/ subdirectories. Tutorial markdown files reference these paths relatively (e.g., `../../../static/screenshots/tutorials/admin/01-stages-editor.png`). The directory rename must be coordinated: `git mv docs/static/screenshots/tutorials docs/static/screenshots/user-guide`, then update all path references in the markdown files from `screenshots/tutorials/` to `screenshots/user-guide/`.

_Alternative considered_: Leave screenshot paths unchanged and only rename the markdown folder. Rejected — broken image paths would fail the build's `onBrokenMarkdownImages: 'warn'` gate and leave the live site with missing screenshots.

### D4: Redocusaurus 2.x with placeholder shim, not skipped
The canonical spec requires Redocusaurus mounted at `/api`. Without a real OAS file the plugin errors. Solution: create `docs/static/oas/pipelinq.json` as a minimal valid OpenAPI 3.0 document. The real spec is tracked in #355. `redocusaurus@^2.0.0` is the version compatible with Docusaurus 3.7.

_Alternative considered_: Skip Redocusaurus until the real spec exists. Rejected — the navbar link and route must exist for QA to verify; a placeholder is the standard approach (used in all fleet migrations).

### D5: `git mv` for all renames (not copy + delete)
`git mv` preserves rename history, which matters for blame and bisect on the 40 feature files and 21 tutorial files. All renames use `git mv` directly. Post-rename content edits (em-dash fixes, path updates) are separate commits.

## Risks / Trade-offs

- **nl SSR failure after stale-metadata cleanup** → Mitigation: run `npm run build` after adding `nl` to locales; if it fails, revert `locales` to `['en']` and add comment citing #354.
- **redocusaurus version incompatibility with Docusaurus 3.7** → Mitigation: if `npm install` fails, check npm for the correct peer-compatible version and pin it.
- **Broken image references** → Mitigation: after renaming screenshot directories, run `git grep 'screenshots/tutorials' docs/` to find any remaining stale references.
- **Sidebar autogeneration** → Moving files between folders changes the autogenerated sidebar order. No sidebar.js manual overrides exist, so autogeneration will simply rebuild from the new structure. Acceptable.
- **Internal links between docs** → `features/README.md` Spec-to-Feature Mapping section links to individual feature files with relative paths. After renaming to `Features/`, all relative links within the folder remain valid (same-folder references don't include the parent dir name). No cross-folder link updates needed.

## Migration Plan

1. Commit 1 (openspec artifacts): `chore(openspec): add docs-product-pages-conformance change artifacts`
2. Commit 2 (implementation): All docs changes in one atomic commit — `docs: align with canonical product-pages structure`
   - Renames first (git mv), then content edits (Edit tool), then new stubs (Write tool), then config changes
3. Push `feature/docs-product-pages-conformance` → PR against `development`
4. CI build must pass. If `nl` locale fails SSR, patch before merge.
5. Merge with `--admin --squash` (pre-authorized)
6. Docs-deploy workflow re-deploys to `pipelinq.conduction.nl`
7. Verify: `curl -sIL https://pipelinq.conduction.nl/docs/intro/`

**Rollback**: Revert the squash-merge commit on `development`. The docs site redeploys to the previous state automatically.

## Open Questions

_(none — all decisions resolved above or explicitly deferred to follow-up issues #353–#356)_



## Tasks

## 1. Folder Renames (git mv — preserves history)

- [ ] 1.1 `git mv docs/features docs/Features` — rename features folder (40 files including README.md)
- [ ] 1.2 `git mv docs/tutorials docs/user-guide` — rename tutorials folder (6 admin + 12 user files + _category_.json files)
- [ ] 1.3 `git mv docs/static/screenshots/tutorials docs/static/screenshots/user-guide` — rename screenshot directory to match

## 2. Update Screenshot Paths in Tutorial Markdown

- [ ] 2.1 Update all `screenshots/tutorials/admin/` references → `screenshots/user-guide/admin/` in `docs/user-guide/admin/*.md`
- [ ] 2.2 Update all `screenshots/tutorials/user/` references → `screenshots/user-guide/user/` in `docs/user-guide/user/*.md`
- [ ] 2.3 Verify: `git grep 'screenshots/tutorials' docs/` returns 0

## 3. Root MD Moves into Technical/

- [ ] 3.1 Create `docs/Technical/` directory (via first git mv)
- [ ] 3.2 `git mv docs/ARCHITECTURE.md docs/Technical/architecture.md`
- [ ] 3.3 `git mv docs/development.md docs/Technical/development.md`
- [ ] 3.4 `git mv docs/DESIGN-REFERENCES.md docs/Technical/design-references.md`
- [ ] 3.5 `git mv docs/FEATURES.md docs/Technical/market-analysis.md`

## 4. GOVERNMENT-FEATURES.md Move + Em-dash Fix

- [ ] 4.1 `git mv docs/GOVERNMENT-FEATURES.md docs/Features/government-compliance.md`
- [ ] 4.2 Fix em-dash on line 1: `# Pipelinq — Overheidsfunctionaliteiten` → `# Pipelinq: Overheidsfunctionaliteiten`
- [ ] 4.3 Fix em-dash on line 173: `Nextcloud Contacts sync — geen dubbele invoer` → `Nextcloud Contacts sync, geen dubbele invoer`
- [ ] 4.4 Fix em-dash on line 194: `Geen apart CRM-systeem — draait als Nextcloud-app` → `Geen apart CRM-systeem, draait als Nextcloud-app`
- [ ] 4.5 Verify: `git grep -E '—' docs/Features/government-compliance.md` returns 0

## 5. Em-dash Gate (full sweep)

- [ ] 5.1 Run `git grep -E '—' docs/` — collect any remaining hits not yet fixed
- [ ] 5.2 Fix any remaining em-dashes found (replace with colon, comma, or rephrase per context)
- [ ] 5.3 Final verify: `git grep -E '—' docs/` returns 0 output

## 6. New Stub Files

- [ ] 6.1 Create `docs/UseCases/index.md` — `draft: true` frontmatter, title "Use Cases", body cites issue #353
- [ ] 6.2 Create `docs/Integrations/index.md` — `draft: true` frontmatter, title "Integrations", body cites issue #353
- [ ] 6.3 Create `docs/installation.md` — real install steps: prerequisites (Nextcloud 29+, OpenRegister), App Store install, initial config (pipeline stages, register connection), troubleshooting

## 7. i18n Cleanup

- [ ] 7.1 Delete `docs/i18n/nl/code.json`
- [ ] 7.2 Delete `docs/i18n/nl/docusaurus-plugin-content-docs/` directory (all files)
- [ ] 7.3 Delete `docs/i18n/nl/docusaurus-theme-classic/` directory (all files)
- [ ] 7.4 Verify `docs/i18n/nl/` is now empty (or contains only empty subdirs that git ignores)

## 8. Re-enable Dutch Locale in Config

- [ ] 8.1 Edit `docs/docusaurus.config.js`: change `locales: ['en']` → `locales: ['en', 'nl']`
- [ ] 8.2 Add `nl: { label: 'Nederlands' }` to `localeConfigs` block
- [ ] 8.3 Update the comment block to reflect that metadata was cleaned and locale is re-enabled; cite #354 for translation pass

## 9. Redocusaurus Setup

- [ ] 9.1 Add `"redocusaurus": "^2.0.0"` to `dependencies` in `docs/package.json`
- [ ] 9.2 Create `docs/static/oas/` directory
- [ ] 9.3 Create `docs/static/oas/pipelinq.json` with placeholder: `{"openapi":"3.0.0","info":{"title":"Pipelinq","version":"0.0.0"},"paths":{}}`
- [ ] 9.4 Add Redocusaurus plugin to `docs/docusaurus.config.js` `plugins:` array, route `/api`, spec `static/oas/pipelinq.json`
- [ ] 9.5 Add `API Documentation` navbar item (href `/api`, position `left`) to the `navbar.items` array in `docs/docusaurus.config.js`

## 10. Build Verification

- [ ] 10.1 Run `npm install --legacy-peer-deps` in `docs/`
- [ ] 10.2 Run `npm run build` in `docs/` — must exit 0
- [ ] 10.3 If build fails on `nl` SSR: revert `locales` to `['en']`, add comment citing #354, re-run build
- [ ] 10.4 Verify `docs/build/` contains `/api/` route files
- [ ] 10.5 Verify `docs/build/` contains `/docs/Features/` pages
- [ ] 10.6 Verify `docs/build/` contains `/docs/user-guide/` pages

## 11. Commit Openspec Artifacts

- [ ] 11.1 Stage openspec change files: `git add openspec/changes/docs-product-pages-conformance/`
- [ ] 11.2 Commit: `chore(openspec): add docs-product-pages-conformance change artifacts`

## 12. Commit Implementation

- [ ] 12.1 Stage all docs changes: `git add docs/`
- [ ] 12.2 Restore openspec/schemas/conduction symlink if disturbed: `git checkout HEAD -- openspec/schemas/conduction`
- [ ] 12.3 Commit: `docs: align with canonical product-pages structure`