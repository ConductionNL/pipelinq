---
kind: code
depends_on: [appointment-booking-01-data-model]
chain:
  - appointment-booking-01-data-model
  - appointment-booking-02-availability-service
  - appointment-booking-03-skill-routing-eligibility
  - appointment-booking-04-booking-service
  - appointment-booking-05-portal-controller
  - appointment-booking-06-portal-frontend
  - appointment-booking-07-email-confirmation-reminder
  - appointment-booking-08-deposit-payment
  - appointment-booking-09-walkin-queue
  - appointment-booking-10-calendar-sync
  - appointment-booking-11-admin-ui
  - appointment-booking-12-compliance-i18n
---

# Proposal: Appointment Booking — Availability Service (Member 02 of 12)

**Member 2 of 12 in the appointment-booking chain.** Predecessor:
`appointment-booking-01-data-model`. This member builds `AvailabilityService` —
slot computation that intersects working hours, vacations, existing bookings, and
calendar-synced blocks into 15-minute-aligned free slots, backed by the
AvailabilityCache declared in member 01.

`kind: code` per ADR-032: the centre of mass is a PHP service + unit tests.

## Why (from the giant)

Staff calendars contain blocked time (lunch, meetings, vacation) that isn't
visible to booking systems, so portals show slots that collide. Real-time
availability is the core of any booking surface; this member is the computation
engine every downstream booking flow queries.

## What this member does

- `AvailabilityService::computeAvailability/getWorkingHours/getBlockedTimes/
  alignToSlots/invalidateCache/getOrComputeCache`.
- 15-minute slot alignment and buffer handling.
- AvailabilityCache read/regenerate with 24h expiry.
- Unit tests (≥4 methods) for working hours, vacations, booking conflicts, alignment.

Calendar-block fetching is stubbed behind a seam consumed in member 10
(calendar-sync); skill filtering is added in member 03.

## Dependencies

- `appointment-booking-01-data-model` (schemas).
- OpenRegister `ObjectService`; `OCP\ICacheFactory`.
