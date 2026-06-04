# Tasks: Appointment Booking — Data Model (Member 01)

> **Leaf-first (ADR-022).** No calendar/email link schema. Declare booking domain only.

## Section 1: Schema Registration

- [ ] Add `service` schema to `lib/Settings/pipelinq_register.json` with fields: name, description, durationMinutes, bufferBefore/After, price, currency, requiredSkills, requiredResourceTypes, multiStep (array), bookableOnline, requiresDeposit, depositAmount, noShowFee, cancellationPolicy, cancellationHoursBefore, status
- [ ] Add `resource` schema with fields: name, type (enum: staff/room/equipment), skills, workingHours (array), vacations (array), calendarSyncId, bookable, userId, maxConcurrent, status
- [ ] Add `booking` schema with fields: customerId, serviceId, resourceAssignments (array), startAt, endAt, status (enum with all lifecycle values), statusHistory (array), notes, internalNotes, source, confirmationSentAt, reminderSentAt, depositPaidAt, depositAmount, noShowFeeChargedAt, cancellationReason, cancelledAt, cancelledBy, previousBookingId
- [ ] Add `walk-in-ticket` schema with fields: customerId, displayName, phone, serviceId, arrivedAt, estimatedReadyAt, status (enum: waiting/called/served/abandoned), assignedResourceId, actualServedAt
- [ ] Add `availability-cache` schema (internal, not queryable by users): resourceId, date, freeBlocks (array), generatedAt, expiresAt
- [ ] Verify all schemas have proper relations to customer/service/resource entities
- [ ] Verify multiStep, workingHours, vacations, statusHistory arrays have clear sub-schema definitions

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
