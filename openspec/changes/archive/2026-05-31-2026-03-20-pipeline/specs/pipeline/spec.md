# Delta Spec: pipeline search and stage validation

## Purpose

Implement two missing MVP pipeline features: in-memory title search on the pipeline board (REQ-PIPE-022) and client-side probability range validation in the stage editor (REQ-PIPE-005 Scenario 24).

**Main spec ref**: [specs/pipeline/spec.md](../../../../specs/pipeline/spec.md)
**Feature tier**: MVP

---

## Newly Implemented

### REQ-PIPE-022: Pipeline search by title

The pipeline kanban and list views MUST filter displayed items by title when the user types in the search input.

#### Scenario: Search bar renders in pipeline header

- GIVEN a user viewing any pipeline in kanban or list mode
- WHEN the pipeline board loads
- THEN a text search input labeled "Search pipeline..." MUST be visible in the pipeline header
- AND the input MUST be keyboard-accessible and WCAG AA compliant

#### Scenario: Search filters kanban cards by title (case-insensitive)

- GIVEN a pipeline with 50 leads across all stages, including leads titled "Gemeente Amsterdam" and "Waterschap Rijn en IJssel"
- WHEN the user types "gemeente" in the search input
- THEN the kanban MUST show only leads whose `title` contains "gemeente" (case-insensitive)
- AND stage columns that have no matching cards MUST remain visible as empty columns
- AND column headers MUST update their item counts and totals to reflect only the matching cards

#### Scenario: Search filters list view rows by title

- GIVEN a user in list mode on the same pipeline
- WHEN the user types "gemeente" in the search input
- THEN the list table MUST show only rows whose `title` contains "gemeente" (case-insensitive)
- AND the row count in any footer or label MUST reflect the filtered count

#### Scenario: Clearing search restores full board

- GIVEN the user has typed "gemeente" and sees filtered results
- WHEN the user clears the search input (deletes all text or clicks the clear button)
- THEN ALL pipeline items MUST reappear on the board without a new API call
- AND column headers MUST restore their original counts and totals

#### Scenario: Search resets on pipeline switch

- GIVEN the user has typed "Amsterdam" in the search input while viewing "Verkooppijplijn"
- WHEN the user selects a different pipeline from the pipeline selector dropdown
- THEN the search input MUST be cleared automatically
- AND the new pipeline MUST load showing all its items unfiltered

#### Scenario: Search preserves kanban/list view mode

- GIVEN the user is in list mode with an active search "Waterschap"
- WHEN the user clicks the kanban toggle
- THEN the search string MUST be preserved
- AND the kanban MUST show only cards matching "Waterschap"

---

### REQ-PIPE-005 Scenario 24: Stage probability range validation

The stage editor in `PipelineForm.vue` MUST prevent saving a stage with a probability value outside the 0–100 range.

#### Scenario: Invalid probability shows inline error

- GIVEN an admin editing a stage in the pipeline form
- WHEN they enter a probability value of `120` in the probability field
- THEN an inline error message MUST appear adjacent to the field: "Probability must be between 0 and 100"
- AND the error MUST appear without requiring a form submission (on input or blur)

#### Scenario: Negative probability shows inline error

- GIVEN an admin editing a stage
- WHEN they enter a probability value of `-5`
- THEN an inline error message MUST appear: "Probability must be between 0 and 100"

#### Scenario: Save is blocked while validation error is present

- GIVEN an admin who has entered probability `150` on one stage
- WHEN they attempt to click the save button
- THEN the save button MUST be disabled (or the form submission MUST be prevented)
- AND an error state MUST be visible on the offending stage row

#### Scenario: Boundary values 0 and 100 are valid

- GIVEN an admin editing a stage
- WHEN they set probability to `0`
- THEN NO validation error MUST appear
- AND the same applies for probability `100`

#### Scenario: Empty probability field is valid (optional field)

- GIVEN an admin editing a stage
- WHEN they leave the probability field empty (null / not set)
- THEN NO validation error MUST appear
- AND the form MUST be saveable with a blank probability

#### Scenario: Valid probability clears the error

- GIVEN an admin who entered `150` (showing an error)
- WHEN they change the value to `75`
- THEN the inline error MUST disappear immediately
- AND the save button MUST become enabled (assuming no other validation errors)
