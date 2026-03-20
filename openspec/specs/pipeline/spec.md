# Pipeline & Kanban Specification

## Purpose

Pipelines provide configurable kanban-style boards where leads and requests flow through ordered stages. A pipeline is comparable to Trello boards or Nextcloud Deck -- each pipeline has columns (stages) and cards (leads/requests). Both entity types can appear on the same pipeline, distinguished by visual badges. Pipelines are the primary visual workflow tool in Pipelinq.

**Standards**: Schema.org (`ItemList`, `DefinedTerm`), Industry patterns (Trello, HubSpot, Nextcloud Deck)
**Primary feature tier**: MVP (with V1 and Enterprise enhancements noted per requirement)

## Data Model

See [ARCHITECTURE.md](../../../docs/ARCHITECTURE.md) for the full Pipeline and Stage entity definitions.

### Pipeline Entity Summary

| Property | Type | Required | Default | Validation |
|----------|------|----------|---------|------------|
| `title` | string | Yes | -- | Non-empty, max 255 chars |
| `description` | string | No | -- | Max 2000 chars |
| `entityTypes` | string[] | Yes | ["lead"] | Each element MUST be one of: "lead", "request" |
| `isDefault` | boolean | No | false | Only one pipeline per entity type SHOULD be default |
| `color` | string (hex) | No | -- | Valid CSS hex color (e.g., #3B82F6) |

### Stage Entity Summary

| Property | Type | Required | Default | Validation |
|----------|------|----------|---------|------------|
| `title` | string | Yes | -- | Non-empty, max 255 chars |
| `description` | string | No | -- | Max 2000 chars |
| `pipeline` | reference | Yes | -- | MUST reference a valid pipeline object |
| `order` | integer | Yes | 0 | MUST be unique within the same pipeline |
| `color` | string (hex) | No | -- | Valid CSS hex color |
| `probability` | integer (0--100) | No | -- | MUST be 0--100 inclusive |
| `isClosed` | boolean | No | false | -- |
| `isWon` | boolean | No | false | MUST only be true if isClosed is also true |

---

## Requirements

### REQ-PIPE-001: Pipeline CRUD [MVP]

The system MUST support creating, reading, updating, and deleting pipelines. Pipelines are managed by admins via the Nextcloud admin settings page (see DESIGN-REFERENCES.md Section 3.7).

#### Scenario 1: Create a pipeline with title and entity types

- GIVEN an admin on the Pipelinq settings page
- WHEN they click "+ Add Pipeline" and enter:
  - title: "Enterprise Sales Pipeline"
  - description: "For large government deals over EUR 50,000"
  - entityTypes: ["lead"]
  - color: "#3B82F6"
- THEN the system MUST create an OpenRegister object with `@type` set to `schema:ItemList`
- AND the pipeline MUST appear in the pipeline list on the settings page
- AND the pipeline MUST be available in the pipeline selector dropdown for leads

#### Scenario 2: Create a mixed entity pipeline

- GIVEN an admin creating a new pipeline
- WHEN they set entityTypes to ["lead", "request"]
- THEN the pipeline MUST accept both leads and requests as cards
- AND the pipeline selector MUST appear for both lead and request creation forms
- AND the admin settings MUST display "Entities: Leads, Requests" for this pipeline

#### Scenario 3: Edit pipeline title and description

- GIVEN an existing pipeline "Sales Pipeline"
- WHEN the admin changes the title to "Sales Pipeline (Q2 2026)" and updates the description
- THEN the system MUST update the OpenRegister object
- AND the new title MUST be reflected in all views: pipeline selector, kanban header, admin settings
- AND the audit trail MUST record the change

#### Scenario 4: Edit pipeline color

- GIVEN an existing pipeline with no color set
- WHEN the admin sets color to "#10B981" (green)
- THEN the pipeline MUST store the color value
- AND the pipeline SHOULD use the color in the kanban board header and pipeline selector

#### Scenario 5: Delete a pipeline with no items

- GIVEN a pipeline "Archived Pipeline" with 0 leads and 0 requests
- WHEN the admin clicks delete and confirms
- THEN the system MUST remove the pipeline object from OpenRegister
- AND all stage objects belonging to this pipeline MUST also be deleted
- AND the pipeline MUST disappear from the admin settings and all selectors

#### Scenario 6: Delete a pipeline with active items

- GIVEN a pipeline "Sales Pipeline" with 8 leads and 2 requests assigned to it
- WHEN the admin attempts to delete the pipeline
- THEN the system MUST display a warning: "This pipeline has 10 active items (8 leads, 2 requests). Deleting it will remove pipeline and stage references from these items."
- AND upon confirmation, the system MUST delete the pipeline and its stages
- AND all 10 leads/requests MUST have their `pipeline` and `stage` references set to null
- AND the affected items MUST still exist and be accessible via the lead/request list views

---

### REQ-PIPE-002: Pipeline Entity Types [MVP]

Each pipeline MUST declare which entity types it supports. This controls which entities can be placed on the pipeline and which entities appear on the kanban board.

#### Scenario 7: Lead-only pipeline

- GIVEN a pipeline with entityTypes: ["lead"]
- WHEN a user creates a lead and selects this pipeline
- THEN the lead MUST be assignable to this pipeline
- AND when a user attempts to place a request on this pipeline, the system MUST reject it with "This pipeline does not support requests"

#### Scenario 8: Request-only pipeline

- GIVEN a pipeline with entityTypes: ["request"]
- WHEN a user creates a request and selects this pipeline
- THEN the request MUST be assignable to this pipeline
- AND leads MUST NOT be assignable to this pipeline

#### Scenario 9: Mixed pipeline

- GIVEN a pipeline with entityTypes: ["lead", "request"]
- WHEN the kanban board is rendered
- THEN both leads and requests MUST appear as cards in their respective stage columns
- AND the pipeline view MUST support a "Show" filter with options: "All", "Leads only", "Requests only"

---

### REQ-PIPE-003: Default Pipelines [MVP]

The system MUST create default pipelines during app initialization (repair step) so the app is usable out-of-the-box without configuration.

#### Scenario 10: Default Sales Pipeline creation

- GIVEN Pipelinq is installed for the first time (or the repair step runs)
- WHEN the initialization process executes
- THEN a "Sales Pipeline" MUST be created with the following stages in order:

| Order | Title | Probability | isClosed | isWon |
|-------|-------|-------------|----------|-------|
| 0 | New | 10 | false | false |
| 1 | Contacted | 20 | false | false |
| 2 | Qualified | 40 | false | false |
| 3 | Proposal | 60 | false | false |
| 4 | Negotiation | 80 | false | false |
| 5 | Won | 100 | true | true |
| 6 | Lost | 0 | true | false |

- AND the pipeline MUST have entityTypes: ["lead"]
- AND the pipeline MUST be set as the default pipeline (isDefault: true)
- AND the pipeline MUST NOT be recreated if it already exists on subsequent repair runs

#### Scenario 11: Default Service Pipeline creation

- GIVEN Pipelinq is installed for the first time
- WHEN the initialization process executes
- THEN a "Service Requests" pipeline MUST be created with the following stages:

| Order | Title | Probability | isClosed | isWon |
|-------|-------|-------------|----------|-------|
| 0 | New | -- | false | false |
| 1 | In Progress | -- | false | false |
| 2 | Completed | -- | true | true |
| 3 | Rejected | -- | true | false |
| 4 | Converted to Case | -- | true | false |

- AND the pipeline MUST have entityTypes: ["request"]
- AND the pipeline SHOULD be set as the default pipeline for requests

#### Scenario 12: Default pipelines are idempotent

- GIVEN the default Sales Pipeline already exists
- WHEN the repair step runs again (e.g., after an app update)
- THEN the system MUST NOT create a duplicate pipeline
- AND existing stages MUST NOT be modified or reset
- AND any admin customizations (renamed stages, reordered stages) MUST be preserved

---

### REQ-PIPE-004: Stage CRUD [MVP]

The system MUST support creating, reading, updating, reordering, and deleting stages within a pipeline. Stages are managed via the admin settings page as sub-items of their parent pipeline.

#### Scenario 13: Create a new stage

- GIVEN an existing pipeline "Sales Pipeline" with 7 stages (orders 0--6)
- WHEN the admin adds a stage with title "Demo" at order 3
- THEN the system MUST create an OpenRegister object with `@type` set to `schema:DefinedTerm`
- AND the stage MUST reference the parent pipeline
- AND existing stages with order >= 3 MUST have their order incremented by 1 (Proposal becomes 4, Negotiation becomes 5, etc.)
- AND the new stage sequence MUST be: New (0), Contacted (1), Qualified (2), Demo (3), Proposal (4), Negotiation (5), Won (6), Lost (7)

#### Scenario 14: Edit stage properties

- GIVEN a stage "Qualified" with probability 40, color null
- WHEN the admin updates probability to 50 and sets color to "#F59E0B"
- THEN the system MUST update the stage object
- AND the kanban column header MUST reflect the new color
- AND leads entering this stage SHOULD have their probability set to 50 (instead of the previous 40)

#### Scenario 15: Reorder stages via drag-and-drop

- GIVEN stages: New (0), Contacted (1), Qualified (2), Proposal (3)
- WHEN the admin drags "Proposal" between "Contacted" and "Qualified"
- THEN the system MUST update order values: New (0), Contacted (1), Proposal (2), Qualified (3)
- AND the kanban board MUST immediately reflect the new column order

#### Scenario 16: Mark stage as closed and won

- GIVEN a stage "Deal Closed"
- WHEN the admin sets isClosed = true and isWon = true
- THEN leads entering this stage MUST be treated as won/completed
- AND the kanban SHOULD collapse or visually distinguish this column (see REQ-PIPE-007 Scenario 34)

#### Scenario 17: Mark stage as closed but not won

- GIVEN a stage "Disqualified"
- WHEN the admin sets isClosed = true and isWon = false
- THEN leads entering this stage MUST be treated as lost/rejected
- AND probability for this stage SHOULD default to 0

#### Scenario 18: Delete a stage with no items

- GIVEN a stage "Demo" with 0 leads and 0 requests in it
- WHEN the admin deletes the stage
- THEN the system MUST remove the stage object from OpenRegister
- AND remaining stages MUST have their order values recomputed to be contiguous (no gaps)

#### Scenario 19: Delete a stage with items

- GIVEN a stage "Qualified" with 5 leads assigned to it
- WHEN the admin attempts to delete the stage
- THEN the system MUST display a warning: "This stage has 5 items. They will be moved to the previous stage (Contacted)."
- AND upon confirmation, all 5 leads MUST have their `stage` reference updated to the previous stage
- AND if the deleted stage is the first stage, items MUST be moved to the new first stage
- AND the remaining stages MUST have their order values recomputed

---

### REQ-PIPE-005: Stage Validation [MVP]

The system MUST enforce validation rules on stage configuration to maintain pipeline integrity.

#### Scenario 20: Stage order must be unique within pipeline

- GIVEN a pipeline with stages at orders 0, 1, 2, 3
- WHEN the admin attempts to create a new stage at order 2 (duplicate)
- THEN the system MUST either reject the request with "Order must be unique within the pipeline" OR automatically shift existing stages to make room (implementation MAY choose either approach, but the result MUST have unique orders)

#### Scenario 21: Pipeline must have at least one non-closed stage

- GIVEN a pipeline with 3 stages: "Active" (isClosed: false), "Won" (isClosed: true), "Lost" (isClosed: true)
- WHEN the admin attempts to set "Active" to isClosed: true (making all stages closed)
- THEN the system MUST reject the change with validation error: "Pipeline must have at least one non-closed stage"
- AND the "Active" stage MUST remain with isClosed: false

#### Scenario 22: isWon requires isClosed

- GIVEN an admin editing a stage
- WHEN they set isWon = true but isClosed = false
- THEN the system MUST reject the request with validation error: "A Won stage must also be marked as Closed"
- AND the stage MUST NOT be updated

#### Scenario 23: Stage title is required

- GIVEN an admin creating a new stage
- WHEN they submit without a title
- THEN the system MUST reject the request with validation error: "Stage title is required"

#### Scenario 24: Stage probability range

- GIVEN an admin editing a stage
- WHEN they set probability to 120
- THEN the system MUST reject the request with validation error: "Probability must be between 0 and 100"

---

### REQ-PIPE-006: Kanban Board View [MVP]

The system MUST provide a kanban board view for each pipeline showing stages as columns and leads/requests as cards. Request cards MUST be visually distinct from lead cards.

#### Scenario: Kanban card display - request card
- **WHEN** a request "IT Support Request #42" with priority "urgent" and assigned to "jan" is rendered on the kanban board
- **THEN** the card MUST display an entity type badge [REQ] in a different color from leads (e.g., orange)
- **THEN** the card MUST display the title, priority badge, and assignee avatar
- **THEN** the card MUST NOT display a value line (requests have no value field)
- **THEN** the visual distinction between [LEAD] and [REQ] badges MUST be clear without relying on color alone (WCAG AA)

#### Scenario: Mixed entity kanban
- **WHEN** user views a pipeline with entityTypes ["lead", "request"] and a stage containing 3 leads and 2 requests
- **THEN** the column MUST show all 5 cards with entity type badges
- **THEN** the column header MUST show "5 items" and total value of only leads
- **THEN** a "Show" filter dropdown MUST allow toggling: "All", "Leads only", "Requests only"

#### Scenario: Drag and drop request between stages
- **WHEN** user drags a request card from "New" to "In Progress" stage
- **THEN** the system MUST update the request's `stage` reference
- **THEN** the request's `status` SHOULD be synchronized to match the stage mapping

---

### REQ-PIPE-007: Pipeline View Toggle [MVP]

The system MUST support toggling between kanban board view and list table view for each pipeline. Both views show the same data, just in different formats.

#### Scenario 32: Toggle from kanban to list view

- GIVEN the user viewing the Sales Pipeline in kanban mode
- WHEN the user clicks the "List" toggle button (see DESIGN-REFERENCES.md Section 3.3)
- THEN the view MUST switch to a table with columns: priority indicator, type badge, title, stage, value, due date, assigned user
- AND the current filter and pipeline selection MUST be preserved
- AND the footer MUST show: "Showing N items - Total value: EUR X - Weighted: EUR Y"
- AND weighted value MUST be calculated as SUM(value * probability / 100) across all displayed leads

#### Scenario 33: Toggle from list to kanban view

- GIVEN the user viewing the Sales Pipeline in list mode
- WHEN the user clicks the "Kanban" toggle button
- THEN the view MUST switch back to the kanban board
- AND all filter states MUST be preserved
- AND the user's preferred view (last selected) SHOULD be remembered across navigation

#### Scenario 34: Collapsed closed stages

- GIVEN a Sales Pipeline with Won (isClosed: true, isWon: true) and Lost (isClosed: true, isWon: false)
- WHEN the kanban board is rendered
- THEN Won and Lost MUST be displayed as collapsed columns at the bottom of the board
- AND collapsed columns MUST show: stage title + item count (e.g., "WON 3" / "LOST 2")
- AND clicking a collapsed column SHOULD expand it to show the cards
- AND collapsed columns MUST NOT take up full column width to save horizontal space

#### Scenario 60: Toggle between views

- WHEN the user clicks the view toggle
- THEN the pipeline MUST switch between kanban (columns) and list (table) view
- AND the selected view MUST persist during the session

#### Scenario 61: List view content

- WHEN the user is in list view
- THEN items MUST be displayed in a table with columns: title, entity type badge, stage, assignee, value (leads), due date, priority
- AND rows MUST be clickable to navigate to detail views
- AND the table MUST be sortable by clicking column headers

#### Scenario 62: List view preserves filters

- WHEN the user switches from kanban to list view
- THEN the same pipeline selection and entity type filter MUST be preserved

---

### REQ-PIPE-008: Stage Column Headers [MVP]

Each stage column on the kanban board MUST display aggregate information in its header.

#### Scenario 35: Column header with item count and value

- GIVEN the "Qualified" stage with 4 leads (values: EUR 20,000, EUR 18,000, EUR 15,000, EUR 5,000) and 1 request
- WHEN the kanban board is rendered
- THEN the "Qualified" column header MUST display:
  - Stage title: "QUALIFIED"
  - Item count: "5 items"
  - Total lead value: "EUR 58,000" (sum of lead values only; requests have no value)

#### Scenario 36: Column header with zero items

- GIVEN the "Negotiation" stage with 0 leads and 0 requests
- WHEN the kanban board is rendered
- THEN the column header MUST display: "NEGOTIATION", "0 items", "EUR 0"
- AND the column MUST still be rendered (empty columns are valid and necessary for drag targets)

---

### REQ-PIPE-009: Add Entity from Stage Column [MVP]

The system MUST allow creating new entities directly from within a stage column on the kanban board. On mixed pipelines, the quick-create form MUST include an entity type selector.

#### Scenario: Add request from stage column on mixed pipeline
- **WHEN** user clicks "+ Add" on a stage column of a mixed pipeline and selects "Request"
- **THEN** the quick-create form MUST show request-appropriate fields (title, priority)
- **THEN** the created request MUST appear on the correct stage column with a [REQ] badge

---

### REQ-PIPE-010: Pipeline Selection on Entity [MVP]

Leads and requests MUST be assignable to a pipeline and stage, either during creation or via editing.

#### Scenario 39: Assign lead to pipeline on creation

- GIVEN a user creating a new lead and a default Sales Pipeline exists
- WHEN the lead is created without explicitly selecting a pipeline
- THEN the lead MUST be automatically assigned to the default pipeline
- AND the lead MUST be placed on the first non-closed stage (typically "New", order 0)

#### Scenario 40: Assign lead to specific pipeline and stage

- GIVEN two pipelines: "Sales Pipeline" and "Enterprise Pipeline"
- WHEN the user creates a lead and selects "Enterprise Pipeline" with stage "Qualified"
- THEN the lead MUST store references to the Enterprise Pipeline and the Qualified stage
- AND the lead MUST appear on the Enterprise Pipeline kanban in the Qualified column

#### Scenario 41: Move entity between pipelines

- GIVEN a lead "TechCorp deal" on the "Sales Pipeline" in stage "Proposal"
- WHEN the user changes the pipeline to "Enterprise Pipeline" via the lead detail view
- THEN the lead's `pipeline` reference MUST be updated to "Enterprise Pipeline"
- AND the lead's `stage` MUST be set to the first non-closed stage of the Enterprise Pipeline
- AND the lead MUST disappear from the Sales Pipeline kanban
- AND the lead MUST appear on the Enterprise Pipeline kanban
- AND the audit trail MUST record: "Pipeline changed from Sales Pipeline to Enterprise Pipeline"

#### Scenario 42: Remove entity from pipeline

- GIVEN a lead assigned to the "Sales Pipeline"
- WHEN the user clears the pipeline selection (sets to null)
- THEN the lead's `pipeline` and `stage` references MUST be set to null
- AND the lead MUST disappear from all kanban boards
- AND the lead MUST still be accessible via the lead list view

---

### REQ-PIPE-011: Stage Probability Mapping [V1]

When a lead is moved to a stage that has a probability value set, the system MUST automatically update the lead's probability to match the stage probability.

#### Scenario 43: Auto-populate probability on stage change

- GIVEN a lead "Gemeente ABC deal" with probability 20 in stage "Contacted" (probability: 20)
- WHEN the lead is moved to stage "Qualified" (probability: 40)
- THEN the system MUST update the lead's probability from 20 to 40
- AND the audit trail MUST record: "Probability changed from 20% to 40% (auto-set from stage Qualified)"

#### Scenario 44: Stage without probability does not override

- GIVEN a stage "Custom Review" with probability: null
- WHEN a lead with probability 60 is moved to this stage
- THEN the lead's probability MUST remain at 60 (not overwritten)

#### Scenario 45: Manual probability override after stage change

- GIVEN a lead that was auto-set to probability 40 by moving to "Qualified"
- WHEN the user manually changes the probability to 55
- THEN the system MUST accept the override
- AND the lead MUST store probability 55
- AND moving to a new stage in the future SHOULD again auto-set the probability from the stage value

---

### REQ-PIPE-012: Pipeline Analytics [V1]

The system MUST provide analytics for each pipeline to help managers understand conversion rates and bottlenecks.

#### Scenario 46: Conversion rate between stages

- GIVEN a Sales Pipeline with historical data: 100 leads entered "New", 80 moved to "Contacted", 50 to "Qualified", 30 to "Proposal", 20 to "Negotiation", 12 to "Won", 8 to "Lost"
- WHEN the admin views pipeline analytics
- THEN the system MUST display conversion rates between consecutive stages:
  - New -> Contacted: 80%
  - Contacted -> Qualified: 62.5%
  - Qualified -> Proposal: 60%
  - Proposal -> Negotiation: 66.7%
  - Negotiation -> Won: 60%
- AND an overall conversion rate MUST be shown: "Win rate: 12%"

#### Scenario 47: Average time per stage

- GIVEN historical stage-change data for completed leads
- WHEN the admin views pipeline analytics
- THEN the system MUST display average days spent in each stage:
  - New: 3.2 days
  - Contacted: 5.8 days
  - Qualified: 7.1 days
  - (etc.)
- AND stages with unusually long average times SHOULD be highlighted as potential bottlenecks

---

### REQ-PIPE-013: Pipeline Funnel Visualization [V1]

The system MUST display a funnel chart showing the distribution of leads/requests across pipeline stages.

#### Scenario 48: Render pipeline funnel

- GIVEN a Sales Pipeline with: New (5), Contacted (4), Qualified (3), Proposal (2), Negotiation (1)
- WHEN the dashboard funnel is rendered (see DESIGN-REFERENCES.md Section 3.1)
- THEN the system MUST display a horizontal bar chart where:
  - Each row represents a stage
  - Bar width is proportional to item count
  - The count is displayed next to each bar
- AND the overall conversion percentage MUST be shown below the funnel

---

### REQ-PIPE-014: Stage Revenue Summary [V1]

The system MUST display the total monetary value of leads in each stage to provide at-a-glance pipeline valuation.

#### Scenario 49: Revenue per stage column

- GIVEN the kanban board showing the Sales Pipeline
- AND the "Qualified" stage has 3 leads with values EUR 20,000, EUR 18,000, EUR 15,000
- WHEN the kanban is rendered
- THEN the "Qualified" column header MUST display "EUR 53,000" as the total stage value
- AND a footer or summary bar SHOULD display the total pipeline value (sum across all open stages)
- AND a weighted pipeline value SHOULD be displayed: SUM(value * probability / 100)

#### Scenario 50: Revenue excludes closed stages

- GIVEN leads in Won (EUR 50,000) and Lost (EUR 30,000) stages
- WHEN the pipeline total value is calculated
- THEN the total SHOULD only include leads in non-closed stages
- AND Won/Lost values MAY be shown separately (e.g., "Won: EUR 50,000 | Lost: EUR 30,000")

---

### REQ-PIPE-015: Error Scenarios [MVP]

The system MUST handle error conditions gracefully with meaningful feedback.

#### Scenario 51: Create pipeline without title

- GIVEN an admin on the settings page
- WHEN they attempt to create a pipeline without entering a title
- THEN the system MUST reject the request with validation error: "Pipeline title is required"
- AND the form MUST highlight the title field as invalid

#### Scenario 52: Create pipeline without stages

- GIVEN an admin creates a pipeline "New Pipeline" with no stages
- WHEN a user attempts to assign a lead to this pipeline
- THEN the system MUST reject the assignment with error: "Pipeline has no stages. Please add at least one stage in admin settings."
- AND the pipeline SHOULD display a warning in admin settings: "No stages configured"

#### Scenario 53: Drag to same stage (duplicate event prevention)

- GIVEN a lead card in the "Qualified" column
- WHEN the user drops the card back into the same position in "Qualified"
- THEN the system MUST NOT trigger a stage change event
- AND no API call MUST be made
- AND no audit trail entry MUST be created
- (Note: this is the same as Scenario 31, referenced here for completeness as an error prevention scenario)

#### Scenario 54: Admin deletes last non-closed stage

- GIVEN a pipeline with stages "Active" (isClosed: false) and "Done" (isClosed: true)
- WHEN the admin attempts to delete "Active"
- THEN the system MUST reject the deletion with error: "Cannot delete the last non-closed stage. A pipeline must have at least one non-closed stage."
- AND the stage MUST NOT be removed

#### Scenario 55: Concurrent stage reorder conflict

- GIVEN two admins editing the same pipeline's stages simultaneously
- WHEN admin A reorders stages while admin B deletes a stage
- THEN the system SHOULD detect the conflict
- AND display: "Pipeline stages were modified by another user. Please refresh to see the latest configuration."

---

### REQ-PIPE-016: Pipeline List on Admin Settings [MVP]

The admin settings page MUST display all pipelines with their configuration summary. See DESIGN-REFERENCES.md Section 3.7 for the wireframe.

#### Scenario 56: Display pipeline list in admin settings

- GIVEN two pipelines: "Sales Pipeline" (7 stages, entities: leads, isDefault: true) and "Service Pipeline" (5 stages, entities: requests)
- WHEN the admin opens the Pipelinq admin settings
- THEN the system MUST display both pipelines as cards/rows showing:
  - Pipeline title (with a star icon if isDefault)
  - Stage count (e.g., "7 stages")
  - Entity types (e.g., "Leads" or "Leads, Requests")
  - Stage sequence preview (e.g., "New -> Contacted -> Qualified -> ... -> Won -> Lost")
  - Edit button
- AND the default pipeline MUST be visually distinguished (e.g., star icon, bold title)

#### Scenario 57: Set default pipeline

- GIVEN two pipelines where "Sales Pipeline" is currently the default for leads
- WHEN the admin sets "Enterprise Pipeline" as the default for leads
- THEN "Enterprise Pipeline" MUST become isDefault: true
- AND "Sales Pipeline" MUST become isDefault: false
- AND new leads created without explicit pipeline selection MUST be placed on "Enterprise Pipeline"

---

### REQ-PIPE-017: Pipeline Selector Dropdown [MVP]

The pipeline view MUST include a dropdown to switch between pipelines.

#### Scenario 58: Switch between pipelines

- GIVEN the user is viewing "Sales Pipeline" kanban with 12 leads
- AND a "Service Pipeline" exists with 8 requests
- WHEN the user opens the pipeline dropdown and selects "Service Pipeline"
- THEN the kanban board MUST reload showing the Service Pipeline's stages and request cards
- AND the column headers, counts, and values MUST reflect the Service Pipeline's data

#### Scenario 59: Pipeline dropdown shows entity type labels

- GIVEN 3 pipelines: "Sales" (leads), "Service" (requests), "Mixed" (leads + requests)
- WHEN the user opens the pipeline dropdown
- THEN each option MUST display the pipeline title and its entity types (e.g., "Sales (Leads)", "Service (Requests)", "Mixed (Leads, Requests)")

---

### REQ-PIPE-018: Pipeline Card Quick Actions [MVP]

Pipeline cards MUST support quick actions for moving between stages and assigning users without opening the detail view.

#### Scenario 63: Quick stage change

- WHEN the user clicks the stage action on a card
- THEN a dropdown MUST show available stages from the current pipeline
- AND selecting a stage MUST move the item to that stage
- AND the board MUST refresh to reflect the change

#### Scenario 64: Quick assign

- WHEN the user clicks the assign action on a card
- THEN a dropdown MUST show available users
- AND selecting a user MUST assign the item to that user
- AND the card MUST update to show the new assignee

#### Scenario 65: Actions don't navigate

- WHEN the user interacts with quick actions
- THEN the card MUST NOT navigate to the detail view

---

## ADDED Requirements

### Requirement: REQ-PIPE-019: Multiple Pipelines per Organization [V1]

Organizations MUST be able to maintain multiple active pipelines simultaneously, each targeting different workflows or teams. This enables separate sales processes (e.g., government deals vs. commercial, inbound vs. outbound) and prevents forcing all leads through a single funnel. Inspired by EspoCRM's multi-pipeline opportunities and Krayin's pipeline-per-team model.

#### Scenario: Create team-specific pipelines

- GIVEN an organization with two sales teams: "Government" and "Commercial"
- WHEN an admin creates two pipelines:
  - "Government Sales" with stages: New, Assessment, Tender, Award, Won, Lost
  - "Commercial Sales" with stages: New, Contacted, Demo, Proposal, Won, Lost
- THEN both pipelines MUST coexist and be selectable from the pipeline dropdown
- AND each pipeline MUST independently track its own leads
- AND the dashboard KPI "Pipeline Value" MUST aggregate values across all active pipelines

#### Scenario: Pipeline-specific stage sequences

- GIVEN a "Government Sales" pipeline with 6 stages including "Tender" and "Award"
- AND a "Commercial Sales" pipeline with 6 stages including "Demo" and "Proposal"
- WHEN a user views each pipeline's kanban board
- THEN each board MUST show only its own stage columns
- AND stage names, probabilities, and colors MUST be independently configurable per pipeline

#### Scenario: Cross-pipeline lead overview

- GIVEN 15 leads on "Government Sales" and 30 leads on "Commercial Sales"
- WHEN a manager navigates to the lead list view (not the kanban)
- THEN all 45 leads MUST be visible in the list regardless of pipeline
- AND the "Pipeline" column MUST show which pipeline each lead belongs to
- AND filtering by pipeline MUST be available in the list view

---

### Requirement: REQ-PIPE-020: Pipeline Template Creation [Enterprise]

The system SHOULD allow admins to save an existing pipeline configuration as a reusable template. Templates accelerate onboarding by providing pre-built pipeline configurations that match common workflows (sales, service, hiring, procurement). Krayin ships with a default pipeline template; EspoCRM uses installable extension packs.

#### Scenario: Save pipeline as template

- GIVEN an admin viewing the "Government Sales" pipeline with 6 custom stages, probabilities, and colors
- WHEN the admin clicks "Save as template" and enters a template name "Government Tender Process"
- THEN the system MUST store a template object containing:
  - Template title and description
  - Stage definitions (names, order, probabilities, isClosed, isWon, colors)
  - Entity type configuration
- AND the template MUST appear in a "Templates" section on the admin settings page

#### Scenario: Create pipeline from template

- GIVEN a template "Government Tender Process" with 6 stages
- WHEN an admin clicks "Create from template" and selects this template
- THEN the system MUST create a new pipeline pre-populated with all template stages
- AND the admin MUST be able to modify the pipeline title and customize stages before saving
- AND the new pipeline MUST be independent of the template (changes to one do not affect the other)

#### Scenario: Built-in templates available on fresh install

- GIVEN a fresh Pipelinq installation
- WHEN the admin navigates to pipeline settings and clicks "Create from template"
- THEN at least two built-in templates MUST be available:
  - "Sales Pipeline" (7 stages: New through Won/Lost with probabilities)
  - "Service Request Pipeline" (5 stages: New through Completed/Rejected/Converted)
- AND built-in templates MUST NOT be deletable

---

### Requirement: REQ-PIPE-021: Stage Automation on Transition [Enterprise]

The system SHOULD support configurable automation actions triggered when a lead or request moves to a specific stage. This reduces manual work and ensures consistency in follow-up actions. EspoCRM implements this via its BPM engine; Krayin uses a workflow automation system with event-based triggers on lead stage changes.

#### Scenario: Auto-assign on stage transition

- GIVEN a pipeline stage "Qualified" with an automation rule: "Auto-assign to team lead jan@example.nl"
- WHEN a lead is moved from "Contacted" to "Qualified" (via drag-and-drop or quick action)
- THEN the system MUST automatically set the lead's assignee to "jan@example.nl"
- AND the audit trail MUST record: "Auto-assigned to jan@example.nl (triggered by stage change to Qualified)"
- AND a Nextcloud notification MUST be sent to jan@example.nl: "Lead 'TechCorp deal' has been assigned to you"

#### Scenario: Auto-notify on stage transition

- GIVEN a pipeline stage "Won" with an automation rule: "Notify manager piet@example.nl"
- WHEN a lead is moved to "Won"
- THEN the system MUST send a Nextcloud notification to piet@example.nl: "Lead 'Gemeente ABC deal' has been won (EUR 50,000)"
- AND the notification MUST include a link to the lead detail view

#### Scenario: Auto-update field on stage transition

- GIVEN a pipeline stage "Lost" with automation rules:
  - "Set probability to 0"
  - "Set lostReason field to required"
- WHEN a lead is moved to "Lost"
- THEN the system MUST automatically set the lead's probability to 0
- AND the system MUST prompt the user to fill in a "Lost reason" before the transition completes
- AND if the user cancels the reason prompt, the lead MUST remain in its previous stage

#### Scenario: Configure stage automation via admin settings

- GIVEN an admin editing the "Qualified" stage in pipeline settings
- WHEN the admin opens the "Automation" section of the stage editor
- THEN the admin MUST be able to configure zero or more actions from:
  - Auto-assign to a specific user
  - Send notification to a specific user or group
  - Set a field value (e.g., probability, priority)
  - Require a field to be filled (e.g., lostReason)
- AND each action MUST show a preview summary (e.g., "Assign to jan@example.nl on entry")

---

### Requirement: REQ-PIPE-022: Pipeline Filtering and Search [MVP]

The pipeline kanban and list views MUST support filtering and searching items to help users focus on specific subsets of leads or requests. This is a fundamental CRM capability present in all competitors (EspoCRM, Krayin, Twenty, BottleCRM).

#### Scenario: Search by title within pipeline

- GIVEN a pipeline with 50 leads across all stages
- WHEN the user types "Gemeente" in the pipeline search bar
- THEN the kanban MUST show only leads whose title contains "Gemeente" (case-insensitive)
- AND stage columns MUST only display matching cards (empty columns remain visible)
- AND column headers MUST update counts and values to reflect filtered results only
- AND the list view MUST filter the same way if active

#### Scenario: Filter by assignee

- GIVEN a pipeline with leads assigned to users "jan", "piet", and "klaas"
- WHEN the user selects assignee filter "jan"
- THEN only leads assigned to "jan" MUST be displayed on the kanban board
- AND the filter MUST persist when switching between kanban and list views

#### Scenario: Filter by priority

- GIVEN a pipeline with leads at priorities: urgent (2), high (5), normal (30), low (8)
- WHEN the user selects priority filter "urgent" and "high"
- THEN only the 7 matching leads MUST be displayed
- AND column counts and values MUST reflect filtered results

#### Scenario: Filter by due date range

- GIVEN a pipeline with leads having various expected close dates
- WHEN the user selects the date filter "Overdue" (expectedCloseDate < today)
- THEN only overdue leads MUST be displayed
- AND the filter MUST also support: "This week", "This month", "This quarter", "Custom range"

#### Scenario: Combined filters

- GIVEN a pipeline with 100 leads
- WHEN the user applies multiple filters: assignee = "jan", priority = "high", entity type = "Leads only"
- THEN the system MUST apply all filters with AND logic
- AND the result count MUST be displayed: "Showing 3 of 100 items"
- AND clearing all filters MUST restore the full pipeline view

---

### Requirement: REQ-PIPE-023: Pipeline Access Control [V1]

The system SHOULD enforce access control on pipelines to ensure users only see and interact with pipelines relevant to their role. Access control is managed via OpenRegister's RBAC system. EspoCRM uses team-based access with role-level restrictions; Krayin has a "bouncer" system with all/group/individual permission levels.

#### Scenario: Admin-only pipeline configuration

- GIVEN a regular user (non-admin) logged into Pipelinq
- WHEN the user navigates to the app
- THEN the pipeline management section in admin settings MUST NOT be accessible
- AND the user MUST NOT be able to create, edit, or delete pipelines or stages
- AND the user MUST still be able to view and interact with pipeline kanban boards

#### Scenario: Pipeline visibility by role

- GIVEN a pipeline "Executive Sales" with access restricted to the "Sales Managers" group
- AND a user "jan" who is a member of "Sales Managers"
- AND a user "piet" who is NOT a member of "Sales Managers"
- WHEN "jan" opens the pipeline dropdown
- THEN "Executive Sales" MUST appear in the dropdown
- AND when "piet" opens the pipeline dropdown
- THEN "Executive Sales" MUST NOT appear in piet's dropdown

#### Scenario: Pipeline items respect entity-level permissions

- GIVEN a pipeline showing leads from multiple users
- AND OpenRegister RBAC restricts user "jan" to only see leads assigned to himself
- WHEN "jan" views the pipeline kanban board
- THEN only leads assigned to "jan" MUST be visible as cards
- AND column headers MUST show counts and values based only on jan's visible leads

---

### Requirement: REQ-PIPE-024: Pipeline Dashboard Widgets [V1]

The Pipelinq dashboard MUST include pipeline-specific widgets that provide at-a-glance visibility into pipeline health and performance. These widgets complement the full kanban view by surfacing key metrics on the landing page.

#### Scenario: Pipeline value KPI widget

- GIVEN a dashboard with the "Pipeline Value" widget configured
- AND 3 active pipelines with open leads totaling EUR 150,000 in value
- WHEN the dashboard loads
- THEN the widget MUST display "EUR 150,000" as the aggregate pipeline value
- AND clicking the widget MUST navigate to the pipeline view

#### Scenario: Pipeline funnel widget on dashboard

- GIVEN a dashboard with the "Pipeline Funnel" widget
- AND the default Sales Pipeline with leads distributed across stages
- WHEN the dashboard loads
- THEN the widget MUST render a horizontal bar chart showing lead counts per stage
- AND the chart MUST use stage colors if configured
- AND stages MUST be ordered from first (top) to last (bottom)
- AND closed stages (Won/Lost) SHOULD be shown separately at the bottom of the funnel

#### Scenario: Deals by stage widget

- GIVEN a dashboard with the "Deals by Stage" widget
- AND open leads: New (5, EUR 25k), Qualified (3, EUR 40k), Proposal (2, EUR 30k)
- WHEN the dashboard loads
- THEN the widget MUST display bars for each stage with count and value
- AND the bar width MUST be proportional to the count (not value)

#### Scenario: Overdue items widget

- GIVEN 3 leads past their expected close date and 2 requests older than 30 days
- WHEN the dashboard loads
- THEN the "Overdue" widget MUST display "5" with error styling
- AND clicking the widget MUST navigate to a filtered view showing only overdue items

---

### Requirement: REQ-PIPE-025: Stage SLA and Deadline Tracking [Enterprise]

The system SHOULD support configuring maximum time limits (SLAs) per stage so that leads and requests that exceed the expected duration are flagged for attention. SLA tracking is a common feature in government CRM contexts where response time commitments are contractual. EspoCRM offers SLA tracking in its Cases module; Krayin does not have built-in SLA.

#### Scenario: Configure stage SLA

- GIVEN an admin editing the "New" stage of the Sales Pipeline
- WHEN the admin sets the SLA to "3 business days"
- THEN the system MUST store the SLA value on the stage object
- AND the admin MUST be able to choose between "calendar days" and "business days"

#### Scenario: SLA breach warning on kanban card

- GIVEN a lead "Late Deal" that has been in the "New" stage for 5 business days
- AND the "New" stage has an SLA of 3 business days
- WHEN the kanban board is rendered
- THEN the card for "Late Deal" MUST display a visual SLA breach indicator (e.g., red clock icon)
- AND the card MUST show "2d overdue" relative to the SLA deadline
- AND the SLA indicator MUST be distinct from the existing aging badge (aging = total age, SLA = stage-specific)

#### Scenario: SLA breach notification

- GIVEN a lead that exceeds the stage SLA threshold
- WHEN the SLA breach is detected (via periodic check or on board load)
- THEN the system MUST send a Nextcloud notification to the lead's assignee: "Lead 'Late Deal' has exceeded the SLA for stage 'New' (3 business days)"
- AND if the lead has no assignee, the notification MUST go to the pipeline's default admin

#### Scenario: SLA metrics in pipeline analytics

- GIVEN pipeline analytics for a Sales Pipeline with SLA-configured stages
- WHEN the admin views the analytics panel
- THEN the system MUST display SLA compliance rates per stage:
  - New: 85% within SLA (17 of 20 leads)
  - Contacted: 92% within SLA
  - Qualified: 78% within SLA (highlighted as below target)
- AND stages below 80% compliance SHOULD be highlighted as needing attention

---

### Requirement: REQ-PIPE-026: Pipeline Reporting [V1]

The system SHOULD provide exportable pipeline reports that summarize pipeline performance over a configurable time period. Reports complement real-time analytics by providing historical snapshots for management review and tender compliance.

#### Scenario: Generate pipeline summary report

- GIVEN a Sales Pipeline with historical data over the past quarter
- WHEN the admin selects "Pipeline Report" and sets date range to "Q1 2026" (Jan 1 - Mar 31)
- THEN the system MUST generate a report containing:
  - Total leads entered: count of leads that entered the pipeline during the period
  - Total leads won: count of leads that reached a Won stage
  - Total leads lost: count of leads that reached a Lost stage
  - Win rate: won / (won + lost) as a percentage
  - Total value won: sum of values of won leads
  - Average deal time: mean days from pipeline entry to Won stage
  - Stage-by-stage conversion rates

#### Scenario: Export report as CSV

- GIVEN a generated pipeline summary report
- WHEN the admin clicks "Export CSV"
- THEN the system MUST download a CSV file with columns: Lead Title, Client, Value, Stage, Days in Pipeline, Outcome (Won/Lost/Open), Close Date
- AND all leads that were active in the pipeline during the selected period MUST be included

#### Scenario: Pipeline velocity report

- GIVEN a Sales Pipeline with historical data
- WHEN the admin views the velocity report
- THEN the system MUST display:
  - Average deal cycle time (days from first stage to closed stage)
  - Median deal cycle time
  - Deal cycle time trend over the last 6 months (line chart)
  - Breakdown by stage showing average days per stage
- AND the report MUST allow filtering by: pipeline, date range, assignee, value range

---

### Requirement: REQ-PIPE-027: Win/Loss Tracking [Enterprise]

The system SHOULD track the outcome of closed leads with structured reason data to enable analysis of why deals are won or lost. This is a standard CRM feature in EspoCRM (close reason field on opportunities), Krayin (lost reason on leads), and all enterprise CRMs.

#### Scenario: Record loss reason when moving to Lost stage

- GIVEN a lead "Gemeente XYZ" in stage "Negotiation"
- WHEN the user drags the lead to the "Lost" stage
- THEN the system MUST display a modal dialog asking for:
  - Lost reason (required, select from predefined list): "Price too high", "Chose competitor", "No budget", "Requirements changed", "No response", "Other"
  - Lost reason notes (optional, free text, max 500 chars)
- AND the user MUST NOT be able to complete the move without selecting a reason
- AND the lead MUST store the `lostReason` and `lostReasonNotes` fields

#### Scenario: Record win details when moving to Won stage

- GIVEN a lead "BigCorp deal" in stage "Proposal"
- WHEN the user moves the lead to the "Won" stage
- THEN the system SHOULD display a dialog asking for:
  - Actual close date (pre-filled with today's date)
  - Actual value (pre-filled with the lead's current value, editable)
  - Win notes (optional, free text)
- AND the lead MUST store `actualCloseDate` and `actualValue` fields

#### Scenario: Win/loss analysis report

- GIVEN 50 closed leads over the past quarter (30 won, 20 lost)
- WHEN the admin views the "Win/Loss Analysis" report
- THEN the system MUST display:
  - Win rate: 60%
  - Top loss reasons: "Price too high" (8), "Chose competitor" (5), "No budget" (4), "Other" (3)
  - Average deal value: Won EUR 45,000 vs Lost EUR 32,000
  - Win rate by assignee: jan 70%, piet 55%, klaas 50%
- AND the report MUST be filterable by time period, pipeline, and assignee

---

### Requirement: REQ-PIPE-028: Pipeline Sidebar Details [MVP]

The pipeline view MUST include a sidebar panel that displays detailed information about the currently selected pipeline and its stages without navigating away from the board. The sidebar provides quick access to pipeline metadata and stage configuration.

#### Scenario: View pipeline details in sidebar

- GIVEN a user viewing the Sales Pipeline kanban board
- WHEN the user clicks the settings/gear icon in the pipeline header
- THEN a sidebar MUST open showing:
  - Pipeline title and description
  - Schema mappings (e.g., "lead, request")
  - Default pipeline indicator (star icon + Yes/No)
  - Stage count
  - Totals label (e.g., "EUR")
  - Color preview swatch
  - Stage flow preview (e.g., "New -> Contacted -> Qualified -> ... -> Won -> Lost")
  - Edit pipeline button
  - New pipeline button

#### Scenario: View stage list in sidebar

- GIVEN the sidebar is open on the "Stages" tab
- WHEN the user switches to the stages tab
- THEN the sidebar MUST display all stages in order, each showing:
  - Color dot indicator
  - Stage name
  - Order number
  - Probability percentage (if set)
  - "Closed" badge (if isClosed)
  - "Won" badge (if isWon)
- AND an "Edit stages" button MUST open the pipeline form for stage editing

#### Scenario: Sidebar does not block board interaction

- GIVEN the pipeline sidebar is open
- WHEN the user drags a card between stages on the kanban board
- THEN the drag-and-drop MUST work normally
- AND the sidebar MUST remain open during and after the drag operation

---

### Requirement: REQ-PIPE-029: View Persistence and User Preferences [V1]

The system MUST remember per-user pipeline view preferences so that returning to the pipeline view restores the user's last configuration. This reduces friction when users have consistent workflow patterns.

#### Scenario: Remember selected pipeline across navigation

- GIVEN user "jan" who last viewed the "Enterprise Pipeline"
- WHEN "jan" navigates away to the lead list and then returns to the pipeline view
- THEN the system MUST restore "Enterprise Pipeline" as the selected pipeline
- AND the kanban board MUST load with Enterprise Pipeline stages and items

#### Scenario: Remember view mode preference

- GIVEN user "jan" who switched to list view on their last pipeline visit
- WHEN "jan" returns to the pipeline view
- THEN the system MUST restore list view mode
- AND the list MUST show the previously selected pipeline's data

#### Scenario: Remember filter state

- GIVEN user "jan" who applied filters: entity type = "Leads only", assignee = "jan"
- WHEN "jan" returns to the pipeline view after navigating elsewhere
- THEN the previously applied filters SHOULD be restored
- AND the board MUST display the filtered results

#### Scenario: Preferences are per-user

- GIVEN user "jan" prefers list view on "Enterprise Pipeline"
- AND user "piet" prefers kanban view on "Sales Pipeline"
- WHEN each user navigates to the pipeline view
- THEN each user MUST see their own last-used configuration
- AND changing one user's preference MUST NOT affect the other user

---

### Requirement: REQ-PIPE-030: Weighted Pipeline Value and Sales Forecast [V1]

The system MUST calculate and display weighted pipeline values to provide a realistic forecast of expected revenue. The weighted value multiplies each lead's value by its stage probability, giving a more accurate picture than raw totals. This is a standard feature in EspoCRM (opportunity reports), Krayin (pipeline dashboard), and all enterprise CRMs.

#### Scenario: Weighted value in pipeline footer

- GIVEN a Sales Pipeline with open leads:
  - "Deal A": EUR 100,000 in stage "Qualified" (probability 40%) -> weighted EUR 40,000
  - "Deal B": EUR 50,000 in stage "Proposal" (probability 60%) -> weighted EUR 30,000
  - "Deal C": EUR 200,000 in stage "Negotiation" (probability 80%) -> weighted EUR 160,000
- WHEN the kanban board is rendered
- THEN the pipeline footer MUST display:
  - "Total value: EUR 350,000"
  - "Weighted value: EUR 230,000"
- AND in list view, the same footer values MUST be shown

#### Scenario: Weighted value per stage column

- GIVEN the "Qualified" stage with 3 leads:
  - EUR 100,000 (prob 40%), EUR 50,000 (prob 40%), EUR 30,000 (prob 40%)
- WHEN the column header is rendered
- THEN the header MUST display the raw total: "EUR 180,000"
- AND the header MAY additionally display the weighted total: "Weighted: EUR 72,000"

#### Scenario: Weighted value on dashboard KPI

- GIVEN the dashboard "Pipeline Value" widget
- WHEN the dashboard loads
- THEN the widget SHOULD display both:
  - Raw pipeline value (sum of all open lead values)
  - Weighted pipeline value (sum of value * probability / 100)
- AND the weighted value MUST be clearly labeled to distinguish it from the raw total

#### Scenario: Forecast by expected close date

- GIVEN leads with expected close dates in the current quarter
- WHEN the admin views the sales forecast
- THEN the system MUST display a monthly breakdown:
  - April 2026: EUR 80,000 weighted (5 deals)
  - May 2026: EUR 120,000 weighted (3 deals)
  - June 2026: EUR 50,000 weighted (7 deals)
- AND leads without an expected close date MUST be grouped separately as "Unscheduled"

---

## UI Reference

### Kanban Board Layout
See DESIGN-REFERENCES.md Section 3.2 for the complete kanban wireframe showing:
- Pipeline selector dropdown (top left)
- Kanban/List toggle (top right)
- "Show" entity type filter (top bar, next to pipeline selector)
- "+ Add Lead" button (top right)
- Stage columns with header (title, item count, total value)
- Cards within columns showing entity type badge, title, value, priority, due date, assignee
- Collapsed closed stages at the bottom (Won/Lost)
- "+ Add" button at the bottom of each column

### List View Layout
See DESIGN-REFERENCES.md Section 3.3 for the list view wireframe showing:
- Same pipeline selector and toggle controls as kanban
- Table columns: priority indicator, type badge, title, stage, value, due date, assigned user
- Footer with total items, total value, and weighted value

### Admin Settings Layout
See DESIGN-REFERENCES.md Section 3.7 for the admin settings wireframe showing:
- Pipeline cards with title, stage count, entity types, stage preview, edit button
- "+ Add Pipeline" button
- Default pipeline star indicator
- Stage management within the edit view (ordered list with drag-and-drop reorder)

---

### Current Implementation Status

**Implemented:**
- **REQ-PIPE-001 (Pipeline CRUD):** Implemented via `src/views/settings/PipelineManager.vue` (admin settings) and `src/views/settings/PipelineForm.vue`. Pipelines stored as OpenRegister objects. Create, edit, delete operations are functional. Pipeline list shows title, stage count, entity types, stage preview, edit/delete actions, and default star indicator.
- **REQ-PIPE-002 (Pipeline Entity Types):** Implemented via `propertyMappings` in the pipeline object. The `PipelineBoard.vue` supports multiple schema mappings per pipeline and includes a "Show" filter dropdown for mixed pipelines. Entity type badge distinction ([LEAD] blue, [REQ] orange) is implemented.
- **REQ-PIPE-003 (Default Pipelines):** Implemented in `lib/Service/DefaultPipelineService.php` with `PipelineStageData` providing Sales Pipeline (7 stages with probabilities) and Service Requests pipeline. Idempotent check by title before creation.
- **REQ-PIPE-004 (Stage CRUD):** Stages are embedded arrays within pipeline objects (not separate OpenRegister objects as the separate-entity model in this spec suggests). Stage management is in `PipelineForm.vue`.
- **REQ-PIPE-006 (Kanban Board View):** Fully implemented in `src/views/pipeline/PipelineBoard.vue`:
  - Stage columns with headers showing title, item count, and total value.
  - `PipelineCard.vue` with entity type badge, title, priority indicator, value, assignee, aging badge, due date, and overdue styling.
  - Drag-and-drop between stages using HTML5 drag API.
  - Request cards are visually distinct from lead cards with different badge colors.
  - Mixed entity kanban with "Show" filter (All, Leads only, Requests only).
- **REQ-PIPE-007 (Pipeline View Toggle):** Implemented. Toggle between kanban and list view. List view has sortable columns (Title, Type, Stage, Assignee, Value, Due Date, Priority, Age). Filters preserved across toggle.
- **REQ-PIPE-008 (Stage Column Headers):** Implemented with item count and total value. Value calculation sums only items with a `totalsProperty` mapping (leads have value, requests do not).
- **REQ-PIPE-009 (Add Entity from Stage Column):** Not directly visible in current PipelineBoard -- no "+ Add" button per column.
- **REQ-PIPE-010 (Pipeline Selection on Entity):** Leads and requests have `pipeline` and `stage` fields in their schemas. Default pipeline auto-assignment is handled in the lead/request creation forms.
- **REQ-PIPE-014 (Stage Revenue Summary):** Column headers show total value. No pipeline-wide footer with total/weighted values.
- **REQ-PIPE-015 (Error Scenarios):** Partial -- drag to same stage prevention is in `onDrop()` method (checks `data[columnProp] === targetStage.name`).
- **REQ-PIPE-016 (Pipeline List on Admin Settings):** Implemented in `PipelineManager.vue` with star indicator for default, stage count, entity types, stage preview, edit/delete buttons.
- **REQ-PIPE-017 (Pipeline Selector Dropdown):** Implemented in `PipelineBoard.vue` with NcSelect dropdown. Auto-selects default pipeline on mount.
- **REQ-PIPE-018 (Pipeline Card Quick Actions):** Implemented in `PipelineCard.vue` with quick stage change dropdown and quick assign dropdown. Actions use `@click.stop` to prevent card navigation.
- **REQ-PIPE-028 (Pipeline Sidebar Details):** Implemented in `PipelineSidebar.vue` with Details tab (pipeline metadata, schema labels, default indicator, stage flow preview, edit/create buttons) and Stages tab (ordered stage list with color dots, probability, closed/won badges, edit button).
- **Collapsed closed stages:** Implemented. Closed stages render as compact columns with title (uppercase) and count. Click to expand/collapse.
- **Overdue highlighting:** Red left border on overdue cards (`pipeline-card--overdue`). Red date text in list view.
- **Stale detection:** Stale badge shown on leads 14+ days since modification.
- **Aging indicator:** "Xd" badge on cards with color coding (amber 7+, red 14+).

**Not yet implemented:**
- **REQ-PIPE-005 (Stage Validation):** No client-side or server-side validation for: unique order within pipeline, at least one non-closed stage, isWon requires isClosed, probability range 0-100. (Validation relies on OpenRegister schema constraints only.)
- **REQ-PIPE-009 (Add Entity from Stage Column):** No "+ Add" button within stage columns.
- **REQ-PIPE-011 (Stage Probability Mapping):** No auto-population of lead probability from stage probability on drag-and-drop. Stage probability values exist on default pipelines but are not applied to leads.
- **REQ-PIPE-012 (Pipeline Analytics):** Not implemented. No conversion rate calculations, no average time per stage analysis.
- **REQ-PIPE-013 (Pipeline Funnel Visualization):** Not implemented. No funnel chart on dashboard.
- **REQ-PIPE-014 (Stage Revenue Summary) - Pipeline-wide footer:** No footer showing total pipeline value or weighted pipeline value.
- **REQ-PIPE-006 Scenario 6 (Delete pipeline with active items):** Warning about active items and reference clearing is not verified in the current UI.
- **REQ-PIPE-019 (Multiple Pipelines per Organization):** Partially implemented -- multiple pipelines can coexist and the dropdown switches between them, but there is no cross-pipeline list filter or aggregate dashboard KPI.
- **REQ-PIPE-020 (Pipeline Template Creation):** Not implemented. No template save/load functionality.
- **REQ-PIPE-021 (Stage Automation on Transition):** Not implemented. No automation rules on stage change.
- **REQ-PIPE-022 (Pipeline Filtering and Search):** Not implemented. No search bar or filter controls on the pipeline/kanban view beyond the entity type "Show" filter.
- **REQ-PIPE-023 (Pipeline Access Control):** Not implemented. All pipelines visible to all users. Admin-only pipeline management is enforced by Nextcloud's admin settings page.
- **REQ-PIPE-024 (Pipeline Dashboard Widgets):** Partially implemented. Dashboard has "Pipeline Value" KPI, "Overdue" KPI, "Deals by Stage" chart, and "My Work" widget. Missing: funnel widget, weighted value widget.
- **REQ-PIPE-025 (Stage SLA and Deadline Tracking):** Not implemented. No SLA configuration on stages, no breach indicators.
- **REQ-PIPE-026 (Pipeline Reporting):** Not implemented. No exportable reports or velocity metrics.
- **REQ-PIPE-027 (Win/Loss Tracking):** Not implemented. No lost reason prompt, no win details dialog, no win/loss analysis report.
- **REQ-PIPE-029 (View Persistence and User Preferences):** Not implemented. Selected view mode (kanban/list), pipeline selection, and filters are not persisted across navigation.
- **REQ-PIPE-030 (Weighted Pipeline Value and Sales Forecast):** Not implemented. No weighted value calculation, no pipeline footer with totals, no forecast by close date.
- **List view footer:** No "Showing N items - Total value: EUR X - Weighted: EUR Y" footer in list view.

**Partial implementations:**
- Stage data model uses embedded arrays within pipeline objects (simpler) rather than separate OpenRegister objects (as some parts of this spec describe). The spec has an internal contradiction between the "embedded array" model in openregister-integration spec and the "separate OpenRegister objects" model here.

### Standards & References
- **Schema.org:** `ItemList` for Pipeline, `DefinedTerm` for stages (spec reference, but implementation uses embedded arrays).
- **Industry patterns:** Trello-style kanban boards, HubSpot pipeline model, Nextcloud Deck column pattern.
- **HTML5 Drag and Drop API:** Used for card movement between stages.
- **WCAG AA:** Entity type distinction uses both color and text badges (not color-alone).
- **Competitor references:** EspoCRM (multi-pipeline opportunities, BPM automation, formula engine), Krayin (pipeline-per-team, lost reason, web forms, workflow automation), Twenty (modern UI, custom objects), BottleCRM (lightweight pipeline management).

### Specificity Assessment
- This spec contains 30 requirements with 80+ scenarios across MVP, V1, and Enterprise tiers. It is highly specific and implementable.
- **Key contradiction:** Stages as embedded arrays (openregister-integration spec) vs. stages as separate OpenRegister objects (this spec's REQ-PIPE-004). The implementation uses embedded arrays. This needs resolution.
- **Missing:** No specification for pipeline permissions (who can create/edit/delete pipelines -- admins only? any user?). REQ-PIPE-023 now addresses this at V1 tier.
- **Open questions:**
  - Should the pipeline selector show entity type labels? (Spec says yes in Scenario 59, implementation does not currently.)
  - How should the "propertyMappings" approach (current implementation) relate to the "entityTypes" array (spec)? The implementation has evolved beyond the spec.
  - What happens when a lead is dragged to a closed stage (Won/Lost)? REQ-PIPE-027 now specifies win/loss tracking with reason prompts.
