# Appointment Booking — Portal Controller (Member 05) Delta Spec

## Purpose

Provide a public, unauthenticated booking API at `/portal/*` for self-booking, with
HMAC-signed reschedule/cancel links.

---

## ADDED Requirements

### Requirement: REQ-APT-005 Public Booking API

The system MUST provide a public, unauthenticated API at `/portal/*` where customers
list services, query availability, create bookings, and reschedule/cancel via signed
links.

**Feature tier**: V1

#### Scenario: List bookable services without auth

- **GIVEN** Services with `bookableOnline: true` exist
- **WHEN** `GET /portal/services` is called without authentication
- **THEN** the response MUST be 200 with the bookable services and MUST NOT require login

#### Scenario: Submitting booking creates Booking record

- **GIVEN** a valid booking form payload (name, email, phone, serviceId, startAt)
- **WHEN** `POST /portal/book` is called
- **THEN** a Booking MUST be created with `status: pending-deposit` (if deposit required) or `status: confirmed` (if no deposit), `source: "portal"`, and the customer's email/phone linked

#### Scenario: Invalid email is rejected

- **GIVEN** a booking payload with a malformed email
- **WHEN** `POST /portal/book` is called
- **THEN** the response MUST be 400 with a static validation message (never a stack trace)

#### Scenario: Reschedule requires a valid signed link

- **GIVEN** a reschedule request with an expired or invalid HMAC signature
- **WHEN** `POST /portal/reschedule` is called
- **THEN** the response MUST be 410 (link expired/invalid)

#### Scenario: Signed links are generated with expiry

- **GIVEN** a confirmed Booking
- **WHEN** reschedule/cancel deep-links are generated
- **THEN** they MUST be signed with HMAC-SHA256 via `IURLGenerator` and MUST expire after 30 days
