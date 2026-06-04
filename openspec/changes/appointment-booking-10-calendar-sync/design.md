# Design: Appointment Booking — Calendar Sync (Member 10)

## Overview

Fill member 02's calendar seam and push bookings to staff calendars — all through
the OR `calendar` leaf via `email-calendar-sync`. No pipelinq-local CalDAV (ADR-022).

## Backend (per giant design.md)

### Block fetching (REQ-APT-018, ingest)
- `AvailabilityService::getBlockedTimes` calls the leaf's block API
  (`CalendarSyncService::getBlockedTimes`) for resources with a `calendarSyncId`.
- When the leaf syncs a calendar (every 5 min), pipelinq invalidates the
  AvailabilityCache for affected resource/dates within 5 minutes.

### Booking push (REQ-APT-018, write)
- On Booking status → confirmed: create a VEVENT in the staff resource's calendar
  **through the leaf's create API** with summary = customer + service, description =
  service details + deep-link back to the Booking, attendees = staff email
  (from `Resource.userId` → Nextcloud user email). Event UID derived from Booking.id.
- Reschedule moves (not duplicates) the VEVENT.

### AvailabilityCacheRefreshJob (`lib/BackgroundJob/AvailabilityCacheRefreshJob.php`)
- `TimedJob`, `setInterval(3600)` (1 hour).
- `run()`: iterate active Resources, `AvailabilityService::invalidateCache()` for
  today+30 days. Catch per-resource errors, log, continue.
- Register in `appinfo/info.xml` `<background-jobs>`.

## Anti-pattern guard (ADR-022)

No CalDAV client, no `X-PIPELINQ-*` VEVENT properties, no parallel link table. All
calendar I/O is the leaf's.

## Tests

Unit tests: getBlockedTimes merges leaf blocks; confirmed booking triggers a leaf
VEVENT create; refresh job iterates resources and invalidates cache. Mock the
calendar leaf + ObjectService.
