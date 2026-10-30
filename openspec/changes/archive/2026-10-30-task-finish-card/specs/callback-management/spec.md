# task-finish-card Delta: callback-management

## ADDED Requirements

### Requirement: A task is finished from its page

The task page SHALL show "Finish this task" between the task and its documents while the task is open or in progress. It SHALL ask the result and SHALL send it with "Log attempt" to the task's attempts endpoint and with "Finish" to its complete endpoint, first moving an open task to in progress through the lifecycle's claim transition. Both endpoints SHALL check the user may change the task before writing.

#### Scenario: An attempt without an answer

- **GIVEN** an open callback task
- **WHEN** the agent writes "Geen gehoor, voicemail ingesproken" and clicks Poging vastleggen
- **THEN** the attempt is added to the task's attempt log with that result and the card says "Poging vastgelegd."

#### Scenario: Finishing an open task

- **GIVEN** an open callback task
- **WHEN** the agent writes the result and clicks Afronden
- **THEN** the task moves to in progress and is completed with that result, in one click

#### Scenario: Finishing

- **GIVEN** a callback task in progress
- **WHEN** the agent writes the result and clicks Afronden
- **THEN** the task is completed with that result and the card leaves the page
