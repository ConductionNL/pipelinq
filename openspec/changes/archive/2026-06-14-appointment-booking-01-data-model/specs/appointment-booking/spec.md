# Appointment Booking — Data Model (Member 01) Delta Spec

## Purpose

Declare the booking domain schemas (Service, Resource, Booking, WalkInTicket) and
the internal AvailabilityCache schema in OpenRegister, with Dutch seed data. This
is the chain head; all downstream members consume these schemas.

**Leaf-first boundary (ADR-022).** No calendar/email link schema is declared here.

---

## ADDED Requirements

### Requirement: REQ-APT-001 Service Entity Schema

The system MUST support Service entities with configurable duration, pricing,
skills, multi-step composition, and booking policies.

**Feature tier**: V1

#### Scenario: Service with simple duration

- **GIVEN** a Service is created with `name: "Haircut"`, `durationMinutes: 30`, `price: 25.00`, `bookableOnline: true`
- **WHEN** the Service is saved in OpenRegister
- **THEN** the Service MUST be queryable and usable in availability computations

#### Scenario: Multi-step service with skill requirements

- **GIVEN** a Service is created with `multiStep: [{45m, color-certified}, {gap, allowGap: true}, {15m, any-stylist}]` and `requiredSkills: ["color-certified"]`
- **WHEN** the Service is saved
- **THEN** the multiStep array MUST be persisted with each step's `durationMinutes`, `skillRequired`, `resourceType`, and `allowGap`

### Requirement: REQ-APT-002 Resource Entity Schema

The system MUST support Resource entities (staff, room, equipment) with working
hours, vacations, skills, and optional calendar sync.

**Feature tier**: V1

#### Scenario: Staff resource with working hours

- **GIVEN** a Resource is created with `type: "staff"`, `name: "Sarah"`, `workingHours: [{day: monday, openTime: 09:00, closeTime: 17:30}, ...]`
- **WHEN** the Resource is saved
- **THEN** the workingHours array MUST be persisted and queryable per weekday

#### Scenario: Resource with vacation blocks

- **GIVEN** a Resource has `vacations: [{startDate: 2026-06-01, endDate: 2026-06-15}]`
- **WHEN** the Resource is saved
- **THEN** the vacations array MUST be persisted with its date ranges

### Requirement: REQ-APT-016 AvailabilityCache Schema

The system MUST declare a read-only AvailabilityCache schema of free slots per
resource per date for sub-second queries.

**Feature tier**: V1

#### Scenario: AvailabilityCache schema is registered

- **GIVEN** the pipelinq register manifest is loaded
- **WHEN** schemas materialise
- **THEN** an `availability-cache` schema MUST exist with fields `resourceId`, `date`, `freeBlocks` (array of `{startTime, endTime, durationMinutes, bookable}`), `generatedAt`, and `expiresAt`

#### Scenario: Seed objects are queryable

- **GIVEN** the register seed data is imported
- **WHEN** `ObjectService::findObjects('pipelinq', 'service', [])` is called
- **THEN** the four seed Services (haircut-simple, color-and-cut, oil-change-standard, consultation-tax) MUST be returned
