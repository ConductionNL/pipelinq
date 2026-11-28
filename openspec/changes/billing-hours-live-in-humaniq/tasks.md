# Tasks: billing-hours-live-in-humaniq

Moved here from `the-billing-time-entry-is-a-satellite` (its tasks 5.1 and 5.2), which named this
as the next change.

## 1. The hours live once

- [ ] 1.1 Move `hours`, `date`, `user` and `description` onto humaniq's `TimeEntry` so they live
      once, and make `BillingCategoryWidget` a `requiredApp: humaniq` widget that joins the two:
      the same pattern this app already uses to read planninq's `project` (REQ-TEC-032).

## 2. Existing rows

- [ ] 2.1 Migrate existing rows: one humaniq `TimeEntry` per billing line, and the line's
      `timeEntry` pointed at it (REQ-TEC-033).
