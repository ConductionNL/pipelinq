> SUPERSEDED 2026-05-31: feature implemented; archived twin archive/2026-02-25-pipeline-foundation. Archived as already-delivered.

# Proposal: pipeline search and stage validation

## Problem

Two gaps in the pipeline view block day-to-day usability:

1. **REQ-PIPE-022 — No search or filter on the pipeline view.** Users managing pipelines with 50 or more leads across multiple stages have no way to find a specific item quickly. They must scroll through every column. This is listed as a known gap in the current implementation status section of `specs/pipeline/spec.md`.

2. **REQ-PIPE-005 (Scenario 24) — Stage probability is not range-validated.** The `PipelineForm.vue` stage editor accepts any integer for the `probability` field. A value of 120 or −5 is silently stored, which corrupts weighted pipeline value calculations. The main spec requires a validation error when probability falls outside 0–100.

## Proposed Change

### 1. Pipeline search bar (REQ-PIPE-022 — title filter)

Add a text search input to the `PipelineBoard.vue` header, positioned next to the pipeline selector dropdown. The search operates **in-memory** on the already-loaded `allItems` collection:

- Filter matches items whose `title` contains the search string (case-insensitive).
- In **kanban mode**: only matching cards are rendered per column; empty columns remain visible and are valid drop targets.
- In **list mode**: only matching rows are shown in the table.
- Column headers and totals update reactively to reflect the filtered set.
- Clearing the search restores the full board without a new API call.

This covers the core scenario from REQ-PIPE-022 ("Search by title within pipeline"). Advanced filters (assignee, priority, date range) remain out of scope for this change and are tracked separately for V1.

### 2. Stage probability validation (REQ-PIPE-005 Scenario 24)

Add client-side probability range validation to `PipelineForm.vue`:

- When the user enters a probability value in a stage row, validate on input (or blur) that the value is an integer between 0 and 100 inclusive.
- If the value is out of range, display an inline error: **"Probability must be between 0 and 100"** adjacent to the field.
- Prevent saving the pipeline while any stage has an invalid probability value.
- The existing empty-string / null case (no probability set) remains valid.

## Scope

### In scope
- `src/views/pipeline/PipelineBoard.vue` — add `searchQuery` data property; filter `allItems` computed; render `NcTextField` search input in header
- `src/views/settings/PipelineForm.vue` — add probability range validation; inline error state per stage row

### Out of scope
- Filter by assignee, priority, or date range (REQ-PIPE-022 advanced filters — V1)
- Server-side probability validation (OpenRegister schema `minimum`/`maximum` constraints handle the backend)
- Backend API changes or new endpoints
- New Vue components or PHP changes

## Impact

- **Files modified**: 2 Vue files
- **Risk**: Low — no API changes, no schema changes, no new dependencies, no routes added
- **Nextcloud integration**: uses existing `NcTextField` from `@conduction/nextcloud-vue`; all user-visible strings via `t(appName, '...')`
