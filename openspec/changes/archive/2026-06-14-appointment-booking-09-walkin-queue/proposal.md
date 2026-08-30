---
kind: code
depends_on: [appointment-booking-08-deposit-payment]
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

# Proposal: Appointment Booking — Walk-In Queue (Member 09 of 12)

**Member 9 of 12 in the appointment-booking chain.** Predecessor:
`appointment-booking-08-deposit-payment`. This member builds the walk-in queue:
WalkInTicket creation, the `WalkInQueueRebalanceJob`, and the `WalkInQueuePanel.vue`
real-time view.

`kind: code` per ADR-032: a queue service/job + one Vue component + tests.

## Why (from the giant)

Barbershops, urgent-repair shops, and front-office teams mix scheduled appointments
with first-come-first-served walk-ins. Today's tools support one model or the
other; this member supports both — computing the earliest gap and rebalancing as
appointments complete.

## What this member does

- WalkInTicket lifecycle: created `waiting` with `estimatedReadyAt` computed from
  schedule gaps (uses member 02 availability); `called`/`served`/`abandoned`.
- `WalkInQueueRebalanceJob` (on Booking complete) recomputes `estimatedReadyAt`.
- `src/components/bookings/WalkInQueuePanel.vue` — real-time panel (Call next,
  Serve, Abandon, 10s auto-refresh).

## Dependencies

- `appointment-booking-08-deposit-payment` (chain order; uses member 02 availability
  + member 04 completeBooking event).
