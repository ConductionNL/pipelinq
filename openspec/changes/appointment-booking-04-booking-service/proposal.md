---
kind: code
depends_on: [appointment-booking-03-skill-routing-eligibility]
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

# Proposal: Appointment Booking — Booking Service (Member 04 of 12)

**Member 4 of 12 in the appointment-booking chain.** Predecessor:
`appointment-booking-03-skill-routing-eligibility`. This member builds
`BookingService` — the booking lifecycle: create, confirm, reschedule, cancel,
mark no-show, complete — with validated status transitions and a status-history
audit trail.

`kind: code` per ADR-032: a PHP service + unit tests. Payment charges and email
dispatch are seams here, filled by members 07 (email) and 08 (payment).

## Why (from the giant)

A booking is only useful if its lifecycle is enforced: pending-deposit → confirmed
→ completed/no-show/cancelled, with reschedule as a parallel branch that preserves
the original for audit (NL Boekhoudplicht). Invalid transitions must be rejected.

## What this member does

- `createBooking`, `getAvailableSlots` (uses members 02+03), `confirmBooking`,
  `rescheduleBooking`, `cancelBooking` (policy enforcement), `markNoShow`,
  `completeBooking`.
- Status-transition validation + `statusHistory` audit array on every change.
- AvailabilityCache invalidation on booking create/update/delete (member 02 seam).
- Unit tests (≥6 methods).

Email/SMS dispatch (member 07), payment charging (member 08), and calendar push
(member 10) are injected seams — called but provided later.

## Dependencies

- `appointment-booking-03-skill-routing-eligibility` (and transitively 01, 02).
- OpenRegister `ObjectService`; `IUserSession`/`IUserManager`.
