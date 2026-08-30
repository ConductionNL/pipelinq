# Design: Appointment Booking — Email Confirmation & Reminder (Member 07)

## Overview

Compose confirmation and reminder email content and dispatch it through
`email-calendar-sync` (ADR-022 leaf-first). Pipelinq owns template content + signed
links; the leaf owns transport.

## Backend (per giant design.md)

### Confirmation email (REQ-APT-006)
- Triggered from `BookingService::confirmBooking` (member 04 seam) on create or
  deposit-paid.
- Subject (Dutch): "Uw afspraak is bevestigd: [Service], [Date] [Time]".
- Body: customer name, service, resource, date/time, location/notes, price.
- `.ics` attachment per RFC 5545 (DTSTART, DTEND, SUMMARY, DESCRIPTION, ATTENDEES).
- Signed deep-links (reschedule/cancel) generated via `IURLGenerator` HMAC-SHA256,
  30-day expiry (same scheme as member 05).
- Sets `confirmationSentAt`.

### Reminder email (REQ-APT-007)
- Subject (Dutch): "Herinnering: Uw afspraak morgen om [Time]".
- Body: brief reminder + same signed links.
- Optional SMS via openconnector if configured.

### ReminderDispatchJob (`lib/BackgroundJob/ReminderDispatchJob.php`)
- `TimedJob`, `setInterval(300)` (5 min).
- Query Bookings with `startAt` in [now+23h, now+24h], `status: confirmed`,
  `reminderSentAt: null`; dispatch reminder; set `reminderSentAt` on success.
- Catch per-booking errors, log, continue.
- Register in `appinfo/info.xml` `<background-jobs>`.

## i18n

All subjects/bodies/button text from `l10n/en.json` + `l10n/nl.json`; customer
locale (or Dutch default).

## Tests

`tests/Unit/BackgroundJob/ReminderDispatchJobTest.php` — ≥2 methods: queries and
sends; continues on per-booking errors. Mocks ObjectService + the dispatch seam.
