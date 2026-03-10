# Pipeline & Kanban Specification

## Purpose

Defines the pipeline and kanban board system for Pipelinq. Pipelines are backed by OpenRegister Views, with configurable property mappings for column placement and totals calculation. Cards use the Procest "My Work" compact list styling pattern.

## Requirements

### Requirement: REQ-PIPE-001 Pipeline CRUD [MVP]

The system MUST support creating, reading, updating, and deleting pipelines. Pipelines are managed by admins via the Nextcloud admin settings page AND by users via the pipeline sidebar on the board page.

#### Scenario: Create a pipeline with view reference

- GIVEN an admin on the Pipelinq settings page or a user on the pipeline board sidebar
- WHEN they click "New pipeline" and enter:
  - title: "Enterprise Sales Pipeline"
  - description: "For large government deals over EUR 50,000"
  - viewId: (selected OpenRegister View UUID)
  - propertyMappings: [{ schemaSlug: "lead", columnProperty: "stage", totalsProperty: "value" }]
  - totalsLabel: "EUR"
- THEN the system MUST create an OpenRegister object with `@type` set to `schema:ItemList`
- AND the pipeline MUST store the viewId, propertyMappings, and totalsLabel
- AND the pipeline MUST appear in the pipeline list on the settings page and the board selector

#### Scenario: Edit pipeline view and mappings

- GIVEN an existing pipeline "Sales Pipeline" with a view reference
- WHEN the admin changes the viewId to a different View and updates propertyMappings
- THEN the system MUST update the OpenRegister object
- AND the kanban board MUST reload with items from the new View
- AND column placement MUST use the updated propertyMappings

---

### Requirement: REQ-PIPE-002 Pipeline Entity Types [MVP]

Each pipeline MUST declare which entity types it supports via a referenced OpenRegister View. The View's query defines which schemas (entity types) appear on the pipeline board. The previous `entityType` enum ("lead", "request", "both") is replaced by the View reference.

#### Scenario: View-defined entity types

- GIVEN a pipeline with viewId pointing to a View querying lead and request schemas
- WHEN the kanban board renders
- THEN both leads and requests MUST appear as cards in their respective columns
- AND the pipeline view MUST support a "Show" filter with options derived from the View's schemas (e.g., "All", "Leads only", "Requests only")

#### Scenario: Pipeline with custom schemas

- GIVEN a View that includes lead, request, and a custom "project" schema
- WHEN the pipeline references this View
- THEN items from all three schemas MUST appear on the board
- AND each schema type MUST display a distinct entity badge

---

### Requirement: REQ-PIPE-003 Default Pipelines [MVP]

The system MUST create default pipelines during app initialization (repair step) so the app is usable out-of-the-box without configuration. Default pipelines MUST reference a default OpenRegister View.

#### Scenario: Default Sales Pipeline creation

- GIVEN Pipelinq is installed for the first time (or the repair step runs)
- WHEN the initialization process executes
- THEN a "Sales Pipeline" MUST be created with:
  - viewId: reference to the default Pipelinq View (see REQ-PV-005)
  - propertyMappings: [{ schemaSlug: "lead", columnProperty: "stage", totalsProperty: "value" }, { schemaSlug: "request", columnProperty: "stage", totalsProperty: null }]
  - totalsLabel: "EUR"
  - stages: (same 7 stages as before: New, Contacted, Qualified, Proposal, Negotiation, Won, Lost)
  - isDefault: true
- AND the pipeline MUST NOT be recreated if it already exists on subsequent repair runs

#### Scenario: Default Service Pipeline creation

- GIVEN Pipelinq is installed for the first time
- WHEN the initialization process executes
- THEN a "Service Requests" pipeline MUST be created with:
  - viewId: reference to the default Pipelinq View
  - propertyMappings: [{ schemaSlug: "request", columnProperty: "status", totalsProperty: null }]
  - totalsLabel: null
  - stages: (same 5 stages: New, In Progress, Completed, Rejected, Converted to Case)

---

### Requirement: REQ-PIPE-006 Kanban Board View [MVP]

The system MUST provide a kanban board view for each pipeline. Items are fetched via the pipeline's referenced View and placed in columns based on the pipeline's propertyMappings. Cards MUST use Procest "My Work" list styling.

#### Scenario: Kanban fetches via View API

- WHEN the user selects a pipeline on the board
- THEN the system MUST fetch items using the View API: `/api/objects?_view={viewId}&pipeline={pipelineId}`
- AND items MUST be placed in columns based on their schema's mapped columnProperty value
- AND items with no matching column MUST appear in the first non-closed column

#### Scenario: Mixed entity kanban with property mappings

- GIVEN a pipeline where lead's columnProperty is "stage" and request's columnProperty is "status"
- AND a stage column named "Qualified" exists
- WHEN a lead with stage "Qualified" and a request with status "Qualified" are fetched
- THEN both items MUST appear in the "Qualified" column
- AND the column header total MUST sum only the mapped totalsProperty values (lead.value only)

#### Scenario: Drag and drop updates mapped property

- WHEN user drags a request card from "New" to "In Progress" stage
- THEN the system MUST look up the request's schema in propertyMappings
- AND the system MUST update the mapped columnProperty (e.g., `status`) to "In Progress"
- AND the system MUST NOT modify any other property (e.g., `stage` stays unchanged if the mapping uses `status`)

#### Scenario: Card styling follows Procest pattern

- WHEN a card renders on the kanban board
- THEN it MUST use compact flex-row layout (badge, title, meta, age)
- AND hover MUST use `background: var(--color-background-hover)` with 0.15s transition
- AND overdue items MUST have `border-left: 3px solid var(--color-error)`

---

### Requirement: REQ-PIPE-008 Stage Column Headers [MVP]

Each stage column on the kanban board MUST display configurable aggregate information in its header, based on the pipeline's propertyMappings and totalsLabel.

#### Scenario: Column header with configurable totals

- GIVEN the "Qualified" stage with 4 leads (values: 20000, 18000, 15000, 5000) and 1 request
- AND the pipeline's totalsLabel is "EUR" and lead's totalsProperty is "value"
- WHEN the kanban board is rendered
- THEN the "Qualified" column header MUST display:
  - Stage title: "QUALIFIED"
  - Item count badge: "5"
  - Total: "EUR 58,000" (sum of lead values; request has no totalsProperty)

#### Scenario: Column header without totals

- GIVEN a pipeline with no totalsProperty set for any schema (all null)
- WHEN the kanban board is rendered
- THEN the column header MUST display only the stage title and item count badge
- AND no total line MUST be shown

---

### Requirement: REQ-PIPE-017 Pipeline Selector Dropdown [MVP]

The pipeline view MUST include a dropdown to switch between pipelines. The dropdown MUST show the View's schema names instead of the old entityType labels.

#### Scenario: Pipeline dropdown shows view schemas

- GIVEN 3 pipelines: "Sales" (view with lead), "Service" (view with request), "Mixed" (view with lead + request)
- WHEN the user opens the pipeline dropdown
- THEN each option MUST display the pipeline title and the schema names from its View (e.g., "Sales (Leads)", "Service (Requests)", "Mixed (Leads, Requests)")

## Changelog

### Removed: entityType enum on Pipeline schema

The `entityType` property (enum: "lead", "request", "both") was removed from the pipeline schema in the pipeline-views-overhaul change (2026-02-28). Its functionality is replaced by `viewId` (OpenRegister View reference) which provides a superset of the entity type concept. Views provide unlimited schema combinations with filtering.
