# Design: Appointment Booking — Walk-In Queue (Member 09)

## Overview

Add the walk-in queue on top of the WalkInTicket schema (member 01). Ticket
`estimatedReadyAt` is computed from schedule gaps (member 02 availability) and
rebalanced when bookings complete (member 04 event).

## Backend (per giant design.md)

### WalkInQueueRebalanceJob (`lib/BackgroundJob/WalkInQueueRebalanceJob.php`)
- Extends `OCP\BackgroundJob\Job` (on-demand, not timed).
- Registered as an event listener on Booking status → completed (or called from
  `BookingService::completeBooking`).
- `run()`: query WalkInTickets with `status: waiting`, recompute `estimatedReadyAt`
  for each from the updated schedule.

### Ticket lifecycle
- Created `waiting`, `arrivedAt: now`, `estimatedReadyAt` from earliest gap.
- `called` (first waiting ticket), `served` (sets `actualServedAt`), `abandoned`.

## Frontend (per giant design.md)

### WalkInQueuePanel.vue (`src/components/bookings/WalkInQueuePanel.vue`)
- List WalkInTickets `waiting`/`called`, sorted by `arrivedAt`.
- Row: displayName, service, arrivedAt, estimatedReadyAt, actions (Call next, Serve,
  Abandon).
- "Call next" → first waiting → `called` (highlight/sound).
- "Serve" → `served` + `actualServedAt`. "Abandon" → `abandoned`.
- Auto-refresh every 10s (setInterval); empty state when none.
- SPDX header; all strings translated; @conduction/nextcloud-vue + axios.

## Tests

Unit tests for rebalance recomputation and ticket transitions; mock ObjectService +
AvailabilityService.
