# Appointment Booking — Availability Service (Member 02) Delta Spec

## Purpose

Compute available 15-minute-aligned slots per resource per date by intersecting
working hours, vacations, booked times, and calendar-synced blocks; back it with
the AvailabilityCache.

---

## ADDED Requirements

### Requirement: REQ-APT-003 Availability Computation

The system MUST compute available 15-minute-aligned slots per resource per date by
intersecting working hours, vacations, booked times, and calendar-synced blocks.

**Feature tier**: V1

#### Scenario: Slots computed from working hours and existing bookings

- **GIVEN** a Resource works 09:00-17:30 and has a 30-minute booking 10:00-10:30
- **WHEN** availability is computed for that date with a 45-minute Service
- **THEN** a slot at 09:00-09:45 MUST be available, a slot at 10:30-11:15 MUST be available, and no slot at 09:45-10:30 (overlaps booking)

#### Scenario: Slots are 15-minute-aligned

- **GIVEN** a Resource is available 09:00-12:00 and a Service needs 45 minutes
- **WHEN** availability is computed
- **THEN** available start times MUST be `09:00, 09:15, 09:30, 09:45, 10:00, 10:15, 10:30, 10:45, 11:15` (only 15-minute boundaries where the full 45 minutes fit)

#### Scenario: Buffers are applied

- **GIVEN** a Service has `bufferBeforeMinutes: 10, bufferAfterMinutes: 5`, requires 30 minutes, and a Resource is available 09:00-12:00
- **WHEN** availability is computed
- **THEN** a booking from 09:30-10:00 MUST actually block 09:20-10:05

### Requirement: REQ-APT-016 AvailabilityCache Behaviour

The system MUST maintain a read-only cache of free slots per resource per date,
regenerated on changes and expiring after 24 hours.

**Feature tier**: V1

#### Scenario: Cache is populated from availability computation

- **GIVEN** availability is computed for resource X on date Y
- **WHEN** the computation completes
- **THEN** an AvailabilityCache record MUST be stored with `freeBlocks` and `expiresAt: now + 24h`

#### Scenario: Stale cache is still usable until refreshed

- **GIVEN** an AvailabilityCache entry exists but is older than 24 hours
- **WHEN** availability is queried for that date
- **THEN** the stale cache MUST still be returned, but the next change event MUST trigger a refresh
