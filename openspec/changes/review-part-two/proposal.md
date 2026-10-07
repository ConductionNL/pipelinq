---
kind: code
depends_on: [review-finish]
---

# Proposal: review-part-two

## Summary

The booking page's timeline uses the library `timeline` widget instead of
pipelinq's own. It shows the booking's dated moments and every change from
the audit trail, so status changes stay visible. Product categories use their
real schema slug again once nextcloud-vue resolves slugs exactly.

## Motivation

Ruben's review of the pipelinq beta on 2026-10-06, item E6: the booking page
timeline should be the timeline widget dossiq uses. nextcloud-vue 2.65.0 ships
it as the `timeline` widget type. Pipelinq kept an interim
`BookingTimelineWidget` until then.

## What changes

- The booking page's Timeline tab is `type: timeline`. Its fields: created,
  deposit cleared, confirmation mail sent, reminder sent, starts, ends,
  cancelled, no-show fee charged. `auditTrail: true` adds each change with
  who made it, which covers status changes.
- The interim `BookingTimelineWidget` registry entry is removed.

## Out of scope

- The `statusHistory` reasons do not show in the library widget, which reads
  dated fields, related objects and the audit trail, not arrays on the object.
