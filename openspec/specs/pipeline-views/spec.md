# Pipeline Views Specification

## Purpose

Pipelines are backed by OpenRegister Views instead of a hardcoded entity type enum. A View is a saved search configuration that can span multiple schemas and registers with filters. This enables pipelines to work with any combination of entity types, with per-schema configuration for how items map to columns and how totals are calculated.

**Standards**: OpenRegister View API (`/api/views`), Schema.org (`ItemList`)

## Requirements

### Requirement: REQ-PV-001 View-Backed Pipelines

Each pipeline MUST reference an OpenRegister View via a `viewId` property. The View defines which entity types (schemas) appear on the pipeline board. This replaces the previous `entityType` enum.

#### Scenario: Pipeline with view reference

- GIVEN a pipeline "Sales Pipeline" with `viewId` pointing to a View that queries the lead and request schemas on the pipelinq register
- WHEN the kanban board loads
- THEN the system MUST fetch items using the View's query configuration
- AND items from all schemas included in the View MUST appear on the board
- AND items MUST be placed in columns based on their schema's property mapping (see REQ-PV-002)

#### Scenario: Pipeline without view reference (legacy fallback)

- GIVEN a pipeline that has no `viewId` but has the deprecated `entityType` property
- WHEN the kanban board loads
- THEN the system MUST fall back to fetching items based on `entityType` (lead/request/both)
- AND the board MUST function identically to the pre-overhaul behavior

#### Scenario: View with multiple schemas

- GIVEN a View that queries lead, request, and a custom "project" schema
- WHEN a pipeline references this View
- THEN items from all three schemas MUST appear on the board
- AND each schema type MUST have its own entity badge color and label

---

### Requirement: REQ-PV-002 Property-to-Stage Mapping

Each pipeline MUST support a `propertyMappings` array that defines, per schema, which property determines an item's column placement on the kanban board. This allows different entity types to use different properties for column positioning.

#### Scenario: Lead uses stage property, request uses status property

- GIVEN a pipeline with propertyMappings:
  ```json
  [
    { "schemaSlug": "lead", "columnProperty": "stage", "totalsProperty": "value" },
    { "schemaSlug": "request", "columnProperty": "status", "totalsProperty": null }
  ]
  ```
- WHEN a lead with `stage: "Qualified"` and a request with `status: "Qualified"` are fetched
- THEN both items MUST appear in the "Qualified" column
- AND the lead's column is determined by its `stage` property
- AND the request's column is determined by its `status` property

#### Scenario: Schema without explicit mapping

- GIVEN a View that includes the "project" schema
- AND the pipeline's propertyMappings has no entry for "project"
- WHEN items are rendered
- THEN items from the "project" schema MUST fall back to using a `stage` property for column placement
- AND if the item has no `stage` property, it MUST appear in the first non-closed column

#### Scenario: Property value does not match any stage name

- GIVEN a request with `status: "converted"` and no pipeline stage named "converted"
- WHEN the board renders
- THEN the item MUST appear in an "Unassigned" overflow area or the first non-closed column
- AND the system MUST NOT hide or discard the item

---

### Requirement: REQ-PV-003 Drag Updates Mapped Property

When a user drags a card between columns, the system MUST update the mapped column property on the actual object — not a generic `stage` field. The property that gets updated is determined by the item's schema and the pipeline's propertyMappings.

#### Scenario: Drag lead between columns updates stage property

- GIVEN a pipeline where lead's columnProperty is "stage"
- AND a lead "TechCorp Deal" is in the "New" column (stage: "New")
- WHEN the user drags it to the "Qualified" column
- THEN the system MUST update the lead object's `stage` property to "Qualified" via the OpenRegister API
- AND the lead MUST appear in the "Qualified" column after the update

#### Scenario: Drag request between columns updates status property

- GIVEN a pipeline where request's columnProperty is "status"
- AND a request "IT Support #42" is in the "New" column (status: "new")
- WHEN the user drags it to the "In Progress" column
- THEN the system MUST update the request object's `status` property to "In Progress" via the OpenRegister API
- AND the request MUST NOT have a `stage` property modified

#### Scenario: Drag data payload includes schema information

- GIVEN a card being dragged
- WHEN the drag starts
- THEN the drag payload MUST include the item's schema slug (e.g., "lead" or "request")
- AND the drop handler MUST look up the propertyMappings for that schema to determine which property to update

---

### Requirement: REQ-PV-004 Configurable Column Totals

Each pipeline MUST support configurable totals in column headers. The `propertyMappings` defines which numeric property to sum per schema, and the pipeline's `totalsLabel` provides the display unit.

#### Scenario: Column totals from lead value property

- GIVEN a pipeline with totalsLabel "EUR" and lead's totalsProperty "value"
- AND the "Qualified" column contains 3 leads with values 20000, 18000, 15000 and 2 requests
- WHEN the column header renders
- THEN it MUST display "EUR 53,000" (sum of lead values only; requests have no totalsProperty)

#### Scenario: Column totals from multiple schemas

