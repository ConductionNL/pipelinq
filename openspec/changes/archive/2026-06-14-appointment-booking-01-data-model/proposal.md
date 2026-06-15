---
kind: config
depends_on: []
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

# Proposal: Appointment Booking — Data Model (Member 01 of 12)

**Member 1 of 12 in the appointment-booking chain.** No predecessor (chain head).
This member declares the four booking domain schemas (Service, Resource, Booking,
WalkInTicket) plus the internal AvailabilityCache schema in
`lib/Settings/pipelinq_register.json`, with realistic Dutch seed data and an
integration test that the materialised schemas are queryable. Every later member
in the chain (`02`..`12`) consumes these schemas; nothing downstream can build
until they land.

This is a `kind: config` member per ADR-032: the centre of mass is declarative
register JSON. No PHP/Vue is touched here beyond the register manifest and a
schema-load integration test.

## Why (from the giant)

Turn Pipelinq from a CRM that records past interactions into a system that
schedules future ones. MKB service businesses (hairdressers, garages,
physiotherapists, tax advisors) run on pen-and-paper or third-party SaaS like
Calendly/Setmore that doesn't talk to their CRM. The booking domain is entirely
app-specific data that OpenRegister stores; declaring it first (expand-then-contract,
ADR-032) means every consumer member can opt-in incrementally against a stable
schema surface.

## What this member does

1. Register `service`, `resource`, `booking`, `walk-in-ticket`, and
   `availability-cache` schemas with complete field definitions (per design.md).
2. Add realistic Dutch seed data: 4 Services, 4 Resources, 2 Bookings.
3. Verify schemas materialise and are queryable via an integration test.

## Leaf-first boundary (ADR-022)

No calendar/email link schema is declared here. Calendar VEVENT linkage and email
dispatch are delegated to `email-calendar-sync` (consuming the OR `calendar` and
`email` leaves) in later members. Pipelinq owns only the booking domain entities.

## Scope

- In scope: schema registration + seed data + schema-load integration test.
- Out of scope: all services, controllers, views, jobs (members 02..12).

## Dependencies

- **OpenRegister** — schema registration, CRUD, audit trails, relations.
- **pipelinq-base** (completed) — register manifest exists.
