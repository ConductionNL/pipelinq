# project-portfolio Delta

**Status**: proposed
**Scope**: pipelinq
**OpenSpec changes**:

- [project-close-current-cycle](../../)

## ADDED Requirements

### Requirement: A project page can close its current cycle

The system SHALL close a project's current cycle, the open cycle with the latest start date, on
`POST /api/programmes/{programmeId}/close-cycle`, writing the same snapshot as closing that cycle by id. It SHALL refuse
a project the caller may not read with 403 before reading any cycle, and a project without an open cycle with 409.

#### Scenario: Cyclus sluiten on PqProject

- **GIVEN** project "Vervanging straatverlichting Oost" with cycles 1 and 2 closed and cycle 3 open
- **WHEN** the owner presses "Cyclus sluiten" in the page header
- **THEN** cycle 3 is closed with its snapshot, and its unfinished tasks stay for the next cycle

#### Scenario: No open cycle

- **GIVEN** a project whose cycles are all closed
- **WHEN** a user asks to close its current cycle
- **THEN** the answer is 409 and nothing changes
