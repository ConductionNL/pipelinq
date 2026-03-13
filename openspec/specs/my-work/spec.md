# My Work (Werkvoorraad) Specification

## Purpose

My Work provides a personal workload view aggregating all items assigned to the current user -- leads, requests, and optionally tasks from Procest. This is the user's daily productivity hub, showing what needs immediate attention, what is due soon, and what is upcoming. Items are organized into temporal groups (Overdue, Due This Week, Upcoming, No Due Date) and sorted by priority within each group.

The design follows the wireframe in DESIGN-REFERENCES.md section 3.5.

**Feature tier**: MVP (leads + requests, sorting, filtering, grouping), V1 (cross-app with Procest tasks, overdue highlighting)

---

## Requirements

### REQ-MW-010: Personal Workload View [MVP]

The system MUST provide a "My Work" view showing all leads and requests assigned to the current user. Only open items are shown by default, with a toggle to include completed items.

#### Scenario: View assigned leads and requests
- WHEN the current user navigates to "My Work"
- THEN the system MUST display all open leads and requests assigned to them
- AND each item MUST show: entity type badge, title, stage or status, due date, priority badge (if not normal)
- AND lead items MUST also show pipeline value (if set)

#### Scenario: Only open items by default
- WHEN the user views My Work
- THEN only leads in non-closed stages and requests with non-terminal statuses MUST be shown
- AND closed/completed/rejected/converted items MUST NOT appear by default

#### Scenario: Toggle to show completed items
- WHEN the user enables the "Show completed" toggle
- THEN completed and closed items MUST also be displayed
- AND completed items MUST be visually distinct (muted color or completed badge)

#### Scenario: Item count display
- WHEN the user views My Work
- THEN the header MUST display the total count and breakdown (e.g., "Leads (5) · Requests (3) — 8 items total")

#### Scenario: Empty workload
- WHEN the current user has no assigned items
- THEN the system MUST display "No items assigned to you"

---

### REQ-MW-020: Sorting [MVP]

Within each temporal group, items MUST be sorted by priority first, then by due date ascending.

#### Scenario: Default sort order within groups
- WHEN the user views My Work
- THEN within each group, items MUST be sorted by priority (urgent > high > normal > low), then by due date ascending

#### Scenario: Items without due date positioning
- WHEN items exist without a due date
- THEN they MUST be grouped in the "No Due Date" section

---

### REQ-MW-030: Temporal Grouping [MVP]

Items MUST be organized into four temporal groups displayed top to bottom: Overdue, Due This Week, Upcoming, No Due Date. Empty groups MUST be hidden.

#### Scenario: Overdue group
- WHEN a lead has expectedCloseDate in the past
- THEN it MUST appear in the "Overdue" group with "N days overdue" displayed

#### Scenario: Due This Week group
- WHEN a lead has expectedCloseDate within the current calendar week (today through Sunday)
- THEN it MUST appear in the "Due This Week" group

#### Scenario: Upcoming group
- WHEN a lead has expectedCloseDate after the current week
- THEN it MUST appear in the "Upcoming" group

#### Scenario: No Due Date group
- WHEN an item has no due date set
- THEN it MUST appear in the "No Due Date" group (last group)

#### Scenario: Section item counts
- WHEN a group contains items
- THEN the group header MUST display its item count (e.g., "Overdue (2)")

#### Scenario: Empty group behavior
- WHEN a group contains no items
- THEN it MUST be hidden (not shown as empty section)

---

### REQ-MW-040: Filtering [MVP]

The system MUST allow filtering by entity type: All (default), Leads, Requests.

#### Scenario: Filter by entity type
- WHEN the user selects a filter (All / Leads / Requests)
- THEN only matching items MUST be displayed
- AND the item count MUST update to reflect the filtered count
- AND grouping MUST be preserved

#### Scenario: Filter with empty result
- WHEN filtering produces no items
- THEN the system MUST display an empty state message

---

### REQ-MW-050: Overdue Item Highlighting [MVP]

Overdue items MUST be visually distinct with red indicators and "N days overdue" text.

#### Scenario: Overdue visual treatment
- WHEN an item is overdue
- THEN it MUST display a red visual indicator and "N days overdue" in red text

#### Scenario: Due today is not overdue
- WHEN an item is due today
- THEN it MUST appear in "Due This Week" (not Overdue)
- AND it SHOULD show "Due today" as a warning indicator

---

### REQ-MW-060: Item Navigation [MVP]

Each item MUST be clickable, navigating to the full detail view.

#### Scenario: Click item to navigate
- WHEN the user clicks a lead or request item
- THEN the system MUST navigate to the respective detail view

---

### REQ-MW-070: Cross-App Workload [V1]

The system SHOULD include items from Procest (cases and tasks assigned to the current user) in the My Work view.

#### Scenario: Include Procest tasks
- GIVEN the current user has 3 leads in Pipelinq and 2 tasks in Procest assigned to them
- WHEN they view My Work with cross-app integration enabled
- THEN all 5 items MUST appear in a unified list
- AND each Procest item MUST display a "Task" or "Case" entity type badge
- AND Procest items MUST follow the same sorting and grouping rules

#### Scenario: Filter includes Procest entity types
- GIVEN the cross-app workload is enabled
- WHEN the user views the filter options
- THEN the filter MUST include additional options: "Tasks" and/or "Cases"
- AND "All" MUST include Procest items

#### Scenario: Procest unavailable gracefully
- GIVEN Procest is not installed or not accessible
- WHEN the user views My Work
- THEN the system MUST display only Pipelinq items (leads and requests)
- AND the system MUST NOT show an error
- AND the cross-app filter options SHOULD be hidden

---

### REQ-MW-080: Item Card Layout [MVP]

Each item MUST follow a consistent card layout showing entity badge, title, stage/status, pipeline, value (leads), due date, and priority.

#### Scenario: Lead card in My Work
- WHEN a lead is displayed
- THEN it MUST show [LEAD] badge, title, stage, pipeline name, value, expected close date, and priority badge

#### Scenario: Request card in My Work
- WHEN a request is displayed
- THEN it MUST show [REQ] badge, title, status, due date, and priority badge

---

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
