---
status: done
---

**Capability**: docs-product-pages-conformance
**Status**: in-progress
**OpenSpec changes**:
- `docs-product-pages-conformance` (2026-05-13) — Initial: canonical folder taxonomy, installation guide, Redocusaurus /api mount, nl locale, em-dash cleanup

---

## Purpose

Bring the Pipelinq documentation site into conformance with the `@conduction/docusaurus-preset` product-pages standard: a canonical folder taxonomy, an installation guide, a Redocusaurus `/api` mount, an `nl` locale, and consistent typography. Conformance is a docs-site structure concern verified by CI file-tree checks, not a Nextcloud app UI.
## Requirements

@e2e exclude documentation CI concern — folder taxonomy conformance is a docs-site structure check, not a Nextcloud app UI; covered by CI file-tree checks

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

### Requirement: Licence Claims Conformance

Every human-readable licence statement SHALL say EUPL-1.2, matching `LICENSE`: the README licence badge, and the English and Dutch descriptions in `appinfo/info.xml`. The machine `<licence>` element SHALL be `EUPL-1.2` — the SPDX token accepted by Nextcloud's app-info.xsd licence enum since the 2026-05-07 upstream addition (nextcloud/server PR #60212; also in the App Store's accepted-licenses fixtures) — per the product-owner decision of 2026-07-05. Only when the targeted Nextcloud version ships an app-info.xsd predating the EUPL enum value MAY the element fall back to the previous schema-valid value (`agpl`) annotated with an XML comment naming EUPL-1.2 as the canonical licence. The same flip is a fleet-wide follow-up (openregister and other Conduction apps declare `agpl` today) tracked outside this change.

**Feature tier**: MVP

#### Scenario: No AGPL prose remains

- WHEN README.md and appinfo/info.xml are searched for licence statements
- THEN the badge and both description texts MUST reference EUPL-1.2
- AND no prose MUST claim the project is AGPL-licensed

#### Scenario: info.xml stays schema-valid

- WHEN appinfo/info.xml is validated against a Nextcloud app-info.xsd carrying the EUPL enum value (upstream master, stable31+ branch heads, tagged releases from v33.0.5)
- THEN validation MUST pass with `<licence>EUPL-1.2</licence>`

### Requirement: Feature Claims Match Implementation

README.md and appinfo/info.xml SHALL contain no feature claim without implementing code at HEAD: Unified Search SHALL be attributed to OpenRegister (`lib/Search/ObjectsProvider.php` provides it centrally); the Request-to-Case Bridge SHALL be presented as roadmap pointing at the `semantic-handoff-emit` change until that change ships; duplicate detection SHALL be attributed to OpenRegister master-data management (the in-app engine was removed in PR#332); the CSV-import claim SHALL be removed (no CSV import exists — vCard claims remain, backed by code).

**Feature tier**: MVP

#### Scenario: Search claim attributes OR

- WHEN the README Unified Search entry is read
- THEN it MUST state the capability is provided via OpenRegister, not as pipelinq code

#### Scenario: Bridge claim is roadmap until shipped

- GIVEN `semantic-handoff-emit` has not shipped
- WHEN the Request-to-Case wording in README/info.xml is read
- THEN it MUST be marked as in development referencing `semantic-handoff-emit`, not presented as a working feature

#### Scenario: No unbacked import claim

- WHEN info.xml and README import/export wording is read
- THEN no bulk CSV-import support MUST be claimed
- AND vCard import/export claims MUST remain only where code backs them

### Requirement: Features Overlay Status Honesty

`openspec/features.overlay.json` SHALL report `omnichannel-registratie` as `beta` with a recorded reason: outbound WhatsApp/SMS send has no production callers and no UI surface, and SLA notification dispatch defers all channels except nextcloud-notification, while inbound webhooks are wired. The EN/NL summaries SHALL describe inbound registration and consent logging rather than promising outbound reach. (Alternative — wiring outbound send — is an owner decision outside this change; downgrade is the default.)

**Feature tier**: MVP

#### Scenario: Overlay reflects outbound reality

- WHEN `features.overlay.json` is read
- THEN the `omnichannel-registratie` entry MUST carry `status: beta` and a reason naming the unwired outbound path
- AND its summaries MUST NOT promise reaching clients via WhatsApp/SMS

### Requirement: docs/features.json Matches Shipped Reality

`docs/features.json` SHALL NOT describe shipped capabilities as unimplemented: the `kcc-werkplek` entry SHALL describe the shipped Customer Support workspace instead of "not yet implemented; no UI surface to test". Factual staleness found in adjacent entries during apply SHALL be corrected in the same batch.

**Feature tier**: MVP

#### Scenario: kcc-werkplek entry is current

- WHEN `docs/features.json` is read
- THEN the kcc-werkplek entry MUST describe the shipped surface and MUST NOT claim it is unimplemented

