---
kind: code
depends_on: [appointment-booking-06-portal-frontend]
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

# Proposal: Appointment Booking — Email Confirmation & Reminder (Member 07 of 12)

**Member 7 of 12 in the appointment-booking chain.** Predecessor:
`appointment-booking-06-portal-frontend`. This member wires confirmation and
24-hour reminder emails (with `.ics` attachment and signed reschedule/cancel
links) and the `ReminderDispatchJob`.

`kind: code` per ADR-032: email templates + a background job + tests.

## Leaf-first boundary (ADR-022)

Email dispatch is delegated to `email-calendar-sync` (consuming the OR `email`
leaf). Pipelinq composes the template content (subject, body, `.ics`, signed links)
and hands it to the leaf's dispatch API; it adds no SMTP client.

## Why (from the giant)

8-15% no-show rates cost MKB revenue. Confirmation + 24h reminder emails (and
optional SMS) cut no-shows; signed deep-links let customers reschedule/cancel
without an account.

## What this member does

- Confirmation email on booking create/deposit-paid: subject, body, RFC-5545 `.ics`
  attachment, signed reschedule/cancel links; sets `confirmationSentAt`.
- Reminder email + optional SMS 24h before; sets `reminderSentAt`.
- `ReminderDispatchJob` (`TimedJob`, 5-min interval) querying due bookings.
- Tests (≥2 per job).

## Dependencies

- `appointment-booking-06-portal-frontend` (and transitively the booking lifecycle).
- `email-calendar-sync` (email dispatch); openconnector (optional SMS).
