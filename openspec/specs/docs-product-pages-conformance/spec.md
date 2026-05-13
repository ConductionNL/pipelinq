**Capability**: docs-product-pages-conformance
**Status**: in-progress
**OpenSpec changes**:
- `docs-product-pages-conformance` (2026-05-13) — Initial: canonical folder taxonomy, installation guide, Redocusaurus /api mount, nl locale, em-dash cleanup

---

## Requirements

### Requirement: Canonical folder taxonomy
The docs site SHALL organise content under the canonical folder set: `Features/`, `user-guide/`, `UseCases/`, `Integrations/`, and `Technical/`. Folder names SHALL use the exact casing defined by the `@conduction/docusaurus-preset` product-pages spec. No content SHALL remain under the old `features/` (lowercase) or `tutorials/` names.

#### Scenario: Features folder is correctly cased
- **WHEN** a visitor or CI job scans the docs directory
- **THEN** `docs/Features/` exists with all 40 feature markdown files and `docs/features/` does not exist

#### Scenario: User-guide folder replaces tutorials
- **WHEN** a visitor or CI job scans the docs directory
- **THEN** `docs/user-guide/` exists with admin/ and user/ subdirectories containing all 18 tutorial files and `docs/tutorials/` does not exist

#### Scenario: Technical folder contains moved root MDs
- **WHEN** a visitor or CI job scans the docs directory
- **THEN** `docs/Technical/` exists and contains `architecture.md`, `development.md`, `design-references.md`, and `market-analysis.md`

#### Scenario: Stub folders exist for future content
- **WHEN** a visitor or CI job scans the docs directory
- **THEN** `docs/UseCases/index.md` and `docs/Integrations/index.md` both exist with `draft: true` frontmatter

### Requirement: Installation guide present
The docs site SHALL provide a dedicated `docs/installation.md` with step-by-step instructions for installing Pipelinq on Nextcloud. It SHALL cover prerequisites, App Store installation, initial configuration of registers and pipeline stages, and basic troubleshooting steps.

#### Scenario: Installation guide is reachable
- **WHEN** a visitor navigates to the docs sidebar
- **THEN** an "Installation" entry appears and links to a page with at least three numbered steps

### Requirement: API documentation route mounted
The docs site SHALL mount Redocusaurus at the `/api` route. The route SHALL render an OpenAPI specification sourced from `docs/static/oas/pipelinq.json`.

#### Scenario: API Documentation navbar entry exists
- **WHEN** a visitor loads any page on the docs site
- **THEN** the navbar contains an "API Documentation" link

#### Scenario: Build succeeds with placeholder shim
- **WHEN** `npm run build` is executed in the docs/ directory
- **THEN** the build exits 0 and generates the /api route

### Requirement: Dutch locale enabled with clean metadata
The docs site SHALL declare `nl` as a supported locale in `docusaurus.config.js`. Stale `i18n/nl/` JSON metadata files SHALL be removed before re-enabling the locale.

#### Scenario: Locale dropdown shows Nederlands
- **WHEN** a visitor inspects the navbar locale dropdown
- **THEN** "Nederlands" is listed as a selectable option

### Requirement: Em-dash-free content
All markdown files under `docs/` SHALL be free of em-dash characters (Unicode U+2014).

#### Scenario: Em-dash gate passes
- **WHEN** `git grep -E '—' docs/` is executed after all changes
- **THEN** the command returns no output (exit code 1 = no matches)

### Requirement: Screenshot paths consistent after rename
All image references inside `docs/user-guide/` markdown files SHALL point to `docs/static/screenshots/user-guide/`.

#### Scenario: Tutorial screenshots render after rename
- **WHEN** a visitor opens any tutorial page on the live site
- **THEN** all inline screenshots render without broken-image placeholders
