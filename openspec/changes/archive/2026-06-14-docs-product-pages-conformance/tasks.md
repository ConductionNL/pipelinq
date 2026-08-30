# Tasks: Docs Product Pages Conformance

## 1. Folder Renames (git mv — preserves history)

- [x] 1.1 Rename features folder to canonical casing
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Canonical folder taxonomy`
  - **files**: `docs/features/` → `docs/Features/` (40 files including README.md)
  - **acceptance_criteria**:
    - GIVEN the migration is applied
    - THEN `docs/Features/` SHALL exist with all 40 markdown files
    - AND `docs/features/` (lowercase) SHALL NOT exist

- [x] 1.2 Rename tutorials folder to user-guide
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Canonical folder taxonomy`
  - **files**: `docs/tutorials/` → `docs/user-guide/` (6 admin + 12 user files + _category_.json files)
  - **acceptance_criteria**:
    - GIVEN the migration is applied
    - THEN `docs/user-guide/` SHALL exist with admin/ and user/ subdirectories
    - AND `docs/tutorials/` SHALL NOT exist

- [x] 1.3 Rename screenshot directory to match user-guide rename
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Screenshot paths consistent after rename`
  - **files**: `docs/static/screenshots/tutorials/` → `docs/static/screenshots/user-guide/`
  - **acceptance_criteria**:
    - GIVEN the screenshot directory has been renamed
    - THEN `docs/static/screenshots/user-guide/` SHALL contain admin/ and user/ subdirectories
    - AND `docs/static/screenshots/tutorials/` SHALL NOT exist

## 2. Update Screenshot Paths in Tutorial Markdown

- [x] 2.1 Update admin tutorial screenshot references
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Screenshot paths consistent after rename`
  - **files**: `docs/user-guide/admin/*.md`
  - **acceptance_criteria**:
    - GIVEN all admin tutorial markdown files are updated
    - THEN all `screenshots/tutorials/admin/` references SHALL be replaced with `screenshots/user-guide/admin/`

