# app-navigation Specification

**Status:** proposed
**Scope:** pipelinq
**Tier:** leaf
**Depends on:** ADR-037 (declarative-manifest conventions; `menuItem.section` enum `main|footer|settings`; `type: "caption"`), ADR-022 (apps-consume-or-abstractions — surfaces stay consumer pages, no controller change), ADR-012 (deduplication)

## Purpose

Relocate pipelinq's four configuration/integration navigation surfaces — pipeline
**definitions** (`Pipelines`), **BI export** (`ExportJobs`), **StUF endpoints**
(`StufEndpoints`), and the read-only **StUF audit log** (`StufAuditLog`) — out of the
top-level transactional navigation and into the `CnAppNav` Settings (gear-icon) foldout,
following the docudesk/procest "config-under-Settings" IA model and the decidesk
demote-not-delete precedent. The relocation distinguishes the config surface **Pipelines**
(pipeline *definitions*) from the operational board **Pipeline**, which stays top-level. All
four pages remain routable for deep links. This is a navigation/IA change only — no schema,
controller, route-table, or data change.

## MODIFIED Requirements

### Requirement: REQ-PICS-001 — The system SHALL render the four config/integration surfaces inside the Settings foldout, not the top-level main list

The system SHALL place the pipelinq `menu` entries `Pipelines`, `ExportJobs`, `StufEndpoints`,
and `StufAuditLog` in the `CnAppNav` Settings (gear-icon) foldout by setting
`"section": "settings"` on each entry in `src/manifest.json`, so none of these four config and
integration surfaces renders in the top-level `main` navigation list. The change SHALL set only
the `section` field on these four existing entries and SHALL NOT alter their `id`, `route`,
`label`, `icon`, or `order`. No other top-level menu item SHALL be moved or reordered.

#### Scenario: Config/integration entries appear in the Settings foldout

- GIVEN the pipelinq app is in the ready state
- WHEN the `CnAppNav` Settings (gear-icon) foldout is opened
- THEN it lists `Pipelines` (route `Pipelines`), `BI export` (route `ExportJobs`), `StUF endpoints` (route `StufEndpoints`), and `StUF audit log` (route `StufAuditLog`)

#### Scenario: Config/integration entries are absent from the top-level main list

- GIVEN the pipelinq top-level navigation renders from the manifest `menu`
- WHEN the `main` section is inspected
- THEN no `main`-section item has route `Pipelines`, `ExportJobs`, `StufEndpoints`, or `StufAuditLog`

#### Scenario: The four entries carry section settings in the manifest

- GIVEN `src/manifest.json`
- WHEN the `menu` entries with id `Pipelines`, `ExportJobs`, `StufEndpoints`, and `StufAuditLog` are inspected
- THEN each carries `"section": "settings"` and its `id`, `route`, `label`, and `order` are otherwise unchanged

### Requirement: REQ-PICS-002 — The system SHALL keep the operational Pipeline board in the top-level transactional navigation

The system SHALL keep the operational `Pipeline` board (`menu` id `Pipeline`, route `Pipeline`
→ `/pipeline`, `component PipelineBoardView`, order 100) in the top-level `main` navigation list,
distinct from the relocated `Pipelines` definitions surface. Moving the config/integration
entries SHALL NOT change, reorder, or relocate the `Pipeline` board entry, and SHALL NOT move
any other operational/transactional surface (e.g. Leads, Requests, Tasks, Clients, POS).

#### Scenario: Operational Pipeline board stays top-level

- GIVEN the pipelinq top-level navigation renders from the manifest `menu`
- WHEN the `main` section is inspected
- THEN it still contains the `Pipeline` entry (route `Pipeline`) at order 100
- AND the `Pipeline` entry's `section` remains `main` (the default, no `section` override)

#### Scenario: Pipeline (board) and Pipelines (definitions) are not confused

- GIVEN the manifest `menu`
- WHEN the entries `Pipeline` (route `Pipeline`) and `Pipelines` (route `Pipelines`) are compared
- THEN `Pipeline` renders in `main` and `Pipelines` renders in `settings`

### Requirement: REQ-PICS-003 — The system SHALL keep all four relocated pages routable for deep links

The system SHALL keep the `pages[]` entries `Pipelines` (`/pipelines`, type `index`),
`ExportJobs` (`/export/jobs`, type `custom`, component `ExportJobsView`), `StufEndpoints`
(`/stuf/endpoints`, type `custom`, component `StufEndpointsView`), and `StufAuditLog`
(`/stuf/audit-log`, type `custom`, component `StufAuditLogView`) registered and routable after
the menu relocation. Relocating the menu entries SHALL NOT remove, rename, re-type, or otherwise
modify any `pages[]` entry, so deep links, bookmarks, and in-page action navigations to these
four routes continue to resolve — the demote-not-delete behaviour established by decidesk's
`decidesk-retire-motions-nav` / `ia-six-item-nav`.

#### Scenario: Deep links to the relocated surfaces still resolve

- GIVEN the four config/integration menu entries have been moved to the Settings foldout
- WHEN a user opens `/pipelines`, `/export/jobs`, `/stuf/endpoints`, or `/stuf/audit-log` directly
- THEN the corresponding view renders inside the app shell

#### Scenario: No page entry is removed by the relocation

- GIVEN `src/manifest.json` `pages[]`
- WHEN it is inspected after this change
- THEN it still contains entries with id `Pipelines`, `ExportJobs`, `StufEndpoints`, and `StufAuditLog`, each with its original `route`, `type`, and `component`

## ADDED Requirements

### Requirement: REQ-PICS-004 — The system SHALL group the integration surfaces under an Integrations caption in the Settings foldout

The system SHALL add one new `menu` entry `{ id: "SettingsIntegrationsCaption", label:
"Integrations", type: "caption", section: "settings", order: 205 }` so that the integration
surfaces `ExportJobs` (BI export), `StufEndpoints` (StUF endpoints), and `StufAuditLog` (the
read-only StUF audit log) render beneath an **Integrations** caption divider inside the Settings
foldout, while the `Pipelines` definitions/config surface renders above the caption (order
200 < 205 < 215 ≤ 216 ≤ 217). The caption entry SHALL be of `type: "caption"` so it renders an
`NcAppNavigationCaption` divider and never becomes a navigable target, and the English source
l10n key `Integrations` SHALL be added to the pipelinq catalogue (English-source-key convention).

#### Scenario: Integrations caption divides the Settings foldout

- GIVEN the `CnAppNav` Settings foldout is open
- WHEN its entries are read top-to-bottom
- THEN `Pipelines` appears first, then the `Integrations` caption, then `BI export`, `StUF endpoints`, and `StUF audit log`

#### Scenario: The Integrations caption is not a navigation target

- GIVEN the `SettingsIntegrationsCaption` menu entry
- WHEN it is inspected
- THEN it has `type: "caption"` and no `route`, so clicking it dispatches no vue-router navigation
