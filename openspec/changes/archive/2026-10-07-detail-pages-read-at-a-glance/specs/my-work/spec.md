# my-work Specification (delta)

## ADDED Requirements

### Requirement: A user takes a ticket with one action (REQ-DPG-020)

TicketDetail and every row of the Queue SHALL offer "Assign to me". It SHALL
set the ticket's assignee to the signed-in user under their own
OpenRegister permissions: a ticket they cannot read answers 404, one they
cannot change answers 403. After assigning from the Queue, the ticket SHALL
open.

#### Scenario: An agent picks up a request from the Queue

- GIVEN an unassigned open request on the Queue
- WHEN the agent chooses "Assign to me" on its row
- THEN the request's assignee is the agent
- AND the request opens, and it is no longer on the Queue

### Requirement: My Work shows every ticket assigned to you (REQ-DPG-021)

My Work and the dashboard worklist SHALL list every open ticket assigned to
the user, whatever its ticket type, each with a badge for its type, next to
their leads and follow-ups. A ticket in a closed status (resolved, completed,
rejected, converted, closed) SHALL NOT be listed unless the user shows
completed items.

#### Scenario: An assigned complaint shows up

- GIVEN a complaint and a request both assigned to the agent
- WHEN the agent opens My Work
- THEN both appear under Tickets, one badged as a complaint, one as a request
