# Pipeline Insights Specification

## Purpose

Add temporal and financial context to pipeline views so users can spot bottlenecks, prioritize overdue items, and see revenue per stage at a glance.

## Requirements

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

---

### Current Implementation Status

**Implemented:**
- **Stage Revenue Summary:** Fully implemented in `PipelineBoard.vue`. Column headers show `getStageTotalValue()` which sums `totalsProperty` values (lead value) for items in each stage. Stages with zero total show the value. List view includes a Value column. Uses `propertyMappings` to determine which property holds the value per entity type (leads have value, requests do not).
- **Stale Lead Detection:** Fully implemented:
  - `src/services/pipelineUtils.js` provides `isStale(item, entityType)` function: returns true only for leads with `_dateModified` 14+ days ago.
  - Stale badge shown on kanban cards in `PipelineBoard.vue` list view (amber "Stale" badge).
  - Stale badge shown in `MyWork.vue` cards.
  - Only leads can be stale (requests return false).
- **Aging Indicator:** Fully implemented:
  - `getDaysAge(item)` calculates days since `_dateModified`.
  - `formatAge(days)` returns "Today", "1d", or "Xd" format.
  - `getAgingClass(days)` returns `aging-warning` (amber, 7+ days) or `aging-alert` (red, 14+ days).
  - Shown on kanban cards in `PipelineCard.vue` and in list view as an "Age" column.
- **Overdue Item Highlighting:** Fully implemented:
  - Kanban: `pipeline-card--overdue` adds red left border. Date shown in red (`card-date--overdue`).
  - List view: `list-row--overdue` adds subtle red background tint. `overdue-date` class shows date in red.
  - MyWork: Overdue group header in red with count badge. Cards show "N days overdue" text.
  - Lead overdue: `expectedCloseDate < today`.
  - Request overdue: `requestedAt` more than 30 days ago.
  - Closed/terminal items: MyWork skips items in closed stages or terminal statuses when `showCompleted` is false. Pipeline board does not explicitly exclude closed-stage items from overdue styling (partial gap).

**Not yet implemented:**
- **Stale badge in list view:** The spec says "the lead row MUST show a 'Stale' indicator" -- this IS implemented in `PipelineBoard.vue` list view.
- **Closed/terminal items overdue exclusion on pipeline board:** The pipeline board's `isItemOverdue()` method does not check for closed stages. Items in Won/Lost stages could show as overdue if their date has passed.

**Partial implementations:**
- Aging indicator uses `_dateModified` as a proxy for "days in current stage" rather than tracking actual stage entry date. This is noted in the spec's scenario ("number of days since `_dateModified`") so matches the spec, but may not reflect true stage duration if the item was modified for non-stage-change reasons.

### Standards & References
- No specific external standards apply. This spec defines visual presentation patterns common in CRM tools.
- WCAG AA: Color coding is supplemented with text labels (badge text, day counts).

### Specificity Assessment
- The spec is clear and implementable. All scenarios have concrete acceptance criteria.
- **Well-implemented:** Most of this spec is already in production.
- **Open question:** Should the 14-day stale threshold be configurable per organization? Currently hardcoded.
- **Open question:** Should aging track actual stage entry date rather than `_dateModified`? The latter changes on any update, not just stage changes.
- **Missing:** No specification for how stale/aging indicators interact with the pipeline analytics (pipeline spec REQ-PIPE-012).
