---
kind: code
depends_on: [appointment-booking-05-portal-controller]
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

# Proposal: Appointment Booking — Portal Frontend (Member 06 of 12)

**Member 6 of 12 in the appointment-booking chain.** Predecessor:
`appointment-booking-05-portal-controller`. This member builds the customer-facing
Vue portal: `BookingPortal.vue` at `/book/{serviceSlug}` (date picker, slot picker,
booking form) and `BookingConfirmationPage.vue`.

`kind: code` per ADR-032: Vue views consuming the member-05 portal API.

## Why (from the giant)

The portal is where MKB customers actually book. It must be public (no login),
accessible (WCAG 2.1 AA), and bilingual (nl/en). It shows only available dates and
15-minute slots, never colliding times.

## What this member does

- `src/views/portal/BookingPortal.vue` — service header, date picker (only
  available dates enabled), 15-min slot picker, booking form (name/email/phone/
  notes), submit to `POST /portal/book`, payment redirect on deposit.
- `src/views/portal/BookingConfirmationPage.vue` — booking summary, "email sent"
  notice, reschedule/cancel links.
- WCAG 2.1 AA, CSS variables only, @conduction/nextcloud-vue components, axios,
  i18n via `this.t('pipelinq', ...)`.

## Dependencies

- `appointment-booking-05-portal-controller` (the API it calls).
