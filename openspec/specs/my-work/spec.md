---
status: done
---

# My Work (Werkvoorraad) Specification

## Purpose

My Work provides a personal workload view aggregating all items assigned to the current user -- leads, requests, and optionally tasks from Procest. This is the user's daily productivity hub, showing what needs immediate attention, what is due soon, and what is upcoming. Items are organized into temporal groups (Overdue, Due This Week, Upcoming, No Due Date) and sorted by priority within each group.

The design follows the wireframe in DESIGN-REFERENCES.md section 3.5.

**Feature tier**: MVP (leads + requests, sorting, filtering, grouping), V1 (cross-app with Procest tasks, overdue highlighting)

---

## Requirements

### Requirement: Personal Workload View [MVP]

The system MUST provide a "My Work" view showing all leads and requests assigned to the current user. Only open items are shown by default, with a toggle to include completed items.

#### Scenario: View assigned leads and requests
- WHEN the current user navigates to "My Work"
- THEN the system MUST display all open leads and requests assigned to them
- AND each item MUST show: entity type badge, title, stage or status, due date, priority badge (if not normal)
- AND lead items MUST also show pipeline value (if set)

#### Scenario: Only open items by default
@e2e exclude requires assigned items as test data
- WHEN the user views My Work
- THEN only leads in non-closed stages and requests with non-terminal statuses MUST be shown
- AND closed/completed/rejected/converted items MUST NOT appear by default

#### Scenario: Toggle to show completed items
@e2e exclude requires completed assigned items
- WHEN the user enables the "Show completed" toggle
- THEN completed and closed items MUST also be displayed
- AND completed items MUST be visually distinct (muted color or completed badge)

#### Scenario: Item count display
@e2e exclude requires assigned items
- WHEN the user views My Work
- THEN the header MUST display the total count and breakdown (e.g., "Leads (5) · Requests (3) — 8 items total")

#### Scenario: Empty workload
- WHEN the current user has no assigned items
- THEN the system MUST display "No items assigned to you"

---

### Requirement: Sorting [MVP]

Within each temporal group, items MUST be sorted by priority first, then by due date ascending.

#### Scenario: Default sort order within groups
@e2e exclude requires multiple assigned items
- WHEN the user views My Work
- THEN within each group, items MUST be sorted by priority (urgent > high > normal > low), then by due date ascending

#### Scenario: Items without due date positioning
@e2e exclude requires items without due dates
- WHEN items exist without a due date
- THEN they MUST be grouped in the "No Due Date" section

---

### Requirement: Temporal Grouping [MVP]

Items MUST be organized into four temporal groups displayed top to bottom: Overdue, Due This Week, Upcoming, No Due Date. Empty groups MUST be hidden.

#### Scenario: Overdue group
@e2e exclude requires items with past due dates
- WHEN a lead has expectedCloseDate in the past
- THEN it MUST appear in the "Overdue" group with "N days overdue" displayed

#### Scenario: Due This Week group
@e2e exclude requires items due this week
- WHEN a lead has expectedCloseDate within the current calendar week (today through Sunday)
- THEN it MUST appear in the "Due This Week" group

#### Scenario: Upcoming group
@e2e exclude requires future-dated items
- WHEN a lead has expectedCloseDate after the current week
- THEN it MUST appear in the "Upcoming" group

#### Scenario: No Due Date group
@e2e exclude requires items without due dates
- WHEN an item has no due date set
- THEN it MUST appear in the "No Due Date" group (last group)

#### Scenario: Section item counts
@e2e exclude requires assigned items
- WHEN a group contains items
- THEN the group header MUST display its item count (e.g., "Overdue (2)")

#### Scenario: Empty group behavior
@e2e exclude requires controlled data state
- WHEN a group contains no items
- THEN it MUST be hidden (not shown as empty section)

---

### Requirement: Filtering [MVP]

The system MUST allow filtering by entity type: All (default), Leads, Requests.

#### Scenario: Filter by entity type
- WHEN the user selects a filter (All / Leads / Requests)
- THEN only matching items MUST be displayed
- AND the item count MUST update to reflect the filtered count
- AND grouping MUST be preserved

#### Scenario: Filter with empty result
@e2e exclude requires data and filter interaction
- WHEN filtering produces no items
- THEN the system MUST display an empty state message

---

### Requirement: Overdue Item Highlighting [MVP]

Overdue items MUST be visually distinct with red indicators and "N days overdue" text.

#### Scenario: Overdue visual treatment
@e2e exclude requires overdue items
- WHEN an item is overdue
- THEN it MUST display a red visual indicator and "N days overdue" in red text

