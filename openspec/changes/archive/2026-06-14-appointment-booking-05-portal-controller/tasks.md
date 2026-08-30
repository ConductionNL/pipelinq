# Tasks: Appointment Booking — Portal Controller (Member 05)

## Section 1: PortalController

- [x] Implement `GET /portal/services` — return all Services with `bookableOnline: true` in JSON (no auth required)
- [x] Implement `GET /portal/availability?serviceId=X&date=YYYY-MM-DD` (no auth) — call `BookingService::getAvailableSlots()`, return free slots
- [x] Implement `POST /portal/book` (no auth) — validate customerName, email, phone, serviceId, startAt; create Booking; if depositRequired, initiate payment (seam, member 08); return confirmation JSON + payment redirect URL
- [x] Implement `GET /portal/booking/{bookingId}` (no auth) — fetch booking by UUID; validate signed link if provided
- [x] Implement `POST /portal/reschedule` (no auth) — validate signed link, call `BookingService::rescheduleBooking()`
- [x] Implement `POST /portal/cancel` (no auth) — validate signed link, call `BookingService::cancelBooking()`
- [x] All endpoints return JSON with appropriate HTTP status codes (200, 400, 404, 410)
- [x] Error responses use static messages, never $e->getMessage() (ADR-005)
- [x] Generate signed deep-links using `IURLGenerator` with HMAC-SHA256 signature (expires after 30 days)
- [x] All controller methods thin (<10 lines) — delegate to BookingService (ADR-003)
- [x] Add routes to `appinfo/routes.php` (specific routes before wildcards); declare auth posture per ADR-016
- [x] Add `@spec` PHPDoc

## Section 2: Unit Tests

- [x] Test `GET /portal/services` returns 200 with services list
- [x] Test `GET /portal/availability` returns 200 with slots for valid date
- [x] Test `POST /portal/book` returns 200 and creates booking for valid input
- [x] Test `POST /portal/book` returns 400 for invalid email
- [x] Test `POST /portal/reschedule` with valid signed link returns 200; expired/invalid signature returns 410
- [x] Test `/portal/cancel` similarly
- [x] Mock BookingService
