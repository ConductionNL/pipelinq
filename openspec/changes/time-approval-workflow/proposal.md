# Proposal: time-approval-workflow (hand approval + invoicing to shillinq)

## Why

The original draft of this change proposed building a full timesheet
submit/approve/lock lifecycle **inside Pipelinq**: two new OR schemas
(`timesheetPeriod`, `timesheetEditRequest`), a `TimesheetService` state machine,
a `TimesheetController`, four approval Vue views, and Nextcloud notifications.

Two facts make that the wrong home:

1. **The time-tracker leaf explicitly excludes approval + invoicing.** Its
   proposal states the out-of-scope items are "Invoicing; approval workflows
   (those belong in a separate billing app); rate management." Pipelinq consumes
   that leaf for capture (see `time-entry-core`).
2. **hydra ADR-022** forbids an app building a parallel mechanism for a
   capability that belongs to another part of the fleet. Approval-before-billing
   and invoicing are **shillinq's** domain — shillinq already owns the billing
   ledger and WIP balance (see `pipelinq-time-to-shillinq-wip`,
   `pipelinq-project-to-shillinq-ledger`).

The user decision: **time-tracker leaf for capture, shillinq for billing.**
Approval is the gate before billing, so it belongs with billing in shillinq, not
in Pipelinq.

This change is therefore **re-pointed from "build approval in Pipelinq" to
"hand approval + invoicing to shillinq"**. Pipelinq builds no timesheet schema,
no state machine, and no approval UI. It declares shillinq as the owner of the
submit → approve → lock → invoice lifecycle and ensures captured hours (from the
time-tracker leaf) are reachable by shillinq.

## What Changes

### Remove the in-pipelinq approval subsystem from scope

1. **No `timesheetPeriod` / `timesheetEditRequest` schemas** in Pipelinq.
2. **No `TimesheetService`, no `TimesheetController`, no approval Vue views**
   (`TimesheetSubmit`, `TimesheetApprovalInbox`, `TimesheetApprovalDetail`,
   `TimesheetEditRequestDialog`).
3. **No approval/locking notifications** authored in Pipelinq.

### Hand the lifecycle to shillinq (pointer + dependency)

4. **Shillinq owns submit / approve / reject / lock / edit-request / invoicing.**
   This change records that ownership and depends on shillinq exposing the
   approval + billing surface. Pipelinq links to it rather than re-implementing.
5. **Captured hours are reachable by shillinq.** Time entries captured via the
   time-tracker leaf are linked to Pipelinq objects through OR integration link
   tables; shillinq reads those links (or receives them via the existing
   WIP-sync integration) to drive approval + billing. No pipelinq-side approval
   state is introduced.
6. **Pipelinq surfaces a link to shillinq's approval inbox** where useful (e.g.
   a manifest menu entry / detail-page action that deep-links to shillinq), not
   an in-app approval screen.

## Out of Scope

- Hour capture — owned by the time-tracker leaf (see `time-entry-core`).
- Timesheet submission, approval, rejection, period-locking, edit-requests,
  invoicing, rate management — all owned by **shillinq**.
- Building any approval schema, state machine, controller, or view in Pipelinq.

## Interaction with existing changes

- **`pipelinq-time-to-shillinq-wip`** assumed Pipelinq emitted a
  `TimeEntryApprovedEvent` on an in-app approval. With approval moving to
  shillinq, that change's trigger inverts: shillinq approves, then accrues WIP
  internally. The WIP-sync change SHOULD be re-pointed or archived as a
  follow-up (flagged for the maintainer; tracked separately, not in this change).

## Impact

- **New schemas**: 0.
- **New backend/frontend files**: 0 approval logic.
- **Modified files**: `src/manifest.json` (optional deep-link to shillinq's
  approval inbox).
- **Removed from prior draft**: 2 schemas, `TimesheetService`,
  `TimesheetController`, 4 Vue views, approval notifications, seed data.
- **Dependency**: shillinq must expose the approval + invoicing surface for
  time captured against Pipelinq objects.
- **Risk**: Low for Pipelinq (no code owned here); the lifecycle risk moves to
  shillinq.
