## MODIFIED Requirements

### REQ-MW-010: Personal Workload View [MVP]

The system MUST provide a "My Work" view showing all leads, requests, and tasks assigned to the current user. Only open items are shown by default, with a toggle to include completed items. Tasks assigned to Nextcloud groups the user belongs to MUST also appear.

#### Scenario: View assigned leads, requests, and tasks

- **WHEN** the current user navigates to "My Work"
- **THEN** the system MUST display all open leads, requests, and tasks assigned to them
- AND tasks assigned to Nextcloud groups the user belongs to MUST also be included
- AND each item MUST show: entity type badge, title, stage or status, due date, priority badge (if not normal)
- AND lead items MUST also show pipeline value (if set)
- AND task items MUST show: type sub-badge (Terugbelverzoek/Opvolgtaak/Informatievraag), deadline, and assignee

#### Scenario: Only open items by default

- **WHEN** the user views My Work
- **THEN** only leads in non-closed stages, requests with non-terminal statuses, and tasks with status open or in_behandeling MUST be shown
- AND closed/completed/rejected/converted items and tasks with status afgerond or verlopen MUST NOT appear by default

#### Scenario: Toggle to show completed items

- **WHEN** the user enables the "Show completed" toggle
- **THEN** completed and closed items MUST also be displayed, including tasks with status afgerond and verlopen
- AND completed items MUST be visually distinct (muted color or completed badge)

#### Scenario: Item count display

- **WHEN** the user views My Work
- **THEN** the header MUST display the total count and breakdown (e.g., "Leads (5) · Requests (3) · Tasks (4) — 12 items total")

#### Scenario: Empty workload

- **WHEN** the current user has no assigned items
- **THEN** the system MUST display "No items assigned to you"

---

### REQ-MW-040: Filtering [MVP]

The system MUST allow filtering by entity type: All (default), Leads, Requests, Tasks.

#### Scenario: Filter by entity type

- **WHEN** the user selects a filter (All / Leads / Requests / Tasks)
- **THEN** only matching items MUST be displayed
- AND the item count MUST update to reflect the filtered count
- AND grouping MUST be preserved

#### Scenario: Filter with empty result

- **WHEN** filtering produces no items
- **THEN** the system MUST display an empty state message

---

### REQ-MW-080: Item Card Layout [MVP]

Each item MUST follow a consistent card layout showing entity badge, title, stage/status, pipeline, value (leads), due date, and priority.

#### Scenario: Lead card in My Work

- **WHEN** a lead is displayed
- **THEN** it MUST show [LEAD] badge, title, stage, pipeline name, value, expected close date, and priority badge

#### Scenario: Request card in My Work

- **WHEN** a request is displayed
- **THEN** it MUST show [REQ] badge, title, status, due date, and priority badge

#### Scenario: Task card in My Work

- **WHEN** a task is displayed
- **THEN** it MUST show [TASK] badge with type sub-label (Terugbelverzoek/Opvolgtaak/Informatievraag), subject, status, deadline, priority badge, and assignee name
- AND if the task is a terugbelverzoek with a preferred time slot, it MUST show the time slot
- AND clicking the task card MUST navigate to the task detail view
