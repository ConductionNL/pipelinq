# Appointment Booking — Portal Frontend (Member 06) Delta Spec

## Purpose

Provide the public Vue booking portal at `/book/{serviceSlug}` with a date picker,
15-minute slot picker, booking form, and a confirmation page.

---

## ADDED Requirements

### Requirement: REQ-APT-005A Public Booking Portal UI

The system MUST provide a public, unauthenticated web portal at `/book/{serviceSlug}`
where customers self-book appointments.

**Feature tier**: V1

#### Scenario: Customer books without login

- **GIVEN** a Service with `bookableOnline: true` and available slots exist next Tuesday
- **WHEN** a customer visits `/book/haircut`
- **THEN** they MUST see the service details, a date picker, an available slot picker (15-minute intervals), and a form for name+email+phone
- **AND** they MUST NOT be required to log in or create an account

#### Scenario: Unavailable dates are disabled in picker

- **GIVEN** available slots exist only on Tuesday and Thursday
- **WHEN** the customer views the date picker
- **THEN** dates other than Tuesday/Thursday MUST be visually disabled or grayed out

#### Scenario: Confirmation page shows booking summary

- **GIVEN** a booking was created successfully
- **WHEN** the customer lands on `/booking-confirmation/{bookingId}`
- **THEN** the page MUST display the service, resource, date/time, status, and price, plus a "confirmation email sent" notice and reschedule/cancel links
