# Design: Appointment Booking — Data Model (Member 01)

## Overview

Declare the booking domain in OpenRegister: four user-facing schemas (Service,
Resource, Booking, WalkInTicket) plus one internal performance schema
(AvailabilityCache). Declarative-first (ADR-031): the data model is register JSON,
not hand-built mappers. CRUD is reused from `ObjectService` (ADR-001).

## Declarative-vs-imperative decision

These schemas are pure declarative metadata. No business logic lives here — slot
computation (member 02), lifecycle transitions (member 04), and routing (member 03)
consume the schemas later. Per ADR-031 the schema is the source of truth; the
AvailabilityCache is a regenerated read-only optimisation, never hand-edited.

## Schemas (per giant design.md)

### Service
name, description, durationMinutes, bufferBeforeMinutes, bufferAfterMinutes,
price, currency, requiredSkills[], requiredResourceTypes[], multiStep[] (each
`{durationMinutes, skillRequired, resourceType, allowGap}`), bookableOnline,
requiresDeposit, depositAmount, noShowFee, cancellationPolicy,
cancellationHoursBefore, status.

### Resource
name, type (staff/room/equipment), skills[], workingHours[] (`{day, openTime,
closeTime}`), vacations[] (`{startDate, endDate, label}`), calendarSyncId,
bookable, userId, maxConcurrent, status.

### Booking
customerId, serviceId, resourceAssignments[] (`{stepIndex, resourceId, startAt,
endAt}`), startAt, endAt, status (pending-deposit/confirmed/completed/no-show/
cancelled-by-customer/cancelled-by-business/rescheduled), statusHistory[]
(`{status, changedAt, changedBy, reason}`), notes, internalNotes, source,
confirmationSentAt, reminderSentAt, depositPaidAt, depositAmount,
noShowFeeChargedAt, cancellationReason, cancelledAt, cancelledBy,
previousBookingId.

### WalkInTicket
customerId, displayName, phone, serviceId, arrivedAt, estimatedReadyAt, status
(waiting/called/served/abandoned), assignedResourceId, actualServedAt.

### AvailabilityCache (internal, not user-queryable)
resourceId, date, freeBlocks[] (`{startTime, endTime, durationMinutes, bookable}`),
generatedAt, expiresAt.

## Seed Data

Seed objects live under `components.objects[]` with `x-openregister.type: "mock"`
in `lib/Settings/pipelinq_register.json`:

- **Services**: `haircut-simple` (30m, simple), `color-and-cut` (90m, multi-step,
  deposit), `oil-change-standard` (45m, always-charge), `consultation-tax` (45m,
  free).
- **Resources**: `sarah-stylist` (staff, color-certified), `jan-mechanic` (staff),
  `treatment-room-a` (room), `workshop-bay-1` (equipment) — each with weekday
  working hours.
- **Bookings**: `booking-001-completed` (past), `booking-002-confirmed`
  (future, multi-step). Full seed JSON in the giant design.md.

All seed objects use realistic Dutch names, times, and EUR currency.

## Integration test

A schema-load integration test verifies the five schemas materialise in the
pipelinq register and that a seed Service/Resource/Booking is queryable via
`ObjectService::findObjects()` (3 positional args, ADR-015).

## Reuse (ADR-012)

ObjectService for all CRUD; OpenRegister audit-trail/relations plugins; no
hand-rolled mappers or CRUD.
