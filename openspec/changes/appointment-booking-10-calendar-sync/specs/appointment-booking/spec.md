# Appointment Booking — Calendar Sync (Member 10) Delta Spec

## Purpose

React to staff calendar blocks synced by the `calendar` leaf and push confirmed
Bookings back to staff calendars through the leaf; refresh the AvailabilityCache
hourly.

**Leaf-first boundary (ADR-022).** Calendar read/write is the leaf's; pipelinq adds
no CalDAV client or `X-PIPELINQ-*` VEVENT properties.

---

## ADDED Requirements

### Requirement: REQ-APT-018 Bi-Directional Calendar Sync

The system MUST react to staff calendar blocks (vacation, lunch, meetings) synced by
the `calendar` leaf every 5 minutes, and MUST push created Bookings back to staff
calendars by creating VEVENTs through the leaf.

**Feature tier**: V1

#### Scenario: Calendar-synced block is respected in availability

- **GIVEN** a staff member has a "lunch" event 12:00-13:00 in their calendar
- **WHEN** the `calendar` leaf (via email-calendar-sync) syncs the calendar
- **THEN** within 5 minutes the AvailabilityCache MUST be invalidated, and no customer can book that staff member 12:00-13:00

#### Scenario: Booking is pushed to staff calendar via the leaf

- **GIVEN** a Booking is created for staff member X on 2026-05-25 14:00
- **WHEN** the Booking is confirmed
- **THEN** a VEVENT MUST be created in X's calendar **through the `calendar` leaf's create API** (not a pipelinq-local CalDAV write) with customer name, service, and a deep-link back to the Booking

#### Scenario: AvailabilityCache is refreshed hourly

- **GIVEN** the AvailabilityCacheRefreshJob runs
- **WHEN** `run()` executes
- **THEN** the cache for all active Resources MUST be invalidated for today+30 days, with per-resource errors logged and skipped
