# Proposal: time-entry-core (consume the time-tracker leaf)

## Why

Pipelinq needs time capture against clients, leads, projects and requests so
that downstream billing (shillinq) and profitability analysis are possible.
The original draft of this change proposed a bespoke time-tracking subsystem
inside Pipelinq: a `timeEntry` schema, a `TimerController`, a `TimeEntryService`
and five Vue views (`TimerWidget`, `TimeEntryList`, `ManualEntryDialog`,
`WeeklyGrid`, `TimeEntryDetail`).

Per **hydra ADR-022** (apps consume OpenRegister abstractions over local
duplication) this is now a leaf-consumption change. OpenRegister ships the
**time-tracker leaf** (`openregister/openspec/changes/integration-time-tracker/`)
which provides hour capture as a reusable integration: `TimeEntryService` +
`TimeController` + `TimeProvider` + `CnTimeTab` (quick-log form, entries grouped
by user/date, total hours) + `CnTimeCard` widget on all four surfaces. The leaf
is gated on the NC `timemanager` app and stores entries in an OR link table
(`time entries linked to object/user`).

Building a parallel timer/schema/service in Pipelinq is exactly the
"parallel mechanism" ADR-022 forbids: it would duplicate the leaf's data model,
drift from its audit/RBAC, and never inherit future capabilities. Pipelinq
therefore **consumes** the time-tracker leaf for capture and keeps only the
pipelinq-specific glue: declaring which entities a time entry attaches to.

The decision recorded by the user: **time-tracker leaf for capture, shillinq
for billing.** Approval + invoicing are explicitly out of scope for this change
and for the leaf (see `time-approval-workflow`, which hands that scope to
shillinq).

## What Changes

### Consume the time-tracker leaf (no bespoke time subsystem)

1. **Remove the bespoke time subsystem from scope** — no `timeEntry` schema, no
   `TimerController`, no `TimeEntryService`, no bespoke timer/grid/list/detail
   Vue views. The leaf owns capture, the entry data model, the timer state, the
   weekly grouping and the totals.
2. **Add `time-tracker` to `linkedTypes`** on the Pipelinq schemas that should
   support hour capture (`client`, `lead`, `request`, and any project/deal
   schema). This is the pipelinq-specific glue: it declares *which entities* a
   time entry attaches to. The leaf's tab + widget then auto-appear on those
   objects.
3. **Place the leaf widget + tab via the app manifest (ADR-024).** The leaf's
   `CnTimeCard` widget is added to the relevant detail pages' sidebar tabs
   (`pages[].sidebar`/`sidebarProps.tabs[]` with the time-tracker tab) and,
   where useful, to the dashboard (`type:"dashboard"` widget showing
   "today's hours"). The leaf's `CnTimeTab` is mounted via the integration
   registry, not as bespoke pipelinq components.
4. **Declare the `timemanager` dependency** in `src/manifest.json`
   `dependencies[]` so the install-time requirement is explicit.
5. **Link via OR integration endpoints.** Capture, listing and totals all flow
   through the leaf's `TimeController` / OR integration link endpoints
   (`openregister_*_links`), not through any pipelinq-owned route.

## Out of Scope

- Hour-capture data model, timer state, weekly grid, totals — owned by the
  time-tracker leaf, not Pipelinq.
- Approval / submission / period-locking — see `time-approval-workflow`
  (handed to shillinq).
- Invoicing / rate management — shillinq.
- Mobile / offline capture UX — see `time-entry-mobile` (also leaf-backed).

## Impact

- **New schemas**: 0 (capture model owned by the leaf).
- **Modified schemas**: Pipelinq register schemas gain `time-tracker` in
  `linkedTypes`.
- **Modified files**: `src/manifest.json` (tab/widget placement + `timemanager`
  dependency), `lib/Settings/pipelinq_register.json` (`linkedTypes` only).
- **Removed from prior draft**: `timeEntry` schema, `TimerController.php`,
  `TimeEntryService.php`, 5 bespoke Vue views.
- **Dependency**: OpenRegister `integration-time-tracker` leaf must be shipped;
  NC `timemanager` app installed at runtime.
- **Risk**: Low — no app-owned data layer; glue is declarative
  (`linkedTypes` + manifest).
