# Pipeline Insights Specification (Delta)

## Purpose

Add temporal and financial context to pipeline views so users can spot bottlenecks, prioritize overdue items, and see revenue per stage at a glance.

## ADDED Requirements

### Requirement: Stage Revenue Summary [V1]

Each kanban column header MUST display the total EUR value of leads in that stage.

#### Scenario: Stage header shows total value
- GIVEN a pipeline stage contains leads with value fields
- WHEN the kanban board is displayed
- THEN each stage column header MUST show the summed EUR value formatted as "EUR X,XXX"
- AND stages with zero total value MUST show "EUR 0"

#### Scenario: Requests do not contribute to stage value
- GIVEN a mixed pipeline has both leads and requests in a stage
- WHEN the stage revenue is calculated
- THEN only lead values MUST be summed (requests have no value field)

#### Scenario: List view shows value column
- GIVEN the pipeline is in list view mode
- THEN the Value column MUST be present and show individual item values
- AND leads without a value MUST show "-"

### Requirement: Stale Lead Detection [V1]

Leads that have not been modified for 14 or more days MUST be visually flagged as stale.

#### Scenario: Stale badge on kanban card
- GIVEN a lead's `_dateModified` is 14 or more days ago
- WHEN the kanban board is displayed
- THEN the lead card MUST show a "Stale" badge with an orange/amber color
- AND the badge MUST indicate the number of days since last modification

#### Scenario: Stale badge in list view
- GIVEN a lead is stale (14+ days since modification)
- WHEN the list view is displayed
- THEN the lead row MUST show a "Stale" indicator

#### Scenario: Non-stale items show no badge
- GIVEN a lead was modified less than 14 days ago
- THEN no stale indicator MUST be shown

#### Scenario: Only leads can be stale
- GIVEN a request has not been modified for 14+ days
- THEN no stale badge MUST be shown (stale detection is lead-only)

### Requirement: Aging Indicator [V1]

Pipeline cards MUST show how many days the item has been in its current stage.

#### Scenario: Days-in-stage badge on kanban card
- GIVEN an item is on the pipeline board
- WHEN the card is displayed
- THEN a "Xd" or "X days" indicator MUST show the number of days since `_dateModified`
- AND items modified today MUST show "Today" or "0d"

#### Scenario: Aging in list view
- GIVEN the pipeline is in list view mode
- THEN an "Age" column MUST be present showing days in current stage

#### Scenario: Aging color coding
- GIVEN an item has been in stage for 7+ days
- THEN the aging indicator SHOULD use a warning color (amber)
- AND items in stage for 14+ days SHOULD use an alert color (red)

### Requirement: Overdue Item Highlighting [V1]

Overdue items MUST be visually prominent across all views.

#### Scenario: Overdue card styling on kanban board
- GIVEN a lead's `expectedCloseDate` has passed, or a request's `requestedAt` is more than 30 days ago
- WHEN the item is displayed on the kanban board
- THEN the card MUST have a red left border or background tint
- AND the date MUST be shown in red

#### Scenario: Overdue highlighting in list view
- GIVEN an item is overdue in list view
- THEN the due date cell MUST be shown in red text
- AND the row MAY have a subtle red background tint

#### Scenario: Overdue highlighting in My Work
- GIVEN the My Work view groups items by due status
- THEN overdue items in the "Overdue" group MUST have red date text
- AND the overdue group header MUST show the count prominently

#### Scenario: Closed/terminal items are not overdue
- GIVEN a lead is in a closed stage or a request has terminal status
- THEN the item MUST NOT be shown as overdue regardless of dates
