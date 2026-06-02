# Tasks: Appointment Booking — Availability Service (Member 02)

## Section 1: AvailabilityService

- [ ] Implement `computeAvailability(string $resourceId, string $date, int $serviceDurationMinutes): array` — returns array of free blocks with startTime, endTime, durationMinutes
- [ ] Implement `getWorkingHours(string $resourceId, string $weekDay): ?array` — queries Resource.workingHours, returns {openTime, closeTime}
- [ ] Implement `getBlockedTimes(string $resourceId, string $date): array` — merges vacations, existing Bookings, calendar-synced blocks (calendar seam consumed by member 10)
- [ ] Implement `alignToSlots(string $startTime, string $endTime, int $intervalMinutes): array` — splits time range into 15-minute boundaries
- [ ] Implement `invalidateCache(string $resourceId, string $date): void` — deletes AvailabilityCache entry
- [ ] Implement `getOrComputeCache(string $resourceId, string $date): array` — check AvailabilityCache, regenerate if missing/stale
- [ ] Apply buffers: a booking blocks bufferBefore + duration + bufferAfter
- [ ] Use `ObjectService::findObjects()` to query Resource, Booking objects (3 positional args per ADR-015)
- [ ] Add `@spec ...#req-apt-003` PHPDoc to class and all public methods

## Section 2: Unit Tests

- [ ] Test `computeAvailability()` returns free slots when resource is available 09:00-17:00, duration 30min
- [ ] Test `computeAvailability()` excludes booked times
- [ ] Test `computeAvailability()` excludes vacation dates
- [ ] Test `alignToSlots()` returns 15-minute-aligned boundaries
- [ ] Test cache hit when available-cache is recent
- [ ] Test cache miss/regenerate when older than 24h
- [ ] Mock `ObjectService` and the calendar seam — no real DB
