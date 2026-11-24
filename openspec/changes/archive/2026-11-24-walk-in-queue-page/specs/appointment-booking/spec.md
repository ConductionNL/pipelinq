# appointment-booking Specification (delta)

## ADDED Requirements

### Requirement: A walk-in operator works the queue on a manifest page

The app SHALL offer a walk-in queue page, declared in the manifest over the `walkInTicket` schema, that lists today's
waiting tickets in arrival order and lets the operator call the next ticket, serve it, or mark it abandoned. The page
SHALL use library widgets and declared actions; it SHALL NOT be a pipelinq component.

#### Scenario: The operator calls the next walk-in

- GIVEN three walk-in tickets waiting today
- WHEN the operator chooses Call next
- THEN the oldest waiting ticket is called and leaves the waiting list
