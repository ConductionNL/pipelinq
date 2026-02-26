## MODIFIED Requirements

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

### REQ-PIPE-009: Add Entity from Stage Column [MVP]

The system MUST allow creating new entities directly from within a stage column on the kanban board. On mixed pipelines, the quick-create form MUST include an entity type selector.

#### Scenario: Add request from stage column on mixed pipeline
- **WHEN** user clicks "+ Add" on a stage column of a mixed pipeline and selects "Request"
- **THEN** the quick-create form MUST show request-appropriate fields (title, priority)
- **THEN** the created request MUST appear on the correct stage column with a [REQ] badge
