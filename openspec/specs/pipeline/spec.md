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

When a lead is moved to a stage that has a probability value set, the system SHOULD automatically update the lead's probability to match the stage probability.

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

The system SHOULD provide analytics for each pipeline to help managers understand conversion rates and bottlenecks.

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

The system SHOULD display a funnel chart showing the distribution of leads/requests across pipeline stages.

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

The system SHOULD display the total monetary value of leads in each stage to provide at-a-glance pipeline valuation.

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
