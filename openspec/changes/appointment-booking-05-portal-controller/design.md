# Design: Appointment Booking — Portal Controller (Member 05)

## Overview

`lib/Controller/PortalController.php` exposes the public booking API. All logic is
delegated to BookingService (member 04); the controller validates input, signs/
verifies links, and shapes JSON responses.

## Backend (per giant design.md)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/portal/services` | Public | List `bookableOnline: true` services |
| GET | `/portal/availability` | Public | `?serviceId=X&date=YYYY-MM-DD` → slots |
| POST | `/portal/book` | Public | Create booking; payment-redirect seam (member 08) |
| GET | `/portal/booking/{bookingId}` | Public | Fetch booking (signed link if provided) |
| POST | `/portal/reschedule` | Public | Reschedule via signed link |
| POST | `/portal/cancel` | Public | Cancel via signed link |

**Dependencies:** BookingService, `IURLGenerator` for signed deep-links.

## Security (ADR-005)

- All endpoints carry `#[PublicPage]` + appropriate CSRF posture (route-auth, ADR-016).
- `POST /portal/book` validates `customerName`, `email`, `phone`, `serviceId`,
  `startAt`; returns 400 on invalid email.
- Reschedule/cancel require an HMAC-SHA256 signature generated via `IURLGenerator`,
  expiring after 30 days; invalid/expired signature returns 410.
- Error responses use static messages, never `$e->getMessage()`.
- Handlers are thin (<10 lines), delegating to BookingService (ADR-003).
- Routes: specific routes before wildcards in `appinfo/routes.php`.

## Tests

`tests/Unit/Controller/PortalControllerTest.php` — ≥5 methods covering each endpoint
success, validation 400, not-found 404, and expired-link 410. Mocks BookingService.
