## MODIFIED Requirements

### Requirement: There is one queue, and it is a filter

Pipelinq SHALL NOT model a queue as a record. The queue SHALL be every ticket that is
open and has no assignee, offered as the default view `queue` on the All tickets page
(`/tickets?savedView=default:queue`), not as a page of its own.

The view's filter SHALL be `assignee: "IS NULL"` and
`status_notIn: ["resolved", "completed", "rejected", "converted", "closed"]`, naming the
closed half of the lifecycle so a newly added open status is in the queue by default,
sorted by `occurredAt` ascending.

The former address `/queue` SHALL redirect to `/tickets?savedView=default:queue`.

#### Scenario: The queue holds unassigned open tickets

- **WHEN** an agent opens `/tickets?savedView=default:queue`
- **THEN** every row is a ticket with no assignee and an open status
- **AND** no ticket with an assignee appears

#### Scenario: The old address opens the view

- **WHEN** an agent opens `/queue`
- **THEN** the address becomes `/tickets?savedView=default:queue`
- **AND** the queue view is applied
