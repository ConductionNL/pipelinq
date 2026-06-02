# Design: Appointment Booking — Booking Service (Member 04)

## Overview

`lib/Service/BookingService.php` owns the booking lifecycle. It composes member 02
(availability) and member 03 (eligibility) to validate slots, and enforces the
status state machine with a full audit trail.

## Backend (per giant design.md)

**Dependencies:** `ObjectService`, `AvailabilityService` (member 02), eligibility
(member 03), `IUserSession`, `IUserManager`, `IAppConfig`. Email dispatch, payment
charging, and calendar push are injected seams (members 07/08/10) — called but
provided later.

**Methods:**

| Method | Signature |
|--------|-----------|
| `createBooking` | `(array $data, string $source): string` |
| `getAvailableSlots` | `(string $serviceId, string $date): array` |
| `confirmBooking` | `(string $bookingId, string $reason): void` |
| `rescheduleBooking` | `(string $bookingId, string $newStartAt): string` |
| `cancelBooking` | `(string $bookingId, string $reason, string $cancelledBy): void` |
| `markNoShow` | `(string $bookingId, string $staffUserId): void` |
| `completeBooking` | `(string $bookingId): void` |

## Status state machine (REQ-APT-013)

`pending-deposit → confirmed → completed | no-show | cancelled-*`; `rescheduled`
is a parallel terminal branch. Invalid directions (e.g. confirmed → pending-deposit)
are rejected. Every change appends `{status, changedAt, changedBy, reason}` to
`statusHistory`. `changedBy` uses `IUserSession::getUser()->getUID()` — never the
display name (ADR-005).

## Security (ADR-005)

`changedBy` is the UID. AvailabilityCache invalidation on every booking write.
No `$e->getMessage()` leakage. Cancellation policy is enforced server-side, not
trusted from the caller.

## Tests

`tests/Unit/Service/BookingServiceTest.php` — ≥6 methods: create (deposit/no-deposit),
reschedule preserves original, cancel within/after policy window, no-show increments
count + charges fee, invalid transition rejected. Mocks ObjectService,
AvailabilityService, and the payment seam.
