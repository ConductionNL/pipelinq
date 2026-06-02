---
kind: code
depends_on: [appointment-booking-02-availability-service]
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

# Proposal: Appointment Booking — Skill-Routing Eligibility (Member 03 of 12)

**Member 3 of 12 in the appointment-booking chain.** Predecessor:
`appointment-booking-02-availability-service`. This member adds skill-based
eligibility: query `skill-routing` for the Resources that can perform a Service,
then intersect with availability so unqualified resources never surface.

`kind: code` per ADR-032: a focused eligibility seam in PHP + unit tests.

## Why (from the giant)

"A color treatment needs a certified stylist" is invisible to most booking
systems; they show any stylist as available even without the skill. Skill routing
is delegated to the `skill-routing` app (no skill logic duplicated here, ADR-012).

## What this member does

- `getEligibleResources(string $serviceId): array` — calls skill-routing for
  resources matching `requiredSkills`; handles resources with no skills and
  per-step skills for multi-step services.
- Eligibility is intersected with member 02's availability so only bookable,
  qualified resources are returned.
- Unit tests for skill match, no-skill fallback, multi-step step-specific skills.

## Dependencies

- `appointment-booking-02-availability-service`.
- `skill-routing` (assumed available) — source of truth for resource→skill mapping.
