# Tasks: Appointment Booking — Calendar Sync (Member 10)

> **Leaf-first (ADR-022).** All calendar I/O through the OR `calendar` leaf via email-calendar-sync. No CalDAV client, no `X-PIPELINQ-*` VEVENT props.

## Section 1: Block Fetching

- [x] In AvailabilityService::getBlockedTimes(): call the leaf's `CalendarSyncService::getBlockedTimes()` for resources with calendarSyncId
- [x] Merge returned blocks into the total blocked list
- [x] Invalidate AvailabilityCache within 5 minutes of a leaf calendar sync for affected resource/dates

## Section 2: Booking Push

- [x] After Booking status → confirmed: call the leaf to create a calendar event
- [x] Event details: summary = customer name + service name, description = service details + deep-link, attendees = staff resource's email
- [x] Event UID: unique identifier based on Booking.id
- [x] Fetch staff email from Resource.userId (Nextcloud user email)
- [x] Reschedule moves (not duplicates) the VEVENT

## Section 3: AvailabilityCacheRefreshJob

- [x] Extend `OCP\BackgroundJob\TimedJob` with `setInterval(3600)` (1 hour)
- [x] In `run()`: iterate all active Resources, call `AvailabilityService::invalidateCache()` for today+30 days
- [x] Catch per-resource errors, log, and continue
- [x] Register in `appinfo/info.xml` under `<background-jobs>`
- [x] Add `@spec` PHPDoc

## Section 4: Unit Tests

- [x] Test getBlockedTimes merges leaf-synced blocks
- [x] Test confirmed booking triggers a leaf VEVENT create
- [x] Test AvailabilityCacheRefreshJob iterates resources and invalidates cache
- [x] Mock the calendar leaf and ObjectService
