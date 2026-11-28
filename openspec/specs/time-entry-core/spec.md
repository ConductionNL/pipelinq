---
status: done
---

# Time Entry Core Specification

## Purpose

Pipelinq needs hour capture against clients, leads, projects and requests so
that downstream billing (shillinq) and profitability analysis are possible.
Per **hydra ADR-022** (apps consume OpenRegister abstractions over local
duplication), Pipelinq **consumes** the OpenRegister **time-tracker leaf**
(`integration-time-tracker`) for capture rather than building a bespoke time
subsystem. The leaf provides hour capture as a reusable integration:
`TimeProvider` + `TimeTrackerLinks{Controller,Service,Mapper}` (PHP, in
OpenRegister) and `CnTimeTrackerTab` (quick-log form, entries grouped by
user/date, total hours) + `CnTimeTrackerCard` widget (nextcloud-vue). The leaf
is gated on the NC `timemanager` app and stores entries in an OR link table
(`time entries linked to object/user`).

**Standards**: hydra ADR-022 (consume OR abstractions), ADR-024 (app manifest
placement). Leaf id / referenceType `time-tracker`; wrapped NC app `timemanager`.
**Boundary**: capture only — approval, submission, period-locking and invoicing
are handed to shillinq via `time-approval-workflow`.

**OpenSpec changes**: [time-entry-core](../../changes/archive/2026-05-31-time-entry-core/) _(archived 2026-05-31)_, [the-billing-time-entry-is-a-satellite](../../changes/archive/2026-11-28-the-billing-time-entry-is-a-satellite/) _(archived 2026-11-28)_

@e2e exclude consumed-integration + manifest-config spec: Pipelinq builds no time subsystem — capture is delegated to the OpenRegister time-tracker leaf (CnTimeTrackerTab/Card, gated on NC timemanager). The scenarios assert src/manifest.json config (timemanager dependency, time-tracker sidebar tab / dashboard widget) and the absence of bespoke time/billing logic — verified by manifest assertions and source grep; the leaf's UI is owned and tested by the OpenRegister integration, and its rendering is gated on the timemanager app being installed (no stable fixture here).

## Requirements

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
- **THEN** the leaf's `CnTimeTrackerTab` SHALL appear, offering quick-log
  (duration + description), entries grouped by user/date, and total hours on the
  object
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

### Requirement: The billing time entry is named for what it is (REQ-TEC-030)

The schema SHALL be `billingTimeEntry`, not `timeEntry`: a schema slug is global per
organisation, and humaniq and planninq declare a `timeEntry` too. The record is the billing side
of a humaniq booking and SHALL carry a `timeEntry` reference to it, a plain uuid string without
`$ref` (ADR-062 rule 7, cross-register). The app-config key `timeEntry_schema` SHALL NOT move.

#### Scenario: The register declares the namespaced slug

- **WHEN** the register fragments are read
- **THEN** the schema key and its `slug` are both `billingTimeEntry`, and no
  `timeEntry` schema is declared.

#### Scenario: The billing line names the booking it bills

- **WHEN** the merged `billingTimeEntry` is inspected
- **THEN** it carries a `timeEntry` property of type string, format uuid, with
  no `$ref`.

### Requirement: The rename SHALL be migrated on an existing install (REQ-TEC-031)

A repair step SHALL rename the schema row's slug in place, before `InitializeSettings` imports
the register, because OpenRegister creates a new schema when `(application, slug)` misses and
would orphan every existing object. It SHALL be idempotent, and SHALL refuse rather than guess
when both slugs exist or the old slug is duplicated.

#### Scenario: An existing install is renamed in place

- **GIVEN** a schema row with slug `timeEntry` under application `pipelinq`
- **WHEN** the repair step runs
- **THEN** that row's slug is `billingTimeEntry` and its objects stay attached.

#### Scenario: Both slugs present is refused

- **GIVEN** rows carrying both slugs
- **WHEN** the repair step runs
- **THEN** no row is written and the refusal is logged.
