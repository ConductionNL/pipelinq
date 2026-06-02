---
kind: code
depends_on: [appointment-booking-07-email-confirmation-reminder]
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

# Proposal: Appointment Booking — Deposit & Payment (Member 08 of 12)

**Member 8 of 12 in the appointment-booking chain.** Predecessor:
`appointment-booking-07-email-confirmation-reminder`. This member fills the payment
seams: deposit holds (slot held 15 min, PSD2 payment → confirmed) and no-show /
late-cancellation fee charging via openconnector.

`kind: code` per ADR-032: payment integration glue.

## Why (from the giant)

Deposits reduce no-shows for high-value services; no-show fees recover lost
revenue. Payment processing (Mollie/Stripe/Adyen, PSD2 SCA) is delegated to
openconnector — no custom 3D-Secure code (ADR-012).

## What this member does

- `POST /portal/book` (member 05 seam): if `requiresDeposit`, create payment
  session via openconnector, hold slot 15 min, status `pending-deposit` →
  `confirmed` on payment success (sets `depositPaidAt` + triggers confirmation
  email from member 07).
- 15-minute timeout releases the slot (`cancelled-by-business`).
- No-show / late-cancel fee charge via openconnector (member 04 seam); sets
  `noShowFeeChargedAt`; no charge if no payment method on file.

## Dependencies

- `appointment-booking-07-email-confirmation-reminder` (and transitively booking +
  portal).
- `openconnector` — payment processing + PSD2 SCA.
