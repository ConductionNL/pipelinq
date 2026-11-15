# navigation-ia delta: round4-readable-values-and-tour-titles

## ADDED Requirements

### Requirement: Every tour step has a title (REQ-NAV-TOUR-TITLES)
Every step of every tour pipelinq ships, in the simple and the full structure,
MUST declare a title. Every title MUST have an en and an nl catalogue entry.

#### Scenario: The simple menu tour
@e2e exclude Asserted in tests/vitest/tourStepTitles.spec.js on the shipped manifest and menu layouts; the library renders the step title.
- **GIVEN** a user of the simple menu starts the tour
- **WHEN** they reach step 2 of 8
- **THEN** the dialog MUST show the title "Questions and reports" after "Step 2 of 8:"

#### Scenario: A tour added later
@e2e exclude Asserted in tests/vitest/tourStepTitles.spec.js, which walks every tour in the manifest, its fragments and both menu layouts.
- **GIVEN** a developer adds a step without a title to any tour
- **WHEN** the unit tests run
- **THEN** they MUST fail and name the tour and the step
