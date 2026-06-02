# Tasks: Appointment Booking — Email Confirmation & Reminder (Member 07)

> **Leaf-first (ADR-022).** Email transport via email-calendar-sync; compose content only.

## Section 1: Confirmation Email

- [ ] Subject: "Uw afspraak is bevestigd: [Service Name], [Date] [Time]" (Dutch)
- [ ] Body includes: customer name, service, resource, date/time, location/notes, price
- [ ] `.ics` attachment: calendar event per RFC 5545 with DTSTART, DTEND, SUMMARY, DESCRIPTION, ATTENDEES
- [ ] Signed deep-links: "Afspraak verplaatsen" → `/portal/reschedule?link=SIGNED_TOKEN&bookingId=X`
- [ ] Signed deep-links: "Afspraak annuleren" → `/portal/cancel?link=SIGNED_TOKEN&bookingId=X`
- [ ] Generate links using `IURLGenerator` with HMAC-SHA256 signature (expires 30 days)
- [ ] Dispatch via email-calendar-sync; set `confirmationSentAt`
- [ ] All strings localized (en + nl)

## Section 2: Reminder Email

- [ ] Subject: "Herinnering: Uw afspraak morgen om [Time]" (Dutch)
- [ ] Body: brief reminder, service, time, resource, price, same signed links as confirmation
- [ ] All strings localized

## Section 3: ReminderDispatchJob

- [ ] Extend `OCP\BackgroundJob\TimedJob` with `setInterval(300)` (5 minutes)
- [ ] In `run()`: query Bookings with `startAt` between now+23h and now+24h, `status: confirmed`, `reminderSentAt: null`
- [ ] For each: dispatch reminder email + optional SMS, set `reminderSentAt` on success
- [ ] Catch per-booking errors, log, continue
- [ ] Register in `appinfo/info.xml` under `<background-jobs>`
- [ ] Add `@spec` PHPDoc

## Section 4: Unit Tests

- [ ] Test ReminderDispatchJob queries bookings and dispatches reminders
- [ ] Test ReminderDispatchJob continues on per-booking errors
- [ ] Mock ObjectService and the dispatch seam
