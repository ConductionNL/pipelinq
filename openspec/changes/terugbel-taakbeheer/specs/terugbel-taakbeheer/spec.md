# Terugbel- en Taakbeheer - Delta Spec

## Status: planned

## ADDED Requirements

### Requirement: Create Terugbelverzoek

The system MUST allow KCC agents to create callback requests during or after a contact.

#### Scenario: Create callback from active contact
- GIVEN an agent handling a contact for citizen "Jan de Vries"
- WHEN the agent creates a terugbelverzoek with subject, assignee, priority, and deadline
- THEN the system MUST create a taak object with type "terugbelverzoek"
- AND the taak MUST appear in the assignee's my-work inbox

### Requirement: Task Lifecycle Management

Tasks MUST follow a defined lifecycle with status transitions.

#### Scenario: Complete a callback task
- GIVEN a terugbelverzoek assigned to "Petra Bakker" in status "open"
- WHEN Petra marks it as "afgerond" with result notes
- THEN the task MUST transition to "afgerond" status
- AND the completion timestamp MUST be recorded

### Requirement: Integration with My Work

Tasks MUST appear in the assignee's personal my-work inbox.

#### Scenario: Task visible in my-work
- GIVEN a terugbelverzoek assigned to user "petra"
- WHEN petra opens the my-work view
- THEN the task MUST appear alongside leads and requests with a TAAK badge
