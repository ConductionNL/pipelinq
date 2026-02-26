# Pipeline Foundation — Delta Spec

## Purpose
Establish pipeline and stage management infrastructure: schema updates, default pipeline creation, and admin settings UI for pipeline CRUD.

**Main spec ref**: [pipeline/spec.md](../../../../specs/pipeline/spec.md)
**Feature tier**: MVP

---

## Requirements

### REQ-PF-001: Pipeline Schema Update

The pipeline schema MUST include `isClosed`, `isWon`, and `color` properties on each stage object to support lead lifecycle tracking and visual customization.

#### Scenario: Stage with closed/won properties

- GIVEN the pipeline schema in `pipelinq_register.json`
- WHEN a pipeline is created with a stage that has `isClosed: true` and `isWon: true`
- THEN the stage MUST store both properties
- AND `isWon: true` MUST only be valid when `isClosed` is also `true`

#### Scenario: Stage with color

- GIVEN a pipeline stage
- WHEN the admin sets `color` to "#10B981"
- THEN the stage MUST store the hex color value
- AND the color MUST be used in the admin settings stage preview

---

### REQ-PF-002: Default Pipeline Creation

The system MUST create default pipelines during app initialization so the app is usable out-of-the-box.

#### Scenario: Default Sales Pipeline

- GIVEN Pipelinq is installed for the first time
- WHEN the initialization runs
- THEN a "Sales Pipeline" MUST be created with stages:
  - New (order 0, probability 10)
  - Contacted (order 1, probability 20)
  - Qualified (order 2, probability 40)
  - Proposal (order 3, probability 60)
  - Negotiation (order 4, probability 80)
  - Won (order 5, probability 100, isClosed: true, isWon: true)
  - Lost (order 6, probability 0, isClosed: true, isWon: false)
- AND the pipeline MUST have entityType "lead" and isDefault true

#### Scenario: Default Service Pipeline

- GIVEN Pipelinq is installed for the first time
- WHEN the initialization runs
- THEN a "Service Requests" pipeline MUST be created with stages:
  - New (order 0)
  - In Progress (order 1)
  - Completed (order 2, isClosed: true, isWon: true)
  - Rejected (order 3, isClosed: true, isWon: false)
  - Converted to Case (order 4, isClosed: true, isWon: false)
- AND the pipeline MUST have entityType "request" and isDefault true

#### Scenario: Idempotent creation

- GIVEN the default Sales Pipeline already exists
- WHEN the initialization runs again
- THEN the system MUST NOT create a duplicate pipeline
- AND existing pipelines MUST NOT be modified

---

### REQ-PF-003: Pipeline CRUD in Admin Settings

The admin settings page MUST support creating, viewing, editing, and deleting pipelines.

#### Scenario: List all pipelines

- GIVEN 2 pipelines exist: "Sales Pipeline" and "Service Requests"
- WHEN the admin opens the Pipelinq settings page
- THEN both pipelines MUST be listed showing:
  - Title (with star icon if isDefault)
  - Entity type (e.g., "Leads", "Requests")
  - Stage count (e.g., "7 stages")
  - Stage preview (e.g., "New → Contacted → ... → Won → Lost")
- AND an "Add pipeline" button MUST be visible

#### Scenario: Create a new pipeline

- GIVEN the admin clicks "Add pipeline"
- WHEN they fill in title "Enterprise Sales" and entity type "lead"
- THEN the pipeline MUST be created via objectStore.saveObject
- AND the pipeline MUST appear in the list

#### Scenario: Edit pipeline properties

- GIVEN an existing pipeline "Sales Pipeline"
- WHEN the admin edits the title to "Sales Pipeline (Q2 2026)"
- THEN the pipeline MUST be updated
- AND the list MUST reflect the new title

#### Scenario: Delete pipeline

- GIVEN a pipeline "Test Pipeline" with 0 leads
- WHEN the admin deletes it
- THEN the pipeline MUST be removed
- AND it MUST disappear from the list

#### Scenario: Set default pipeline

- GIVEN "Sales Pipeline" is the current default for leads
- WHEN the admin sets "Enterprise Sales" as the default
- THEN "Enterprise Sales" MUST become isDefault: true
- AND "Sales Pipeline" MUST become isDefault: false

---

### REQ-PF-004: Stage Management within Pipeline

The admin MUST be able to add, edit, and delete stages within a pipeline.

#### Scenario: Add a stage

- GIVEN a pipeline "Sales Pipeline" with 7 stages
- WHEN the admin adds a stage "Demo" at position 3
- THEN the stage MUST be added to the stages array
- AND subsequent stages MUST have their order incremented

#### Scenario: Edit stage properties

- GIVEN a stage "Qualified" with probability 40
- WHEN the admin changes probability to 50 and sets color "#F59E0B"
- THEN the stage MUST be updated in the pipeline's stages array

#### Scenario: Delete a stage

- GIVEN a stage "Demo" with no leads in it
- WHEN the admin deletes the stage
- THEN the stage MUST be removed from the stages array
- AND remaining stages MUST have contiguous order values

---

### REQ-PF-005: Stage Validation

The system MUST enforce validation rules on stage configuration.

#### Scenario: Pipeline must have at least one non-closed stage

- GIVEN a pipeline with stages "Active" (isClosed: false), "Won" (isClosed: true), "Lost" (isClosed: true)
- WHEN the admin attempts to set "Active" to isClosed: true
- THEN the system MUST reject the change with error: "Pipeline must have at least one non-closed stage"

#### Scenario: isWon requires isClosed

- GIVEN an admin editing a stage
- WHEN they set isWon = true but isClosed = false
- THEN the system MUST reject with error: "A Won stage must also be marked as Closed"

#### Scenario: Stage name is required

- GIVEN the admin adding a new stage
- WHEN they submit without a name
- THEN a validation error MUST appear: "Stage name is required"

#### Scenario: Pipeline title is required

- GIVEN the admin creating a pipeline
- WHEN they submit without a title
- THEN a validation error MUST appear: "Pipeline title is required"
