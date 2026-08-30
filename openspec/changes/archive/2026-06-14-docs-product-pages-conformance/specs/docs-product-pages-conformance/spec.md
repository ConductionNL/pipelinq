# Delta Spec: Docs Product Pages Conformance

## Changes to specs/docs-product-pages-conformance/spec.md

### ADDED Requirements

#### Requirement: Canonical folder taxonomy

The docs site SHALL organise content under the canonical folder set: `Features/`, `user-guide/`, `UseCases/`, `Integrations/`, and `Technical/`. Folder names SHALL use the exact casing defined by the `@conduction/docusaurus-preset` product-pages spec. No content SHALL remain under the old `features/` (lowercase) or `tutorials/` names.

##### Scenario: Features folder is correctly cased

- GIVEN the docs directory has been migrated
- WHEN a visitor or CI job scans the docs directory
- THEN `docs/Features/` SHALL exist with all 40 feature markdown files
- AND `docs/features/` (lowercase) SHALL NOT exist

##### Scenario: User-guide folder replaces tutorials

- GIVEN the docs directory has been migrated
- WHEN a visitor or CI job scans the docs directory
- THEN `docs/user-guide/` SHALL exist with admin/ and user/ subdirectories containing all 18 tutorial files
- AND `docs/tutorials/` SHALL NOT exist

##### Scenario: Technical folder contains moved root MDs

- GIVEN the docs directory has been migrated
- WHEN a visitor or CI job scans the docs directory
- THEN `docs/Technical/` SHALL exist and contain `architecture.md`, `development.md`, `design-references.md`, and `market-analysis.md`
- AND none of the original root-level filenames (ARCHITECTURE.md, development.md, DESIGN-REFERENCES.md, FEATURES.md) SHALL exist at `docs/` root

##### Scenario: Government compliance feature doc is in Features/

- GIVEN the docs directory has been migrated
- WHEN a visitor or CI job scans the docs directory
- THEN `docs/Features/government-compliance.md` SHALL exist
- AND `docs/GOVERNMENT-FEATURES.md` SHALL NOT exist

##### Scenario: Stub folders exist for future content

- GIVEN the docs directory has been migrated
- WHEN a visitor or CI job scans the docs directory
- THEN `docs/UseCases/index.md` SHALL exist with `draft: true` frontmatter
- AND `docs/Integrations/index.md` SHALL exist with `draft: true` frontmatter

---

#### Requirement: Installation guide present

The docs site SHALL provide a dedicated `docs/installation.md` with step-by-step instructions for installing Pipelinq on Nextcloud. It SHALL cover prerequisites (Nextcloud 29+, OpenRegister), App Store installation, initial configuration of registers and pipeline stages, and basic troubleshooting steps.

##### Scenario: Installation guide is reachable

- GIVEN a visitor has opened the Pipelinq docs site
- WHEN they navigate to the docs sidebar
- THEN an "Installation" entry SHALL appear
- AND the linked page SHALL contain at least three numbered installation steps

##### Scenario: Prerequisites are documented

- GIVEN a visitor has opened `installation.md`
- WHEN they read the prerequisites section
- THEN the Nextcloud version requirement (29+) SHALL be clearly stated
- AND the OpenRegister dependency SHALL be named before the App Store install steps

---

#### Requirement: API documentation route mounted

The docs site SHALL mount Redocusaurus at the `/api` route. The route SHALL render an OpenAPI specification sourced from `docs/static/oas/pipelinq.json`. While the real spec is pending (#355), a valid placeholder shim SHALL be present so the build succeeds and the route resolves.

##### Scenario: API Documentation navbar entry exists

- GIVEN a visitor loads any page on the docs site
- WHEN they inspect the navbar
- THEN the navbar SHALL contain an "API Documentation" link

##### Scenario: API route resolves without 404

- GIVEN a visitor has opened the docs site
- WHEN they navigate to `/api`
- THEN the page SHALL render (placeholder or real spec) without a 404 error

##### Scenario: Build succeeds with placeholder shim

- GIVEN `docs/static/oas/pipelinq.json` contains a minimal valid OpenAPI 3.0 document
- WHEN `npm run build` is executed in the `docs/` directory
- THEN the build SHALL exit 0
- AND the `/api` route files SHALL be present in `docs/build/`

---

#### Requirement: Dutch locale enabled with clean metadata

The docs site SHALL declare `nl` as a supported locale in `docusaurus.config.js`. Stale `i18n/nl/` JSON metadata files (code.json, docusaurus-plugin-content-docs/, docusaurus-theme-classic/) that previously caused SSR failures SHALL be removed before re-enabling the locale. If the build still fails after metadata cleanup, the locale SHALL be reverted to `['en']` with a comment citing issue #354.

##### Scenario: Locale dropdown shows Nederlands

- GIVEN the `nl` locale has been re-enabled and the site is built
- WHEN a visitor inspects the navbar locale dropdown
- THEN "Nederlands" SHALL be listed as a selectable option

##### Scenario: Build passes with nl locale enabled

- GIVEN stale `i18n/nl/` metadata files have been deleted
- WHEN `npm run build` is executed with `locales: ['en', 'nl']`
- THEN the build SHALL exit 0

---

#### Requirement: Em-dash-free content

All markdown files under `docs/` SHALL be free of em-dash characters (Unicode U+2014, `—`). Em-dashes SHALL be replaced with colons, commas, or rephrased sentences as appropriate for each context.

##### Scenario: Em-dash gate passes

- GIVEN all documentation changes have been applied
- WHEN `git grep -E '—' docs/` is executed
- THEN the command SHALL return no output (exit code 1 = no matches found)

---

#### Requirement: Screenshot paths consistent after rename

All image references inside `docs/user-guide/` markdown files SHALL point to `docs/static/screenshots/user-guide/` (not the former `tutorials/` path). The `docs/static/screenshots/user-guide/` directory SHALL contain the same screenshots previously under `docs/static/screenshots/tutorials/`.

##### Scenario: Tutorial screenshots render after rename

- GIVEN `docs/static/screenshots/tutorials/` has been renamed to `docs/static/screenshots/user-guide/`
- AND all path references in tutorial markdown files have been updated
- WHEN a visitor opens any tutorial page on the live site
- THEN all inline screenshots SHALL render without broken-image placeholders

##### Scenario: No stale screenshots/tutorials references remain

- GIVEN all path updates have been applied
- WHEN `git grep 'screenshots/tutorials' docs/` is executed
- THEN the command SHALL return no output