- GIVEN a pipeline where lead's totalsProperty is "value" and request's totalsProperty is "estimatedHours"
- AND the "New" column has 2 leads (value: 10000, 5000) and 1 request (estimatedHours: 40)
- WHEN the column header renders
- THEN the primary total MUST display "EUR 15,000" (from leads, using totalsLabel)
- AND a secondary total MAY display "40h" if a secondary totals display is configured

#### Scenario: No totals configured

- GIVEN a pipeline where no schema has a totalsProperty set (all null)
- WHEN the column header renders
- THEN the column header MUST display item count only (e.g., "5 items")
- AND no monetary or numeric total MUST be shown

---

### Requirement: REQ-PV-005 Default View Creation on Initialization

The system MUST create a default OpenRegister View during app initialization that spans the lead and request schemas on the pipelinq register. This ensures the default pipelines work out-of-the-box.

#### Scenario: First-time installation creates default view

- GIVEN Pipelinq is installed for the first time
- WHEN the initialization repair step runs
- THEN a View MUST be created with:
  - name: "Pipelinq Default View"
  - description: "Default view for pipeline boards - includes leads and requests"
  - query.registers: [pipelinq register ID]
  - query.schemas: [lead schema ID, request schema ID]
  - isDefault: true
- AND the default Sales Pipeline MUST have its `viewId` set to this View's UUID
- AND the default Service Requests Pipeline MUST have its `viewId` set to this View's UUID

#### Scenario: Idempotent view creation

- GIVEN the default View already exists
- WHEN the repair step runs again
- THEN the system MUST NOT create a duplicate View
- AND existing View configuration MUST be preserved

---

### Requirement: REQ-PV-006 View Import in Configuration Service

OpenRegister's ConfigurationService MUST accept `views` in the import JSON alongside `registers` and `schemas`. This allows apps to define views in their settings JSON file.

#### Scenario: Import JSON with views section

- GIVEN an app settings JSON containing:
  ```json
  {
    "components": {
      "registers": { ... },
      "schemas": { ... },
      "views": {
        "default-pipeline-view": {
          "name": "Pipelinq Default View",
          "description": "Default view for pipeline boards",
          "query": {
            "registers": ["pipelinq"],
            "schemas": ["lead", "request"]
          },
          "isDefault": true
        }
      }
    }
  }
  ```
- WHEN ConfigurationService::importFromApp() processes this JSON
- THEN it MUST create the View object with the specified configuration
- AND the view's query.registers and query.schemas MUST be resolved to actual UUIDs
- AND the import result MUST include the created view IDs

#### Scenario: View references resolved schemas

- GIVEN a view definition referencing schemas by slug ("lead", "request")
- WHEN the import processes the view
- THEN schema slugs MUST be resolved to their actual UUIDs from the same import batch
- AND if a referenced schema does not exist, the import MUST log a warning but not fail

---

### Requirement: REQ-PV-007 Pipeline Creation from Sidebar

The pipeline sidebar MUST support creating new pipelines in addition to viewing and editing existing ones.

#### Scenario: Create new pipeline from sidebar

- GIVEN the user is on the pipeline board page with the sidebar open
- WHEN the user clicks "New pipeline" in the sidebar
- THEN the PipelineForm MUST open in create mode (empty form)
- AND the form MUST include: title, description, view selector, property mappings, stages, totals label
- AND upon saving, the new pipeline MUST appear in the pipeline selector dropdown
- AND the board MUST switch to the newly created pipeline

#### Scenario: View selector in pipeline form

- GIVEN the pipeline form is open
- WHEN the user selects a View from the view dropdown
- THEN the property mappings section MUST auto-populate with one row per schema in the selected View
- AND each row MUST show the schema name and dropdowns for columnProperty and totalsProperty
- AND the available properties MUST be fetched from the schema's property definitions

---

### Requirement: REQ-PV-008 Procest-Style Card Styling

Pipeline cards and kanban columns MUST follow the Procest dashboard "My Work" list card styling pattern for visual consistency across Conduction apps.

#### Scenario: Card renders as compact list item

- GIVEN a lead card on the kanban board
- WHEN the card renders
- THEN it MUST use a compact flex-row layout: entity badge, title (truncated), meta info, age/date
- AND padding MUST be 8px with 8-10px gap between elements
- AND hover MUST show `background: var(--color-background-hover)` with 0.15s transition
- AND overdue items MUST have `border-left: 3px solid var(--color-error)`

#### Scenario: Column renders as dashboard widget

- GIVEN a kanban column
- WHEN the column renders
- THEN it MUST have `border: 1px solid var(--color-border)` and `border-radius: var(--border-radius-large)`
- AND the column header MUST have a color-coded top border (from stage color)
- AND the header MUST display: stage title (uppercase, 13px, bold), item count badge, configurable total
- AND the column background MUST use `var(--color-main-background)` (not dark background)

#### Scenario: Entity badge styling

- GIVEN items from different schemas on the board
- WHEN badges render
- THEN lead badges MUST use light blue background (#dbeafe) with dark blue text (#1d4ed8)
- AND request badges MUST use light orange background (#ffedd5) with dark orange text (#c2410c)
- AND badges MUST be 10px font, bold, uppercase, with letter-spacing 0.5px
- AND custom schema types MUST receive auto-generated badge colors from Nextcloud CSS variables
