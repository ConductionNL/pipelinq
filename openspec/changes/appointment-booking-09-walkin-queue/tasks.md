# Tasks: Appointment Booking — Walk-In Queue (Member 09)

## Section 1: WalkInQueueRebalanceJob

- [ ] Extend `OCP\BackgroundJob\Job` (on-demand trigger, not timed)
- [ ] Register as event listener on Booking status → completed (or call from BookingService::completeBooking)
- [ ] In `run()`: query all WalkInTickets with `status: waiting`, recalculate `estimatedReadyAt` for each
- [ ] Log counts
- [ ] Add `@spec` PHPDoc

## Section 2: Ticket lifecycle

- [ ] On create: status `waiting`, arrivedAt now, estimatedReadyAt from earliest schedule gap (member 02 availability)
- [ ] Support transitions: waiting → called → served (set actualServedAt); waiting/called → abandoned

## Section 3: WalkInQueuePanel.vue

- [ ] Add SPDX header
- [ ] Display: WalkInTickets with status waiting or called, sorted by arrivedAt
- [ ] Each row: customer displayName, service, arrivedAt, estimatedReadyAt, actions (Call next, Serve, Abandon)
- [ ] "Call next": transition first waiting ticket to "called" (highlight/sound)
- [ ] "Serve": transition to "served", set actualServedAt
- [ ] "Abandon": transition to "abandoned"
- [ ] On action, refresh list and update estimatedReadyAt for remaining tickets
- [ ] Auto-refresh every 10 seconds (setInterval)
- [ ] Empty state when no waiting/called tickets
- [ ] All strings translated; @conduction/nextcloud-vue + axios

## Section 4: Unit Tests

- [ ] Test rebalance recomputes estimatedReadyAt for waiting tickets
- [ ] Test ticket transitions (call/serve/abandon)
- [ ] Mock ObjectService, AvailabilityService
