# Spec delta — time-entry-core (consume the time-tracker leaf)

## ADDED Requirements

### Requirement: Pipelinq consumes the time-tracker leaf for hour capture

Pipelinq SHALL NOT implement a bespoke time-capture subsystem. Hour capture,
the time-entry data model, timer state, weekly grouping, and totals SHALL be
provided by the OpenRegister **time-tracker leaf**
(`integration-time-tracker`). Pipelinq consumes the leaf rather than building a
parallel mechanism (hydra ADR-022).

#### Scenario: No bespoke time subsystem exists in Pipelinq

- **GIVEN** the time-entry-core change is applied
- **THEN** Pipelinq SHALL define no `timeEntry` schema in
  `lib/Settings/pipelinq_register.json`
- **AND** Pipelinq SHALL define no `TimerController`, no `TimeEntryService`, and
  no bespoke timer/grid/list/detail Vue views
- **AND** all hour capture SHALL flow through the time-tracker leaf's
  `TimeController` / OR integration link endpoints (`openregister_*_links`).

#### Scenario: Capture happens via the leaf tab

- **GIVEN** the NC `timemanager` app is installed and the time-tracker leaf is
  registered
- **WHEN** a user opens a Pipelinq object whose schema declares `time-tracker`
  in `linkedTypes`
- **THEN** the leaf's `CnTimeTab` SHALL appear, offering quick-log (duration +
  description), entries grouped by user/date, and total hours on the object
- **AND** the entries SHALL be stored by the leaf in its OR link table, linked
  to the Pipelinq object and the logging user.

### Requirement: Pipelinq declares which entities accept time entries

The only pipelinq-specific glue SHALL be declaring which Pipelinq schemas
support hour capture, by adding `time-tracker` to each schema's `linkedTypes`.

#### Scenario: Billable entities expose the time-tracker leaf

- **GIVEN** the Pipelinq register schemas for `client`, `lead`, `request`, and
  any project/deal entity
- **WHEN** the register is imported via `ConfigurationService::importFromApp()`
- **THEN** each of those schemas SHALL list `time-tracker` in its `linkedTypes`
- **AND** schemas that should not accept hours (e.g. lookup/config schemas)
  SHALL NOT list `time-tracker`.

### Requirement: Leaf widget and tab are placed via the app manifest

The time-tracker leaf's widget and tab SHALL be surfaced through Pipelinq's
`src/manifest.json` per ADR-024, not via bespoke component mounting.

#### Scenario: Detail sidebar shows the time-tracker tab

- **GIVEN** a Pipelinq detail page for a billable entity (client/lead/request)
- **WHEN** the manifest is rendered
- **THEN** the page's `sidebar` configuration SHALL include the time-tracker
  leaf tab
- **AND** the tab SHALL be filtered to the current object's context.

#### Scenario: Dashboard surfaces today's hours

- **GIVEN** the Pipelinq dashboard page in `src/manifest.json`
- **WHEN** the dashboard renders
- **THEN** it MAY include the leaf's "today's hours" widget as a
  `type:"dashboard"` widget entry
- **AND** the widget SHALL read from the leaf, not from a pipelinq-owned route.

### Requirement: timemanager dependency is declared

Pipelinq SHALL declare the NC `timemanager` app — wrapped by the time-tracker
leaf — as a runtime dependency in its app manifest, alongside `openregister`.

#### Scenario: Manifest lists the runtime dependency

- **GIVEN** Pipelinq's `src/manifest.json`
- **THEN** `dependencies[]` SHALL include `timemanager` (the NC app the
  time-tracker leaf wraps)
- **AND** `openregister` SHALL already be present as the data-layer dependency.

### Requirement: Approval and invoicing are out of scope

Hour approval, weekly submission, period locking, and invoicing SHALL NOT be
implemented by this change. The time-tracker leaf explicitly excludes them; they
are handed to shillinq (see the `time-approval-workflow` change).

#### Scenario: No approval or billing logic ships in Pipelinq

- **GIVEN** the time-entry-core change is applied
- **THEN** Pipelinq SHALL contain no timesheet submission, approval, locking, or
  invoicing logic for time entries
- **AND** those capabilities SHALL be delegated to shillinq per
  `time-approval-workflow`.
