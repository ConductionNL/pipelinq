# Proposal: retire-queue-concept

## Problem

Pipelinq shipped queues as records. A `queue` schema carried a title, categories, a
capacity and a list of assigned agents; `/queues` listed them, `/queues/:id` opened
one, admin settings created them, `DefaultQueueService` seeded four Dutch ones on
install, and `QueueOverflowJob` moved items between them every five minutes when one
filled up. A ticket pointed at one through `ticket.queue`.

**An agent had to know where their work was before they could start.** The Customer
Support group offered All tickets, Tasks, My Work and Queues. A ticket waiting for
somebody sat in one of four named buckets, and finding it meant picking the right
bucket first. That is a lookup the agent performs before doing any work, every time.

**A ticket in the wrong bucket was invisible, not merely unassigned.** Routing put a
ticket in a queue by matching its category. Get the category wrong and the ticket is
not late, it is somewhere nobody is looking. Nothing in the UI showed the whole
waiting set, so nothing showed the gap either.

**The buckets needed their own maintenance.** Capacity, sort order, active flags,
assigned agents, and a background job to rebalance them. All of it exists to keep a
partition healthy that answers one question: what is waiting?

**The partition was never the interesting axis anyway.** Categories, priority and
channel are all fields on the ticket, and an index page filters on fields. A queue
record was a filter that somebody had to create, name and maintain by hand.

## Solution

There is one queue. It is every open ticket nobody has picked up, and it is a filter,
not a record.

1. **A `Queue` page** (`/queue`), an index over `ticket` filtered to unassigned and
   open. The ticketType tab strip narrows it to requests, complaints or
   contactmomenten; the standard index facets do the rest.
2. **Assigning a ticket moves it.** It leaves the Queue and appears on the assignee's
   **My Work**. **All tickets** still shows it either way.
3. **The `queue` schema, `ticket.queue`, and the routing chain are removed.**
   `QueueService`, `DefaultQueueService`'s queue half and `QueueOverflowJob` go;
   `DefaultQueueService` becomes `DefaultSkillService`, since seeding skills was its
   other job and skill routing survives.
4. **Skill-based routing is the replacement, and needs no change.**
   `RoutingController` already matches a ticket's category against agent skills and
   suggests an agent. It never referenced a queue. Routing now suggests a person
   directly instead of a bucket that a person then has to watch.

The nav reads Queue, My Work, All tickets, Tasks: what is waiting, what is mine,
everything.

## Impact

- **Data model**: the `queue` schema and `ticket.queue` are removed. Tickets that
  stored a queue UUID keep an orphan key in their stored JSON; it is inert and no
  code reads it.
- **Backend**: `QueueService` and `QueueOverflowJob` are deleted;
  `DefaultQueueService` becomes `DefaultSkillService`; `KccWerkplekService` loses the
  grouped-COUNT queue pushdown and its `/state` payload loses `queues` and
  `queueCounts`; `Customer360SummaryService` loses `queues` and `queueCount`.
- **Frontend**: `QueueList`, `QueueDetail`, `QueueCreateDialog`, the queues store,
  `queueUtils`, `WerkplekQueueFilter` and `QueueSettings` are deleted. The Customer
  Support dashboard loses its queue filter; the Client 360 page loses its "Active
  queues" tile.
- **Migration**: run `occ openregister:schemas:prune-retired` after upgrading. The
  schema import never removes a retired row on its own.

## Note on the filter spelling

The base filter is
`{"assignee": "IS NULL", "status_notIn": ["resolved", "completed", "rejected", "converted", "closed"]}`.

`assignee: "IS NULL"` is the literal sentinel every OpenRegister condition builder
matches by value. The suffix spelling `assignee_isnull=true` was dead when this page
shipped, and is fixed in openregister `isnull-filter-operator`. The sentinel is kept
because it works on instances that do not yet carry that change, and the two are two
spellings of one predicate.

`status_notIn` names the CLOSED half of the lifecycle rather than enumerating the open
half, so a new open status joins the queue the day it is added instead of being silently
missing from an `in` list.

**Correction, 2026-09-04.** This proposal originally said OpenRegister has no not-in
operator, and used `status_in: ["new", "in_progress"]` on that basis. The claim was
wrong: `notIn` and `ne` both work on object fields, measured over HTTP
(`status_notIn[]=closed` correctly excluded exactly the closed row). The filter was
correct either way; only the recorded reasoning was false, and a false reason in a note
is worse than no note.

## Why a page rather than a menu preset

gate-68 (`duplicate-index-pages`, ADR-097 Decision 5) reports Queue and All
tickets as two `type: index` pages over `pipelinq`/`ticket`, and proposes a
`menu[].query` preset instead. It reports this informationally and does not block.

The preset cannot carry this filter. Its values are constrained to string, number
or boolean by the manifest schema, so the five-value `status_notIn` list has no
expressible form as one. A preset is also only a link into another page: the query filters and
the reader's own facet filters share one map there, so a facet interaction can widen
the queue back to every ticket. A base filter on the page itself cannot be cleared by
the person reading it, which is the property that makes the queue trustworthy.
