# Design: Appointment Booking — Availability Service (Member 02)

## Overview

`lib/Service/AvailabilityService.php` computes free 15-minute-aligned slots per
resource per day by intersecting working hours, vacations, booked times, and
(seam for) calendar-synced blocks. Backed by the `availability-cache` schema from
member 01.

## Backend (per giant design.md)

**Dependencies:** `ObjectService` (query Resource/Booking/Service), `ICacheFactory`,
`IUserManager`. Calendar-block fetch is behind a seam (`getBlockedTimes` calls a
calendar provider injected later by member 10) so this member does not add a
CalDAV client (ADR-022).

**Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `computeAvailability` | `(string $resourceId, string $date, int $serviceDurationMinutes): array` | Free 15-min-aligned blocks that fit the duration |
| `getWorkingHours` | `(string $resourceId, string $weekDay): ?array` | `{openTime, closeTime}` |
| `getBlockedTimes` | `(string $resourceId, string $date): array` | Merge vacations + bookings + calendar blocks |
| `alignToSlots` | `(string $startTime, string $endTime, int $intervalMinutes): array` | Split into 15-min boundaries |
| `invalidateCache` | `(string $resourceId, string $date): void` | Delete cache entry |
| `getOrComputeCache` | `(string $resourceId, string $date): array` | Read cache; regenerate if missing/stale |

Buffers: a booking blocks `bufferBefore + duration + bufferAfter`.

All public methods carry `@spec ...#req-apt-003` PHPDoc. ObjectService calls use 3
positional args (ADR-015). ADR-005: no `$e->getMessage()` leakage.

## Security (ADR-005)

Read-only computation. No user-supplied IDs are trusted for cross-tenant reads;
resource/booking lookups go through ObjectService scoping.

## Tests

`tests/Unit/Service/AvailabilityServiceTest.php` — ≥4 methods, ObjectService and
calendar seam mocked, no real DB.
