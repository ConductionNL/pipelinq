# Tasks: Appointment Booking — Email Confirmation & Reminder (Member 07)

> **Leaf-first (ADR-022).** Email transport via email-calendar-sync; compose content only.

## Section 1: Confirmation Email

- [x] Subject: "Uw afspraak is bevestigd: [Service Name], [Date] [Time]" (Dutch)
- [x] Body includes: customer name, service, resource, date/time, location/notes, price
- [x] `.ics` attachment: calendar event per RFC 5545 with DTSTART, DTEND, SUMMARY, DESCRIPTION, ATTENDEES
- [x] Signed deep-links: "Afspraak verplaatsen" → `/portal/reschedule?link=SIGNED_TOKEN&bookingId=X`
- [x] Signed deep-links: "Afspraak annuleren" → `/portal/cancel?link=SIGNED_TOKEN&bookingId=X`
- [x] Generate links using `IURLGenerator` with HMAC-SHA256 signature (expires 30 days)
- [x] Dispatch via email-calendar-sync; set `confirmationSentAt`
- [x] All strings localized (en + nl)

## Section 2: Reminder Email

- [x] Subject: "Herinnering: Uw afspraak morgen om [Time]" (Dutch)
- [x] Body: brief reminder, service, time, resource, price, same signed links as confirmation
- [x] All strings localized

## Section 3: ReminderDispatchJob

- [x] Extend `OCP\BackgroundJob\TimedJob` with `setInterval(300)` (5 minutes)
- [x] In `run()`: query Bookings with `startAt` between now+23h and now+24h, `status: confirmed`, `reminderSentAt: null`
- [x] For each: dispatch reminder email + optional SMS, set `reminderSentAt` on success
- [x] Catch per-booking errors, log, continue
- [x] Register in `appinfo/info.xml` under `<background-jobs>`
- [x] Add `@spec` PHPDoc

## Section 4: Unit Tests

- [x] Test ReminderDispatchJob queries bookings and dispatches reminders
- [x] Test ReminderDispatchJob continues on per-booking errors
- [x] Mock ObjectService and the dispatch seam
