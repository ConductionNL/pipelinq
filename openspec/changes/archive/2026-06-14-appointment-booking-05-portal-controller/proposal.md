---
kind: code
depends_on: [appointment-booking-04-booking-service]
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

# Proposal: Appointment Booking — Portal Controller (Member 05 of 12)

**Member 5 of 12 in the appointment-booking chain.** Predecessor:
`appointment-booking-04-booking-service`. This member builds `PortalController` —
the public, unauthenticated HTTP surface for self-booking: list services, get
slots, create booking, fetch/reschedule/cancel by signed link.

`kind: code` per ADR-032: a thin controller (logic delegated to BookingService) +
routes + signed-link generation + unit tests.

## Why (from the giant)

Customers must self-book without an account. The portal API is the boundary
between the anonymous public and the booking domain; it must be public yet safe —
signed HMAC links for reschedule/cancel, static error messages, thin handlers.

## What this member does

- `GET /portal/services`, `GET /portal/availability`, `POST /portal/book`,
  `GET /portal/booking/{id}`, `POST /portal/reschedule`, `POST /portal/cancel`.
- HMAC-SHA256 signed deep-links via `IURLGenerator` (30-day expiry).
- Routes in `appinfo/routes.php` (specific before wildcard).
- Unit tests (≥5 methods: success, validation 400, 404, expired-link 410).

Payment-session initiation in `POST /portal/book` is a seam filled by member 08;
the Vue portal is member 06; email confirmation is member 07.

## Security (ADR-005)

Public endpoints; reschedule/cancel require a valid HMAC signature. Errors use
static messages (never `$e->getMessage()`). Thin handlers (<10 lines) delegate to
BookingService (ADR-003).

## Dependencies

- `appointment-booking-04-booking-service`.
- `IURLGenerator`, OpenRegister `ObjectService`.
