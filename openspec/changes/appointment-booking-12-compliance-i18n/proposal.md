---
kind: code
depends_on: [appointment-booking-11-admin-ui]
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

# Proposal: Appointment Booking — Compliance & i18n (Member 12 of 12)

**Member 12 of 12 in the appointment-booking chain (final member).** Predecessor:
`appointment-booking-11-admin-ui`. This member closes out regulatory compliance
(AVG right-to-be-forgotten pseudonymization, NL Boekhoudplicht 7-year retention,
WCAG 2.1 AA), the i18n sweep (en + nl key parity), and code-quality verification.

`kind: code` per ADR-032: compliance service + translations + verification.

## Why (from the giant)

Dutch regulations — AVG (customer data), NL Boekhoudplicht (7-year retention), PSD2
(handled in member 08), WCAG 2.1 AA (accessibility) — are met manually or not at all
in incumbent tools. Compliance is a first-class feature for MKB and government-window
use cases.

## What this member does

- `DataDeletionService::pseudonymizeCustomerBookings` — replace name/email/phone on a
  customer's Bookings with SHA-256 hashes, keep records (7-year retention), keep
  aggregates, log the action.
- Retention invariant: bookings never auto-deleted; terminal statuses are not purged.
- WCAG 2.1 AA verification of the portal + admin forms (axe-core, keyboard, contrast).
- i18n: complete `l10n/en.json` + `l10n/nl.json` with identical key sets covering
  portal, admin, queue, and email strings; no hardcoded strings.
- Pre-commit verification gates (SPDX, ObjectService arg-count, no getMessage, store/
  router registration, lint, tests).

## Dependencies

- `appointment-booking-11-admin-ui` (and the whole chain — this sweeps it all).