- [x] 2.2 Update user tutorial screenshot references
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Screenshot paths consistent after rename`
  - **files**: `docs/user-guide/user/*.md`
  - **acceptance_criteria**:
    - GIVEN all user tutorial markdown files are updated
    - THEN all `screenshots/tutorials/user/` references SHALL be replaced with `screenshots/user-guide/user/`

- [x] 2.3 Verify no stale screenshot paths remain
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Screenshot paths consistent after rename`
  - **files**: `docs/` (grep verification)
  - **acceptance_criteria**:
    - GIVEN all path updates have been applied
    - WHEN `git grep 'screenshots/tutorials' docs/` is executed
    - THEN the command SHALL return no output

## 3. Root MD Moves into Technical/

- [x] 3.1 Create Technical/ directory and move ARCHITECTURE.md
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Canonical folder taxonomy`
  - **files**: `docs/ARCHITECTURE.md` → `docs/Technical/architecture.md`
  - **acceptance_criteria**:
    - GIVEN the move is applied
    - THEN `docs/Technical/architecture.md` SHALL exist
    - AND `docs/ARCHITECTURE.md` SHALL NOT exist at docs root

- [x] 3.2 Move development.md to Technical/
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Canonical folder taxonomy`
  - **files**: `docs/development.md` → `docs/Technical/development.md`
  - **acceptance_criteria**:
    - GIVEN the move is applied
    - THEN `docs/Technical/development.md` SHALL exist
    - AND `docs/development.md` SHALL NOT exist at docs root

- [x] 3.3 Move DESIGN-REFERENCES.md to Technical/
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Canonical folder taxonomy`
  - **files**: `docs/DESIGN-REFERENCES.md` → `docs/Technical/design-references.md`
  - **acceptance_criteria**:
    - GIVEN the move is applied
    - THEN `docs/Technical/design-references.md` SHALL exist
    - AND `docs/DESIGN-REFERENCES.md` SHALL NOT exist at docs root

- [x] 3.4 Move FEATURES.md to Technical/ as market-analysis
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Canonical folder taxonomy`
  - **files**: `docs/FEATURES.md` → `docs/Technical/market-analysis.md`
  - **acceptance_criteria**:
    - GIVEN the move is applied
    - THEN `docs/Technical/market-analysis.md` SHALL exist
    - AND `docs/FEATURES.md` SHALL NOT exist at docs root

## 4. GOVERNMENT-FEATURES.md Move + Em-dash Fix

- [x] 4.1 Move GOVERNMENT-FEATURES.md to Features/ and fix em-dashes
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Canonical folder taxonomy`, `specs/docs-product-pages-conformance/spec.md#Em-dash-free content`
  - **files**: `docs/GOVERNMENT-FEATURES.md` → `docs/Features/government-compliance.md`
  - **acceptance_criteria**:
    - GIVEN the move and fixes are applied
    - THEN `docs/Features/government-compliance.md` SHALL exist
    - AND `docs/GOVERNMENT-FEATURES.md` SHALL NOT exist
    - AND line 1 `# Pipelinq — Overheidsfunctionaliteiten` SHALL be `# Pipelinq: Overheidsfunctionaliteiten`
    - AND line 173 `Nextcloud Contacts sync — geen dubbele invoer` SHALL be `Nextcloud Contacts sync, geen dubbele invoer`
    - AND line 194 `Geen apart CRM-systeem — draait als Nextcloud-app` SHALL be `Geen apart CRM-systeem, draait als Nextcloud-app`
    - AND `git grep -E '—' docs/Features/government-compliance.md` SHALL return no output

## 5. Em-dash Gate (full sweep)

- [x] 5.1 Sweep all docs/ for remaining em-dashes and fix them
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Em-dash-free content`
  - **files**: `docs/` (all markdown files)
  - **acceptance_criteria**:
    - GIVEN all documentation changes have been applied
    - WHEN `git grep -E '—' docs/` is executed
    - THEN the command SHALL return no output (exit code 1 = no matches)

## 6. New Stub Files

- [x] 6.1 Create UseCases/index.md stub
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Canonical folder taxonomy`
  - **files**: `docs/UseCases/index.md` (new file)
  - **acceptance_criteria**:
    - GIVEN the stub is created
    - THEN `docs/UseCases/index.md` SHALL exist with `draft: true` frontmatter
    - AND the body SHALL cite issue #353

- [x] 6.2 Create Integrations/index.md stub
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Canonical folder taxonomy`
  - **files**: `docs/Integrations/index.md` (new file)
  - **acceptance_criteria**:
    - GIVEN the stub is created
    - THEN `docs/Integrations/index.md` SHALL exist with `draft: true` frontmatter
    - AND the body SHALL cite issue #353

- [x] 6.3 Create installation.md with real install steps
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Installation guide present`
  - **files**: `docs/installation.md` (new file)
  - **acceptance_criteria**:
    - GIVEN the installation guide is created
    - THEN `docs/installation.md` SHALL exist with at least three numbered installation steps
    - AND the prerequisites section SHALL name Nextcloud 29+ and OpenRegister before the App Store steps
    - AND troubleshooting steps SHALL be included

## 7. i18n Cleanup

- [x] 7.1 Delete stale Dutch locale metadata files
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Dutch locale enabled with clean metadata`
  - **files**: `docs/i18n/nl/code.json`, `docs/i18n/nl/docusaurus-plugin-content-docs/` (all), `docs/i18n/nl/docusaurus-theme-classic/` (all)
  - **acceptance_criteria**:
    - GIVEN the stale files are deleted
    - THEN `docs/i18n/nl/` SHALL contain no JSON metadata files
    - AND the SSR blocker documented in ADR-030 SHALL be resolved

## 8. Re-enable Dutch Locale in Config

- [x] 8.1 Enable nl locale in docusaurus.config.js
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#Dutch locale enabled with clean metadata`
  - **files**: `docs/docusaurus.config.js`
  - **acceptance_criteria**:
    - GIVEN stale metadata has been deleted (task 7.1)
    - WHEN `docs/docusaurus.config.js` is edited
    - THEN `locales: ['en']` SHALL become `locales: ['en', 'nl']`
    - AND `nl: { label: 'Nederlands' }` SHALL be added to `localeConfigs`
    - AND the escape-hatch comment SHALL cite #354 for the full translation pass
    - AND if the build fails on SSR, locales SHALL be reverted to `['en']` with a comment citing #354

## 9. Redocusaurus Setup

- [x] 9.1 Add redocusaurus dependency
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#API documentation route mounted`
  - **files**: `docs/package.json`
  - **acceptance_criteria**:
    - GIVEN the dependency is added
    - THEN `docs/package.json` SHALL contain `"redocusaurus": "^2.0.0"` in `dependencies`

- [x] 9.2 Create OpenAPI placeholder shim
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#API documentation route mounted`
  - **files**: `docs/static/oas/pipelinq.json` (new file)
  - **acceptance_criteria**:
    - GIVEN the placeholder is created
    - THEN `docs/static/oas/pipelinq.json` SHALL contain a minimal valid OpenAPI 3.0 document: `{"openapi":"3.0.0","info":{"title":"Pipelinq","version":"0.0.0"},"paths":{}}`

- [x] 9.3 Mount Redocusaurus plugin at /api
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#API documentation route mounted`
  - **files**: `docs/docusaurus.config.js`
  - **acceptance_criteria**:
    - GIVEN the plugin is mounted
    - THEN the Redocusaurus plugin SHALL be present in the `plugins:` array
    - AND its route SHALL be `/api`
    - AND its spec path SHALL reference `static/oas/pipelinq.json`

- [x] 9.4 Add API Documentation navbar item
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#API documentation route mounted`
  - **files**: `docs/docusaurus.config.js`
  - **acceptance_criteria**:
    - GIVEN the navbar item is added
    - THEN the `navbar.items` array SHALL contain an "API Documentation" entry with `href: '/api'` and `position: 'left'`

## 10. Build Verification

- [x] 10.1 Install dependencies and verify build succeeds
  - **spec_ref**: `specs/docs-product-pages-conformance/spec.md#API documentation route mounted`, `specs/docs-product-pages-conformance/spec.md#Dutch locale enabled with clean metadata`
  - **files**: `docs/` (build output)
  - **acceptance_criteria**:
    - GIVEN all changes are applied
    - WHEN `npm install --legacy-peer-deps && npm run build` is executed in `docs/`
    - THEN the build SHALL exit 0
    - AND `docs/build/` SHALL contain `/api/` route files
    - AND `docs/build/` SHALL contain `/docs/Features/` pages
    - AND `docs/build/` SHALL contain `/docs/user-guide/` pages
    - AND if the build fails on nl SSR: `locales` SHALL be reverted to `['en']` with a comment citing #354

## 11. Commit Openspec Artifacts

- [x] 11.1 Stage and commit openspec change files
  - **files**: `openspec/changes/docs-product-pages-conformance/`
  - **acceptance_criteria**:
    - GIVEN all artifacts are written
    - THEN `git add openspec/changes/docs-product-pages-conformance/` followed by commit message `chore(openspec): add docs-product-pages-conformance change artifacts` SHALL succeed

## 12. Commit Implementation

- [x] 12.1 Stage and commit all docs changes
  - **files**: `docs/`
  - **acceptance_criteria**:
    - GIVEN all documentation changes are staged
    - THEN commit message SHALL be `docs: align with canonical product-pages structure`
    - AND `git checkout HEAD -- openspec/schemas/conduction` SHALL restore the symlink if disturbed
