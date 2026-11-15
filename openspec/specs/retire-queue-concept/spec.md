# retire-queue-concept Specification

## Purpose
Replaces the separate queue objects with one filter over tickets. A ticket sits with whoever it is assigned to, assigning it moves it, and routing suggests an agent rather than a bucket, so a ticket never waits in a queue nobody watches.

## Requirements

### Requirement: There is one queue, and it is a filter

Pipelinq SHALL NOT model a queue as a record. The queue SHALL be every ticket that is
open and has no assignee, rendered on one page at `/queue` as an index over the
`ticket` schema.

The base filter SHALL be `assignee: "IS NULL"` and
`status_notIn: ["resolved", "completed", "rejected", "converted", "closed"]`, naming the
closed half of the lifecycle so a newly added open status is in the queue by default.

`assignee: "IS NULL"` is the literal sentinel every OpenRegister condition builder
matches by value, and it SHALL be preferred over the `assignee_isnull=true` suffix, which
was unimplemented when this page shipped and works only on instances carrying
openregister `isnull-filter-operator`.

#### Scenario: The queue holds unassigned open tickets

- **WHEN** an agent opens `/queue`
- **THEN** every row is a ticket with no assignee and a status of `new` or
  `in_progress`
- **AND** no ticket with an assignee appears

#### Scenario: The queue narrows by ticket type

- **WHEN** an agent selects the Complaints tab on `/queue`
- **THEN** the rows are the unassigned open tickets whose `ticketType` is `complaint`

#### Scenario: An empty queue says so

- **WHEN** every open ticket has an assignee
- **THEN** `/queue` renders its empty state rather than an empty table

### Requirement: Assigning a ticket moves it to the assignee

Assigning a ticket SHALL remove it from the queue and place it on the assignee's My
Work. The ticket SHALL remain visible on All tickets in both states.

#### Scenario: An assigned ticket leaves the queue
@e2e exclude mutates a shared instance — assigning a ticket rewrites demo data other suites read; the filter itself is asserted by queue.spec.ts, which proves the queue is a strict subset of the ticket index

- **GIVEN** a ticket on `/queue`
- **WHEN** an agent is set as its assignee
- **THEN** the ticket no longer appears on `/queue`
- **AND** it appears on that agent's `/my-work`
- **AND** it appears on `/tickets` in both cases

### Requirement: Routing suggests an agent, not a bucket

Skill-based routing SHALL match a ticket's category against agent skills and suggest
the best-matched, least-loaded agent. It SHALL NOT place a ticket in a named
container.

#### Scenario: Routing returns agents
@e2e exclude unchanged behaviour — RoutingController and RoutingService are untouched by this change and keep their existing coverage

- **WHEN** routing suggestions are requested for a ticket
- **THEN** the response ranks agents, and names no queue
