# Tasks: Appointment Booking — Walk-In Queue (Member 09)

## Section 1: WalkInQueueRebalanceJob

- [x] Extend `OCP\BackgroundJob\Job` (on-demand trigger, not timed)
- [x] Register as event listener on Booking status → completed (or call from BookingService::completeBooking)
- [x] In `run()`: query all WalkInTickets with `status: waiting`, recalculate `estimatedReadyAt` for each
- [x] Log counts
- [x] Add `@spec` PHPDoc

## Section 2: Ticket lifecycle

- [x] On create: status `waiting`, arrivedAt now, estimatedReadyAt from earliest schedule gap (member 02 availability)
- [x] Support transitions: waiting → called → served (set actualServedAt); waiting/called → abandoned

## Section 3: WalkInQueuePanel.vue

- [x] Add SPDX header
- [x] Display: WalkInTickets with status waiting or called, sorted by arrivedAt
- [x] Each row: customer displayName, service, arrivedAt, estimatedReadyAt, actions (Call next, Serve, Abandon)
- [x] "Call next": transition first waiting ticket to "called" (highlight/sound)
- [x] "Serve": transition to "served", set actualServedAt
- [x] "Abandon": transition to "abandoned"
- [x] On action, refresh list and update estimatedReadyAt for remaining tickets
- [x] Auto-refresh every 10 seconds (setInterval)
- [x] Empty state when no waiting/called tickets
- [x] All strings translated; @conduction/nextcloud-vue + axios

## Section 4: Unit Tests

- [x] Test rebalance recomputes estimatedReadyAt for waiting tickets
- [x] Test ticket transitions (call/serve/abandon)
- [x] Mock ObjectService, AvailabilityService
