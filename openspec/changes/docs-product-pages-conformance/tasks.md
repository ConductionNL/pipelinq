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
