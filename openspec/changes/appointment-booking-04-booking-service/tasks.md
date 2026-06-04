# Tasks: Appointment Booking — Booking Service (Member 04)

## Section 1: BookingService

- [ ] Implement `createBooking(array $data, string $source): string` — validate customer, service, time availability; create Booking with status pending-deposit or confirmed; return booking UUID
- [ ] Implement `getAvailableSlots(string $serviceId, string $date): array` — call AvailabilityService (member 02), intersect eligible resources (member 03), return merged slots
- [ ] Implement `confirmBooking(string $bookingId, string $reason): void` — transition status to confirmed, set confirmationSentAt (email dispatch seam, member 07)
- [ ] Implement `rescheduleBooking(string $bookingId, string $newStartAt): string` — create new Booking, mark original as rescheduled, set previousBookingId, free old slot, return new UUID
- [ ] Implement `cancelBooking(string $bookingId, string $reason, string $cancelledBy): void` — validate cancellation policy, queue fee via payment seam (member 08) if applicable, transition to cancelled-by-customer or cancelled-by-business
- [ ] Implement `markNoShow(string $bookingId, string $staffUserId): void` — transition to no-show, increment customer's no-show count (fee charging deferred to member 08)
- [ ] Implement `completeBooking(string $bookingId): void` — transition to completed, optionally update customer lifetime value
- [ ] Validate all status transitions (pending-deposit → confirmed, confirmed → completed/no-show/cancelled, etc.)
- [ ] Maintain statusHistory array with each change: {status, changedAt, changedBy, reason}; use `IUserSession::getUser()->getUID()` (ADR-005)
- [ ] Invalidate AvailabilityCache on Booking create/update/delete (member 02 seam)
- [ ] Add `@spec` PHPDoc to all methods

## Section 2: Unit Tests

- [ ] Test `createBooking()` creates confirmed booking when no deposit required
- [ ] Test `createBooking()` creates pending-deposit booking when depositRequired
- [ ] Test `rescheduleBooking()` marks original as rescheduled, creates new booking with previousBookingId
- [ ] Test `cancelBooking()` with free-until-24-hours policy (no charge within window; charges after window)
- [ ] Test `markNoShow()` increments customer no-show count
- [ ] Test invalid status transitions are rejected
- [ ] Mock ObjectService, AvailabilityService, and the payment seam
