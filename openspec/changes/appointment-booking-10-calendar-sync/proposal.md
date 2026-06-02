---
kind: code
depends_on: [appointment-booking-09-walkin-queue]
chain:
  - appointment-booking-01-data-model
  - appointment-booking-02-availability-service
  - appointment-booking-03-skill-routing-eligibility
  - appointment-booking-04-booking-service
  - appointment-booking-05-portal-controller
  - appointment-booking-06-portal-frontend
  - appointment-booking-07-email-confirmation-reminder
  - appointment-booking-08-deposit-payment
  - appointment-booking-09-walkin-queue
  - appointment-booking-10-calendar-sync
  - appointment-booking-11-admin-ui
  - appointment-booking-12-compliance-i18n
---

# Proposal: Appointment Booking — Calendar Sync (Member 10 of 12)

**Member 10 of 12 in the appointment-booking chain.** Predecessor:
`appointment-booking-09-walkin-queue`. This member fills the calendar seam from
member 02: react to staff calendar blocks synced by the `calendar` leaf and push
confirmed Bookings back to staff calendars — plus the `AvailabilityCacheRefreshJob`.

`kind: code` per ADR-032: integration glue + a timed job.

## Leaf-first boundary (ADR-022)

Calendar read/write is NOT implemented in pipelinq. Staff blocks are read and
Booking VEVENTs are written through the OR `calendar` leaf (`integration-calendar`),
mediated by `email-calendar-sync`. Pipelinq MUST NOT add a CalDAV client or
`X-PIPELINQ-*` VEVENT properties. Pipelinq owns only availability-cache invalidation
reacting to the leaf's synced blocks.

## Why (from the giant)

Staff calendars (Outlook, Google, iCloud) are the source of truth for blocked time.
Without ingesting it, the portal offers slots that collide with lunch/meetings.
Bookings must also appear on staff calendars so staff see them in their normal tool.

## What this member does

- `getBlockedTimes` (member 02 seam) consumes the leaf's synced blocks; cache
  invalidated within 5 minutes of a calendar change.
- On Booking confirmed: create a VEVENT in staff's calendar through the leaf's
  create API (summary, description, deep-link back to the Booking).
- `AvailabilityCacheRefreshJob` (`TimedJob`, hourly) invalidates cache for all active
  resources, today+30 days.

## Dependencies

- `appointment-booking-09-walkin-queue` (chain order; uses member 02 availability).
- `email-calendar-sync` / OR `calendar` leaf.
