# Spec: retire-queue-concept

## ADDED Requirements

### Requirement: There is one queue, and it is a filter

Pipelinq SHALL NOT model a queue as a record. The queue SHALL be every ticket that is
open and has no assignee, rendered on one page at `/queue` as an index over the
`ticket` schema.

The base filter SHALL be `assignee: "IS NULL"` and `status_in: ["new",
"in_progress"]`. The `assignee_isnull=true` spelling SHALL NOT be used: OpenRegister's
`SearchQueryHandler::cleanQuery` compares the value with `=== true`, so a query string
degrades it to `IS NOT NULL` and the page renders empty with no error.

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

## REMOVED Requirements

### Requirement: Queue entity

**Reason**: A queue record was a hand-maintained filter. It made an agent pick a
bucket before they could see their work, and it hid a mis-routed ticket instead of
merely leaving it unassigned.

**Migration**: The `queue` schema and the `ticket.queue` property are removed. Run
`occ openregister:schemas:prune-retired` after upgrading; the schema import does not
remove a retired row on its own. Tickets that stored a queue UUID keep an inert
orphan key in their stored JSON.

### Requirement: Queue capacity and overflow

**Reason**: Capacity and overflow exist to keep a partition healthy. With no
partition there is nothing to rebalance.

**Migration**: `QueueOverflowJob` is deregistered and deleted, along with the
`queue_overflow.poll_interval_seconds` tunable.

### Requirement: Queue administration

**Reason**: Nothing to administer. Queues were created, named, ordered, capped and
deactivated by hand; the queue is now derived from ticket fields.

**Migration**: The admin queue section, `/queues`, `/queues/:id` and the default-queue
seeding are removed. The `/api/kcc-werkplek/state` payload no longer carries `queues`
or `queueCounts`, and the customer-360 summary no longer carries `queues` or
`queueCount`.
