# mobile-experience: delta for task-contact-moment

## ADDED Requirements

### Requirement: A follow-up task names the visit it came from

A follow-up task created while logging a visit MUST reference the saved visit (a ticket of type interaction) in `contactMoment`.

#### Scenario: a visit with a follow-up day

- GIVEN an employee logs a visit on a client with a follow-up day
- WHEN the visit is saved
- THEN the follow-up task's `contactMoment` is the visit's id
