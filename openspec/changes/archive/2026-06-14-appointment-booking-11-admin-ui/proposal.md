---
kind: code
depends_on: [appointment-booking-10-calendar-sync]
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

# Proposal: Appointment Booking — Admin UI (Member 11 of 12)

**Member 11 of 12 in the appointment-booking chain.** Predecessor:
`appointment-booking-10-calendar-sync`. This member builds the staff admin surface:
Service/Resource/Booking list+detail views, the customer-timeline BookingsCard, the
Pinia stores, the router wiring, and the navigation.

`kind: code` per ADR-032: admin Vue views + stores + router.

## Why (from the giant)

Staff manage the catalogue (Services, Resources) and act on bookings (reschedule,
cancel, mark completed/no-show). Customer records must show their appointment
history (CRM integration is the whole point vs. Calendly).

## What this member does

- `ServiceList/Detail`, `ResourceList/Detail`, `BookingList/Detail` (CnIndexPage +
  CnDetailPage), booking status actions wired to the member-04/08 endpoints.
- `BookingsCard.vue` on the Customer detail page (REQ-APT-014).
- Four Pinia stores via `createObjectStore`; `src/router/booking-routes.js` + index
  wiring; "Bookings" nav with Services/Resources/Bookings sub-items.

## Dependencies

- `appointment-booking-10-calendar-sync` (chain order; consumes booking domain +
  endpoints from earlier members).
