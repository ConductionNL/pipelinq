# Tasks: Appointment Booking — Data Model (Member 01)

> **Leaf-first (ADR-022).** No calendar/email link schema. Declare booking domain only.

## Section 1: Schema Registration

- [x] Add `service` schema to `lib/Settings/pipelinq_register.json` with fields: name, description, durationMinutes, bufferBefore/After, price, currency, requiredSkills, requiredResourceTypes, multiStep (array), bookableOnline, requiresDeposit, depositAmount, noShowFee, cancellationPolicy, cancellationHoursBefore, status

  Declared as the ADR-037 fragment `lib/Settings/register.d/45-appointment-booking.json` (no edit of the shared monolith). All listed fields registered, plus `bufferBeforeMinutes`/`bufferAfterMinutes` with sensible defaults. Joined to the `pipelinq` register's schema list via the additive deep-merge.

- [x] Add `resource` schema with fields: name, type (enum: staff/room/equipment), skills, workingHours (array), vacations (array), calendarSyncId, bookable, userId, maxConcurrent, status

  Resource schema declares the full field set; `type` enum is `staff/room/equipment`, `workingHours` items carry `day` (weekday enum), `openTime`/`closeTime` (HH:MM pattern), and `vacations` items carry `startDate`/`endDate` (date) plus optional `label`.

- [x] Add `booking` schema with fields: customerId, serviceId, resourceAssignments (array), startAt, endAt, status (enum with all lifecycle values), statusHistory (array), notes, internalNotes, source, confirmationSentAt, reminderSentAt, depositPaidAt, depositAmount, noShowFeeChargedAt, cancellationReason, cancelledAt, cancelledBy, previousBookingId

  Booking schema declares every lifecycle value the giant calls out — `pending-deposit`, `confirmed`, `completed`, `no-show`, `cancelled-by-customer`, `cancelled-by-business`, `rescheduled`. `resourceAssignments[]` items carry `stepIndex`, `resourceId`, `startAt`, `endAt`; `statusHistory[]` items carry `status`, `changedAt`, `changedBy`, `reason`.

- [x] Add `walk-in-ticket` schema with fields: customerId, displayName, phone, serviceId, arrivedAt, estimatedReadyAt, status (enum: waiting/called/served/abandoned), assignedResourceId, actualServedAt

  Registered as the `walkInTicket` schema (camelCase slug — register.json convention is camelCase, see `posTransactionLine`, `receiptPrintLog`). All listed fields declared with the four-state queue enum.

- [x] Add `availability-cache` schema (internal, not queryable by users): resourceId, date, freeBlocks (array), generatedAt, expiresAt

  Registered as the `availabilityCache` schema; carries the OR-internal annotation `x-openregister: { internal: true, userQueryable: false }` to signal it is a regeneratable read-only cache (member 02 will own population).

- [x] Verify all schemas have proper relations to customer/service/resource entities

  Booking.customerId references the existing `contact` schema (Nextcloud addressbook entity per ADR + memory pin "Contact is a Nextcloud entity"); Booking.serviceId / Booking.resourceAssignments[].resourceId reference the new `service`/`resource` slugs; WalkInTicket reuses the same customer/service/resource references. AvailabilityCache.resourceId references the same `resource` schema.

- [x] Verify multiStep, workingHours, vacations, statusHistory arrays have clear sub-schema definitions

  Every array property carries an `items` object with explicit `required` keys, scalar `type`s, and either `enum` (weekday, resourceType, status) or `pattern` (HH:MM time) / `format` (date, date-time) validators.

## Section 2: Seed Data

- [ ] Add 4 Service seed objects: haircut-simple (simple), color-and-cut (multi-step), oil-change-standard, consultation-tax with varied requirements
- [ ] Each Service has unique slug
- [ ] Add 4 Resource seed objects: Sarah (stylist with skills), Jan (mechanic), treatment-room-a, workshop-bay-1 with varied types
- [ ] Each Resource has unique slug, working hours for all weekdays, optionally vacations
- [ ] Add 2 Booking seed objects: booking-001-completed (past), booking-002-confirmed (future, multi-step)
- [ ] Verify all seed objects use realistic Dutch names, times, and currency

## Section 3: Integration Test

- [ ] Add integration test that the five schemas materialise in the pipelinq register
- [ ] Test that seed Service/Resource/Booking objects are queryable via `ObjectService::findObjects()` (3 positional args per ADR-015)
