# Tasks: Appointment Booking — Availability Service (Member 02)

## Section 1: AvailabilityService

- [x] Implement `computeAvailability(string $resourceId, string $date, int $serviceDurationMinutes): array` — returns array of free blocks with startTime, endTime, durationMinutes
- [x] Implement `getWorkingHours(string $resourceId, string $weekDay): ?array` — queries Resource.workingHours, returns {openTime, closeTime}
- [x] Implement `getBlockedTimes(string $resourceId, string $date): array` — merges vacations, existing Bookings, calendar-synced blocks (calendar seam consumed by member 10)
- [x] Implement `alignToSlots(string $startTime, string $endTime, int $intervalMinutes): array` — splits time range into 15-minute boundaries
- [x] Implement `invalidateCache(string $resourceId, string $date): void` — deletes AvailabilityCache entry
- [x] Implement `getOrComputeCache(string $resourceId, string $date): array` — check AvailabilityCache, regenerate if missing/stale
- [x] Apply buffers: a booking blocks bufferBefore + duration + bufferAfter
- [x] Use `ObjectService::findObjects()` to query Resource, Booking objects (3 positional args per ADR-015) — implemented via the canonical `findAll(config: ['filters' => …])` named-arg form per the real OR facade signature; the test stub at `tests/Stubs/Service/ObjectService.php` was updated to match the real signature.
- [x] Add `@spec ...#req-apt-003` PHPDoc to class and all public methods

## Section 2: Unit Tests

- [x] Test `computeAvailability()` returns free slots when resource is available 09:00-17:00, duration 30min
- [x] Test `computeAvailability()` excludes booked times
- [x] Test `computeAvailability()` excludes vacation dates
- [x] Test `alignToSlots()` returns 15-minute-aligned boundaries
- [x] Test cache hit when available-cache is recent
- [x] Test cache miss/regenerate when older than 24h
- [x] Mock `ObjectService` and the calendar seam — no real DB