#### Scenario: Due today is not overdue
@e2e exclude requires item due today
- WHEN an item is due today
- THEN it MUST appear in "Due This Week" (not Overdue)
- AND it SHOULD show "Due today" as a warning indicator

---

### Requirement: Item Navigation [MVP]

Each item MUST be clickable, navigating to the full detail view.

#### Scenario: Click item to navigate
@e2e exclude requires assigned items
- WHEN the user clicks a lead or request item
- THEN the system MUST navigate to the respective detail view

---

### Requirement: Cross-App Workload [V1]

The system SHALL support including items from Procest (cases and tasks assigned to the current user) in the My Work view. Where cross-app integration is enabled, Procest items MUST appear alongside Pipelinq items; this remains a V1 (SHOULD) capability until that integration ships.

#### Scenario: Include Procest tasks
@e2e exclude V1 Procest integration; not yet implemented
- GIVEN the current user has 3 leads in Pipelinq and 2 tasks in Procest assigned to them
- WHEN they view My Work with cross-app integration enabled
- THEN all 5 items MUST appear in a unified list
- AND each Procest item MUST display a "Task" or "Case" entity type badge
- AND Procest items MUST follow the same sorting and grouping rules

#### Scenario: Filter includes Procest entity types
@e2e exclude V1 Procest integration; not yet implemented
- GIVEN the cross-app workload is enabled
- WHEN the user views the filter options
- THEN the filter MUST include additional options: "Tasks" and/or "Cases"
- AND "All" MUST include Procest items

#### Scenario: Procest unavailable gracefully
@e2e exclude backend graceful degradation; covered by PHPUnit
- GIVEN Procest is not installed or not accessible
- WHEN the user views My Work
- THEN the system MUST display only Pipelinq items (leads and requests)
- AND the system MUST NOT show an error
- AND the cross-app filter options SHOULD be hidden

---

### Requirement: Item Card Layout [MVP]

Each item MUST follow a consistent card layout showing entity badge, title, stage/status, pipeline, value (leads), due date, and priority.

#### Scenario: Lead card in My Work
@e2e exclude requires assigned lead data
- WHEN a lead is displayed
- THEN it MUST show [LEAD] badge, title, stage, pipeline name, value, expected close date, and priority badge

#### Scenario: Request card in My Work
@e2e exclude requires assigned request data
- WHEN a request is displayed
- THEN it MUST show [REQ] badge, title, status, due date, and priority badge

---

### Requirement: My Work holds what is mine, the queue holds what is nobody's

My Work SHALL show the tickets assigned to the current user. It SHALL NOT show
unassigned work: that lives on the Queue page (`/queue`), which is every open ticket
with no assignee. Assigning a ticket moves it from the Queue to the assignee's My
Work; All tickets shows it in both states.

#### Scenario: My Work shows only the current user's tickets
@e2e exclude covered by spec-coverage/my-work.spec.ts, which asserts the assignee-scoped index

- **WHEN** an agent opens `/my-work`
- **THEN** every ticket shown has that agent as its assignee
- **AND** no unassigned ticket appears

#### Scenario: Picking work up moves it
@e2e exclude mutates a shared instance — assigning a ticket rewrites demo data other suites read

- **WHEN** an agent assigns themselves an unassigned ticket from `/queue`
- **THEN** the ticket appears on their `/my-work`
- **AND** it no longer appears on `/queue`

---

### Requirement: My Work UI — documented operations

The personal work overview screen implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `allItems`, `closedStageNames`, `computeGroup`, `currentUser`, `emptyMessage`, `fetchAll`). Each listed method realises an observable part of personal work overview screen and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN the frontend component/store is loaded
- WHEN a caller invokes one of the documented operations for personal work overview screen
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: My Work UI — results derived from current CRM state

Operations for personal work overview screen MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN CRM data backing personal work overview screen
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: My Work UI — defensive handling of absent or invalid input

Operations for personal work overview screen MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN an operation for personal work overview screen is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception

### Requirement: A user takes a ticket with one action (REQ-DPG-020)

TicketDetail and every row of the Queue SHALL offer "Assign to me". It SHALL
set the ticket's assignee to the signed-in user under their own
OpenRegister permissions: a ticket they cannot read answers 404, one they
cannot change answers 403. After assigning from the Queue, the ticket SHALL
open.

#### Scenario: An agent picks up a request from the Queue

