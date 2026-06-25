# pipelinq-navigation Specification

**Status:** proposed
**Scope:** pipelinq
**Tier:** V1
**Depends on:** `@conduction/nextcloud-vue` CnAppNav (manifest-driven nav, settings foldout, icon bridge); `useWalkthrough` engine (ADR-043); `src/menu-layout.json` (relocations + settingsSection).

## Purpose

Declare pipelinq's navigation polish: a single shared icon across the three dashboard
pages, a consolidated "Reports & Compliance" group holding every reporting/analytics
entry, and a Settings "Restart tutorial" action that re-launches the product
walkthrough.

## ADDED Requirements

### Requirement: REQ-NAV-PQ-001 — The Three Dashboards Share One Icon

Every `type:"dashboard"` menu entry SHALL declare the menu `icon`
`icon-category-dashboard` so the navigation renders the same dashboard glyph for each.
This covers `Dashboard` (Commercial), `OperationalDashboard` (Operational) and
`KccWerkplek` (Customer Support).

#### Scenario: All three dashboard entries render the dashboard glyph

- **GIVEN** the pipelinq shell has rendered its navigation
- **WHEN** the Commercial, Operational and Customer Support entries are inspected
- **THEN** each SHALL render the `icon-category-dashboard` glyph (the bridged `view-dashboard-icon`)
- `@e2e exclude` verified live via icon-class inspection; no standing Playwright nav-icon spec in this app.

### Requirement: REQ-NAV-PQ-002 — All Reports Live Under A Reports & Compliance Group

The navigation SHALL present a single "Reports & Compliance" group that contains every
reporting and analytics entry — `Rapportage` (Reporting), `Analytics`,
`PipelineAnalytics`, `BillingCategories`, `SlaAttainment` and `MdmDataQuality` — with
each entry's report page remaining routable. The MDM steward views
(`MdmMasterEntities`, `MdmDuplicates`) SHALL remain under the Settings foldout.

#### Scenario: The group holds every report and each opens

- **GIVEN** the navigation has rendered
- **WHEN** the "Reports & Compliance" group is expanded
- **THEN** it SHALL list Reporting, Analytics, Pipeline Analytics, SLA attainment, Billing categories and Data quality
- **AND** activating each SHALL navigate to its report route
- `@e2e exclude` verified live by expanding the group and asserting child routes; no standing Playwright spec in this app.

### Requirement: REQ-NAV-PQ-003 — Settings Offers A Restart-Tutorial Action

The Settings foldout SHALL offer a "Restart tutorial" entry, under a "Help" caption,
declared as `action: "replay-walkthrough"` with `tourId: "pipelinq:getting-started"`.
Activating it SHALL re-launch the walkthrough from its first step, showing the FULL
tour even for a returning user whose recorded seen-version already covers every step.

#### Scenario: A returning user replays the full tour

- **GIVEN** a user whose `cn-walkthrough-seen:pipelinq` is the current app version
- **WHEN** they activate the "Restart tutorial" Settings entry
- **THEN** the walkthrough SHALL re-launch at step 1 of the getting-started tour
- **AND** every step SHALL be shown (the persisted seen-version SHALL NOT filter the replay)
- `@e2e exclude` verified live (seen-flag set, restart re-opens at 1/11); engine-level behaviour covered by nc-vue `useWalkthrough.spec.js`.
