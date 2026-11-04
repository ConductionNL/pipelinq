# pipelinq-navigation Specification

## Purpose
This spec fixes how Pipelinq's navigation reads: the three dashboards share one dashboard icon, the reports sit on one reports page, and settings offers an action to restart the tutorial.

## Requirements
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

### Requirement: REQ-NAV-PQ-002: Reports live on one reports page

The navigation SHALL carry one "Rapportages" entry in its footer, which opens the page that lists every report per category. There SHALL be no "Reports & Compliance" group. The MDM steward views stay in OpenRegister (REQ-MDM-013).

#### Scenario: The Rapportages entry opens every report

- **GIVEN** the navigation has rendered
- **WHEN** a user opens "Rapportages"
- **THEN** the page SHALL list the reports per category, with a filter "Alle categorieën"
- **AND** the navigation SHALL show no "Reports & Compliance" group
- `@e2e exclude` a menu declaration; the absence of the group is asserted for `specs/nav-ia-cleanup`.

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

