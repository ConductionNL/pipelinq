# Tasks: Appointment Booking — Portal Controller (Member 05)

## Section 1: PortalController

- [ ] Implement `GET /portal/services` — return all Services with `bookableOnline: true` in JSON (no auth required)
- [ ] Implement `GET /portal/availability?serviceId=X&date=YYYY-MM-DD` (no auth) — call `BookingService::getAvailableSlots()`, return free slots
- [ ] Implement `POST /portal/book` (no auth) — validate customerName, email, phone, serviceId, startAt; create Booking; if depositRequired, initiate payment (seam, member 08); return confirmation JSON + payment redirect URL
- [ ] Implement `GET /portal/booking/{bookingId}` (no auth) — fetch booking by UUID; validate signed link if provided
- [ ] Implement `POST /portal/reschedule` (no auth) — validate signed link, call `BookingService::rescheduleBooking()`
- [ ] Implement `POST /portal/cancel` (no auth) — validate signed link, call `BookingService::cancelBooking()`
- [ ] All endpoints return JSON with appropriate HTTP status codes (200, 400, 404, 410)
- [ ] Error responses use static messages, never $e->getMessage() (ADR-005)
- [ ] Generate signed deep-links using `IURLGenerator` with HMAC-SHA256 signature (expires after 30 days)
- [ ] All controller methods thin (<10 lines) — delegate to BookingService (ADR-003)
- [ ] Add routes to `appinfo/routes.php` (specific routes before wildcards); declare auth posture per ADR-016
- [ ] Add `@spec` PHPDoc

## Section 2: Unit Tests

- [ ] Test `GET /portal/services` returns 200 with services list
- [ ] Test `GET /portal/availability` returns 200 with slots for valid date
- [ ] Test `POST /portal/book` returns 200 and creates booking for valid input
- [ ] Test `POST /portal/book` returns 400 for invalid email
- [ ] Test `POST /portal/reschedule` with valid signed link returns 200; expired/invalid signature returns 410
- [ ] Test `/portal/cancel` similarly
- [ ] Mock BookingService
