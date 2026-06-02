# Tasks: Appointment Booking — Calendar Sync (Member 10)

> **Leaf-first (ADR-022).** All calendar I/O through the OR `calendar` leaf via email-calendar-sync. No CalDAV client, no `X-PIPELINQ-*` VEVENT props.

## Section 1: Block Fetching

- [ ] In AvailabilityService::getBlockedTimes(): call the leaf's `CalendarSyncService::getBlockedTimes()` for resources with calendarSyncId
- [ ] Merge returned blocks into the total blocked list
- [ ] Invalidate AvailabilityCache within 5 minutes of a leaf calendar sync for affected resource/dates

## Section 2: Booking Push

- [ ] After Booking status → confirmed: call the leaf to create a calendar event
- [ ] Event details: summary = customer name + service name, description = service details + deep-link, attendees = staff resource's email
- [ ] Event UID: unique identifier based on Booking.id
- [ ] Fetch staff email from Resource.userId (Nextcloud user email)
- [ ] Reschedule moves (not duplicates) the VEVENT

## Section 3: AvailabilityCacheRefreshJob

- [ ] Extend `OCP\BackgroundJob\TimedJob` with `setInterval(3600)` (1 hour)
- [ ] In `run()`: iterate all active Resources, call `AvailabilityService::invalidateCache()` for today+30 days
- [ ] Catch per-resource errors, log, and continue
- [ ] Register in `appinfo/info.xml` under `<background-jobs>`
- [ ] Add `@spec` PHPDoc

## Section 4: Unit Tests

- [ ] Test getBlockedTimes merges leaf-synced blocks
- [ ] Test confirmed booking triggers a leaf VEVENT create
- [ ] Test AvailabilityCacheRefreshJob iterates resources and invalidates cache
- [ ] Mock the calendar leaf and ObjectService
