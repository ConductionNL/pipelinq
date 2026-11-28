# Proposal: billing-hours-live-in-humaniq

## Why

`the-billing-time-entry-is-a-satellite` (archived 28 November 2026) renamed this app's
`timeEntry` to `billingTimeEntry` and gave it a `timeEntry` reference to the humaniq booking it
bills. It left one thing out on purpose, in its section "What this does NOT do yet": the hours
still live in both places. `hours`, `date`, `user` and `description` stay on `billingTimeEntry`,
because `BillingCategoryWidget` sums `hours` per `billingCategory` and moving them makes that
widget read across two apps. That proposal named this as the next change.

## What changes

- `hours`, `date`, `user` and `description` live once, on humaniq's `TimeEntry`; the billing line
  keeps only its billing side (client, lead, billing category, approval state, WIP sync status,
  invoice batch) and its `timeEntry` reference.
- `BillingCategoryWidget` becomes a `requiredApp: humaniq` widget that joins the billing lines to
  their bookings, the pattern `src/manifest.json` already uses to read planninq's `project`
  (gate-55: without humaniq the widget shows its set-up state and sends no request).
- Existing rows are migrated: one humaniq `TimeEntry` per billing line, and the line's
  `timeEntry` pointed at it.

## Open before building

- humaniq's `TimeEntry` must carry `hours`, `date`, `user` and `description` in a shape a billing
  line can be written into (check humaniq's register; a missing field is built there first).
- The migration writes into humaniq's register from a pipelinq repair step. Whether a billing line
  without humaniq installed keeps its own hours until humaniq arrives, or the migration waits, is
  settled in `design.md` before section 2 is built.
