# callback-management delta: simple-tour-and-readable-labels

## ADDED Requirements

### Requirement: Task values read as words (REQ-CBM-TASK-LABELS)
The crmTask properties `type`, `status` and `priority` MUST declare
`x-enum-labels` for every enum value. Every label MUST have an en and an nl
catalogue entry. The create form, the filters and the task list MUST show the
label, never the stored code.

#### Scenario: Creating a task
@e2e exclude Asserted in tests/vitest/taskEnumLabels.spec.js on the merged register; the library renders x-enum-labels through the app's translate.
- **GIVEN** a user opens Create task
- **WHEN** they open the Type list
- **THEN** they MUST see "Callback request", "Follow-up task" and "Information request"
- **AND** never `callbackRequest` or `followUpTask`

#### Scenario: The task list
@e2e exclude Asserted in tests/vitest/taskEnumLabels.spec.js.
- **GIVEN** a task of type `callbackRequest`, status `in_progress`, priority `normal`
- **WHEN** a Dutch user reads the task list
- **THEN** the row MUST read "Terugbelverzoek", "Bezig" and "Normaal"
