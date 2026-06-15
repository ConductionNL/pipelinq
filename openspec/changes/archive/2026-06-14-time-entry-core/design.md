---
status: pr-created
---

# Design: time-entry-core (consume the time-tracker leaf)

## Architecture Overview

Per hydra ADR-022, Pipelinq does **not** own a time-capture subsystem. Hour
capture is provided by the OpenRegister **time-tracker leaf**
(`integration-time-tracker`), which ships:

- `TimeEntryService` + `TimeController` — backend capture + aggregation, wrapping
  the NC `timemanager` app.
- `TimeProvider` — registered against the integration registry.
- `CnTimeTab` — quick-log form (duration + description), entries grouped by
  user/date, total hours on the object.
- `CnTimeCard` — widget rendered on all four integration surfaces ("today's
  hours" on dashboard, breakdown by user/week on detail pages).
- An OR link table storing time entries linked to object + user.

Pipelinq consumes this leaf. The leaf appears on any OR object whose schema
declares `time-tracker` in `linkedTypes`. The only pipelinq-specific design
decision is **which entities accept hours** and **where the leaf surfaces in the
UI** (manifest placement).

## What Pipelinq owns (the glue)

1. **`linkedTypes` declarations** in `lib/Settings/pipelinq_register.json` —
   `client`, `lead`, `request`, and any project/deal schema gain `time-tracker`.
   Lookup/config schemas do not.
2. **Manifest placement (ADR-024)** in `src/manifest.json`:
   - Billable detail pages (`client`, `lead`, `request`) get the time-tracker
     leaf tab in their `sidebar` config, context-filtered to the object.
   - The dashboard page MAY carry the leaf's "today's hours" widget.
   - `dependencies[]` lists `timemanager` (and retains `openregister`).

## What Pipelinq no longer owns (removed from the prior draft)

| Prior bespoke artefact | Replaced by |
|---|---|
| `timeEntry` schema | Leaf's link-table entry model |
| `TimerController.php` | Leaf `TimeController` |
| `TimeEntryService.php` | Leaf `TimeEntryService` |
| `TimerWidget.vue` | Leaf `CnTimeCard` (dashboard surface) |
| `TimeEntryList.vue` / `WeeklyGrid.vue` / `TimeEntryDetail.vue` | Leaf `CnTimeTab` |
| `ManualEntryDialog.vue` | Leaf quick-log form |
| `timerStore.js` + timer API routes | Leaf integration link endpoints |

## Integration link path

Capture, listing and totals flow through the leaf's `TimeController` and the OR
integration link endpoints (`openregister_*_links`). Pipelinq registers no time
routes in `appinfo/routes.php`.

## Boundary with shillinq (billing)

The time-tracker leaf explicitly excludes approval workflows and invoicing
("those belong in a separate billing app" — leaf proposal). Per the user
decision **time-tracker leaf for capture, shillinq for billing**, approval,
submission, period-locking and invoicing are handed to shillinq via the
`time-approval-workflow` change. This change stops at capture.

## Risks

- Low. No app-owned data layer; glue is declarative (`linkedTypes` + manifest).
- Runtime dependency on the NC `timemanager` app + the shipped leaf; the leaf's
  provider `isEnabled()` degrades gracefully when `timemanager` is absent (the
  tab/widget simply do not render).