- GIVEN an unassigned open request on the Queue
- WHEN the agent chooses "Assign to me" on its row
- THEN the request's assignee is the agent
- AND the request opens, and it is no longer on the Queue

### Requirement: My Work shows every ticket assigned to you (REQ-DPG-021)

My Work and the dashboard worklist SHALL list every open ticket assigned to
the user, whatever its ticket type, each with a badge for its type, next to
their leads and follow-ups. A ticket in a closed status (resolved, completed,
rejected, converted, closed) SHALL NOT be listed unless the user shows
completed items.

#### Scenario: An assigned complaint shows up

- GIVEN a complaint and a request both assigned to the agent
- WHEN the agent opens My Work
- THEN both appear under Tickets, one badged as a complaint, one as a request

## UI Layout Reference

The My Work view follows the wireframe in DESIGN-REFERENCES.md section 3.5:

```
Header: "My Work"  [Filter: All | Leads | Requests]  [Show completed toggle]
Subheader: "Leads (5) . Requests (3)  --  8 items total"

[Overdue (2)]
  - Item card (red highlight)
  - Item card (red highlight)

[Due This Week (3)]
  - Item card
  - Item card
  - Item card

[Upcoming (2)]
  - Item card
  - Item card

[No Due Date (1)]
  - Item card
```

- Each group has a header with the group name and item count
- Empty groups are hidden
- Items are full-width cards within each group
- The view is scrollable with all groups visible (no pagination)
- The layout MUST be responsive and stack properly on narrow viewports
- All interactive elements MUST be keyboard accessible (WCAG AA)

---

### Current Implementation Status

**Implemented:**
- Full `MyWork.vue` view exists at `src/views/MyWork.vue` with route `/my-work` (name `MyWork`) in `src/router/index.js`.
- **REQ-MW-010 (Personal Workload View):** Fully implemented. Fetches leads and requests assigned to `OC.currentUser` via OpenRegister API. Shows entity type badge (LEAD/REQ), title, stage/status, pipeline name, value (leads only), due date, and priority badge.
- **REQ-MW-020 (Sorting):** Implemented within each group -- items sorted by priority (urgent > high > normal > low), then by due date ascending.
- **REQ-MW-030 (Temporal Grouping):** Fully implemented. Four groups: Overdue, Due This Week, Upcoming, No Due Date. Empty groups are hidden. Group headers include item count.
- **REQ-MW-040 (Filtering):** Implemented. Filter buttons for All, Leads, Requests. Item counts update with filter.
- **REQ-MW-050 (Overdue Highlighting):** Implemented. Overdue items have red left border (`work-card--overdue`), red text showing "N days overdue". "Due today" warning is shown.
- **REQ-MW-060 (Item Navigation):** Implemented. Clicking a card navigates to `LeadDetail` or `RequestDetail` via `$router.push`. Cards are keyboard accessible with `tabindex="0"` and `@keydown.enter`.
- **REQ-MW-080 (Item Card Layout):** Implemented with entity badge, title, stage/status, pipeline name, value (leads), due date, and priority badge.
- Show completed toggle is implemented with visual distinction (opacity 0.6 via `work-card--completed` class).
- Item count breakdown in header ("Leads (5) . Requests (3) -- 8 items total") is implemented.
- Stale badge is shown on leads (14+ days since modification) using `isStale()` from `src/services/pipelineUtils.js`.

**Not yet implemented:**
- **REQ-MW-070 (Cross-App Workload with Procest):** Not implemented. No integration with Procest tasks/cases. The filter only shows All/Leads/Requests.
- No pagination -- fetches up to 200 leads and 200 requests via `_limit: 200`.
- Empty state message is generic ("No items assigned to you") rather than per-filter.

**Partial implementations:**
- Request overdue detection uses a fixed 30-day threshold from `requestedAt`, not the `expectedCloseDate` pattern. This may not match all user expectations.

### Standards & References
- WCAG AA: Keyboard accessibility implemented (tabindex, keydown.enter handlers). Color-only distinction is supplemented with text ("N days overdue", badges).
- No specific API standard applies -- this is a frontend aggregation view.

### Specificity Assessment
- The spec is well-defined for MVP requirements. All MVP scenarios have clear acceptance criteria.
- **V1 gap:** The cross-app Procest integration (REQ-MW-070) lacks detail on how Procest tasks will be discovered -- via direct API call, shared register, or cross-app event system.
- **Open question:** The spec groups requests in "No Due Date" or "Overdue" only. Should requests with a `requestedAt` date use that as their temporal grouping date (current implementation) or only use explicit due dates?
