# Design: time-approval-workflow (hand approval + invoicing to shillinq)

**status: pr-created**

## Architecture

This change owns **no implementation in Pipelinq**. It is a re-pointing /
dependency declaration: approval-before-billing and invoicing are shillinq's
responsibility.

```
[ time-tracker leaf ]  capture hours        (time-entry-core)
        │  links hours to Pipelinq objects via OR integration link table
        ▼
[ shillinq ]           submit → approve → reject → lock → edit-request → invoice
```

### What Pipelinq owns

- Nothing in the approval lifecycle.
- Optionally, a manifest deep-link (menu entry / detail-page action) to
  shillinq's approval inbox.

### What shillinq owns (out of scope here, recorded as the target)

- `timesheetPeriod` / approval state machine.
- Submit / approve / reject / lock / edit-request.
- Invoicing + rate management + WIP balance.

## Removed from the prior draft

| Prior bespoke artefact | New owner |
|---|---|
| `timesheetPeriod` schema | shillinq |
| `timesheetEditRequest` schema | shillinq |
| `TimesheetService` (state machine) | shillinq |
| `TimesheetController` | shillinq |
| `TimesheetSubmit.vue` / `TimesheetApprovalInbox.vue` / `TimesheetApprovalDetail.vue` / `TimesheetEditRequestDialog.vue` | shillinq |
| Approval/locking Nextcloud notifications | shillinq |
| Seed `timesheetPeriod` / `timesheetEditRequest` objects | shillinq |

## How shillinq reaches the hours

Time entries are captured by the time-tracker leaf and linked to Pipelinq
objects via OR integration link tables (`openregister_*_links`). Shillinq reads
those links — or consumes the existing WIP-sync integration
(`pipelinq-time-to-shillinq-wip`) — to drive approval and billing. No
approval/locking status lives on the Pipelinq side.

## Knock-on: pipelinq-time-to-shillinq-wip

That change was designed around an in-pipelinq `TimeEntryApprovedEvent`. With
approval moving to shillinq, the WIP accrual happens inside shillinq on its own
approval transition; the cross-app event inverts. This design flags it for the
maintainer to re-point or archive the WIP-sync change separately — it is not
modified by this change to keep the migration bounded (ADR-032).

## Risks

- Low for Pipelinq (no code owned). The lifecycle complexity and its risks move
  to shillinq, where the billing domain already lives.
- Dependency risk: until shillinq exposes the approval surface, the only
  user-facing gap in Pipelinq is the absence of an approval screen — which is
  intentional.
