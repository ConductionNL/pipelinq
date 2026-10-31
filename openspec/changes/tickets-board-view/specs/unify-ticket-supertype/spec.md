# unify-ticket-supertype Delta: tickets-board-view

## ADDED Requirements

### Requirement: The tickets list opens as a board by status

The tickets index SHALL offer a board view whose columns come from the ticket `status`, with a column switch for
type, channel and handler. A drop on a status column SHALL be allowed only when the ticket lifecycle allows the move
from the ticket's current status; otherwise it SHALL be refused with a sentence that names both statuses, and
nothing SHALL be written. While a card is dragged, columns the lifecycle does not allow SHALL be marked as not
reachable. The type and channel columns SHALL be read-only.

#### Scenario: Starting work on a new ticket

- **GIVEN** a ticket in status `new`
- **WHEN** the agent drops it on In progress
- **THEN** its status is written as `in_progress`

#### Scenario: A finished ticket cannot go back

- **GIVEN** a ticket in status `completed`
- **WHEN** the agent drops it on New
- **THEN** the move is refused with "You cannot move this ticket from Completed to New." and the card goes back

### Requirement: The tickets list has the work lenses

The tickets index SHALL offer the lenses All open (new, in progress, waiting for the customer; the default), Mine,
Unassigned, Waiting for customer and Past the deadline, followed by the ticket type lenses.

#### Scenario: Unassigned work

- **WHEN** the agent picks Unassigned
- **THEN** the list shows open tickets without an assignee
