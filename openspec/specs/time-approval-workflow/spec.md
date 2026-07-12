---
status: done
---

# Time Approval Workflow Specification

## Purpose

The timesheet submit → approve → reject → lock → edit-request → invoice
lifecycle is **delegated to shillinq**, not built in Pipelinq. The time-tracker
leaf (`integration-time-tracker`) explicitly excludes approval + invoicing, and
**hydra ADR-022** forbids an app building a parallel mechanism for a capability
owned elsewhere in the fleet. Approval is the gate before billing, so it lives
with billing in shillinq (see shillinq `invoice-from-time-and-expense`,
`rate-card-management`, `wbso-uren-tagging-and-export`). Pipelinq captures hours
via the leaf, links them to its objects via OpenRegister integration link
tables. The handoff to shillinq's billing surface has a real emit: a
manager-triggered, idempotent "Send to billing" batches a client's approved,
un-billed entries per period and posts them same-instance to shillinq's
`time-expense-invoice-intake` endpoint, behind an off-by-default flag; the
existing deep-link remains the fallback when shillinq is absent/disabled or the
flag is off.

**OpenSpec changes**: [time-approval-workflow](../../changes/archive/2026-05-31-time-approval-workflow/) _(archived 2026-05-31)_, [time-billing-handoff-emit](../../changes/archive/2026-07-12-time-billing-handoff-emit/) _(archived 2026-07-12 — replaces the deep-link-only handoff with a real, idempotent batch emit to shillinq's `POST /apps/shillinq/api/billing/time-intake`; the delegation itself is unchanged)_

@e2e exclude delegation/architecture spec: the timesheet approve→lock→invoice lifecycle is owned by shillinq, NOT built in Pipelinq. Scenarios assert the absence of an approval subsystem, shillinq as lifecycle owner, OR-link/WIP sync, and a manifest deep-link (not an in-app approval view) — verified by source grep + manifest assertions; no Pipelinq-owned UI surface.

## Requirements

### Requirement: Approval and invoicing are owned by shillinq, not Pipelinq

Pipelinq SHALL NOT implement the timesheet submit → approve → reject → lock →
edit-request → invoice lifecycle; that lifecycle SHALL be owned by **shillinq**.
This follows the time-tracker leaf's explicit exclusion of approval + invoicing
and hydra ADR-022 (no parallel mechanism for a fleet capability owned
elsewhere).

#### Scenario: No approval subsystem ships in Pipelinq

- **GIVEN** the time-approval-workflow change is applied
- **THEN** Pipelinq SHALL define no `timesheetPeriod` or `timesheetEditRequest`
  schema
- **AND** Pipelinq SHALL define no `TimesheetService`, `TimesheetController`, or
  approval Vue views (`TimesheetSubmit`, `TimesheetApprovalInbox`,
  `TimesheetApprovalDetail`, `TimesheetEditRequestDialog`)
- **AND** Pipelinq SHALL author no approval, locking, or submission
  notifications.

#### Scenario: shillinq is declared as the lifecycle owner

- **GIVEN** hours captured against Pipelinq objects via the time-tracker leaf
- **WHEN** an approval-before-billing step is required
- **THEN** that step SHALL be performed in shillinq, which owns approval and
  invoicing
- **AND** Pipelinq SHALL depend on shillinq exposing that surface rather than
  re-implementing it.

### Requirement: Captured hours are reachable by shillinq

Time entries captured via the time-tracker leaf SHALL be reachable by shillinq
so it can drive approval and billing, without Pipelinq introducing any approval
state of its own.

#### Scenario: Hours flow to shillinq via OR links / WIP sync

- **GIVEN** a time entry captured by the leaf and linked to a Pipelinq object
  through the OR integration link table
- **WHEN** shillinq needs the hour data for approval/billing
- **THEN** shillinq SHALL read it via the OR link or the existing WIP-sync
  integration
- **AND** Pipelinq SHALL NOT hold an approval/locking status on the entry.

### Requirement: Pipelinq deep-links to shillinq's approval surface

Pipelinq SHALL NOT render an in-app approval screen; where it helps the user it
MAY instead surface a link to shillinq's approval inbox.

#### Scenario: Manifest provides a deep-link, not an in-app approval view

- **GIVEN** Pipelinq's `src/manifest.json`
- **WHEN** a user wants to review/approve hours
- **THEN** the manifest MAY expose a menu entry or detail-page action that
  deep-links to shillinq's approval inbox
- **AND** no `type` page in the manifest SHALL render a pipelinq-owned approval
  workflow.

### Requirement: Approved time entries are emitted to shillinq's time-intake

Pipelinq SHALL emit approved time entries (`timeEntry.status = approved`) to
shillinq when the shillinq time-intake integration is enabled and the shillinq
app is installed and enabled on the same instance, using shillinq's
`POST /apps/shillinq/api/billing/time-intake` endpoint as a batch per client +
period, using the intake contract from shillinq's `time-expense-invoice-intake`
change: `{batchId, organisationRef, currency, billingModel: "t_and_m",
period:{start,end}, rateCardId|null, projectRef, notes, entries:[...]}` with each
entry carrying `{externalId (the timeEntry UUID), date, minutes (hours × 60,
rounded), description, hourlyRate|null, rateRef|null, projectRef}`. The call SHALL
be made same-instance in the acting user's session so shillinq resolves the
administration/tenant server-side. Pipelinq SHALL NOT send expense rows in this
slice.

#### Scenario: Approved entries become a draft invoice

- **WHEN** a manager triggers "Send to billing" for a client's approved, un-billed entries in a period
- **THEN** Pipelinq posts one intake batch and receives `{invoiceId, invoiceNumber, status:"draft", lines, duplicated:false}`
- **AND** only entries with `status = approved` and no `billingInvoiceId` are included

#### Scenario: Emit requires shillinq present and the flag on

- **WHEN** the `shillinq_time_intake_enabled` setting is off, or the shillinq app is absent/disabled
- **THEN** no intake call is attempted and the existing deep-link handoff (`shillinq_app_url`) remains the offered path, unchanged

### Requirement: The intake emit is idempotent

The batch `batchId` SHALL be derived deterministically from the batch identity
(client, period, sorted entry UUIDs), so re-sending the same batch replays to the
same shillinq invoice (`duplicated: true`) and can never double-bill. Entries
already carrying a `billingInvoiceId` SHALL be excluded from new batches.

#### Scenario: Replay returns the same invoice

- **WHEN** the same batch is sent twice (e.g. after a timeout whose first attempt actually landed)
- **THEN** shillinq returns the same `invoiceId` with `duplicated: true` and Pipelinq records no second invoice reference

#### Scenario: A 409 in-flight batch is not duplicated

- **WHEN** the intake responds 409 (batch in-flight)
- **THEN** Pipelinq leaves the batch `pending` and retries later rather than minting a new `batchId`

### Requirement: Handoff outcome is traceable and failures are retried

Pipelinq SHALL record the handoff outcome on every entry in the batch:
`billingSyncStatus` (`pending` → `synced` | `failed`), `billingBatchId`, and — on
success — `billingInvoiceId` from the intake response. A transient failure SHALL
mark the entries `failed` and notify administrators (mirroring the WIP-sync
failure notification), and a background retry job SHALL re-attempt failed batches
with backoff, relying on the intake's idempotency. A 422 (unresolvable
`organisationRef` or `rateRef`) SHALL be surfaced as an actionable error naming
the unmapped client or rate, not retried blindly.

#### Scenario: Success stores the invoice reference

- **WHEN** an intake call succeeds
- **THEN** every entry in the batch is updated with `billingSyncStatus=synced`, the `billingBatchId`, and the returned `billingInvoiceId`

#### Scenario: Transient failure marks, notifies, and retries

- **WHEN** the intake call fails with a 5xx or a transport error
- **THEN** the entries are marked `billingSyncStatus=failed`, admins are notified, and the retry job re-attempts the same `batchId` later

#### Scenario: Unmapped client is actionable, not retried

- **WHEN** the intake responds 422 for an unresolvable `organisationRef`
- **THEN** the failure message names the client that lacks a `shillinqOrganisationRef` mapping and the batch is not blind-retried

### Requirement: Clients carry a shillinq organisation mapping

The `client` schema SHALL gain an optional `shillinqOrganisationRef` property
holding the shillinq-resolvable customer/organisation reference used as the
intake's `organisationRef`. The mapping is maintained by administrators/managers
on the client record; a client without a mapping cannot be billed through the
intake and surfaces the 422 scenario above.

#### Scenario: Mapped client resolves in shillinq

- **WHEN** a batch is built for a client with `shillinqOrganisationRef` set
- **THEN** the intake request's `organisationRef` carries that value and shillinq resolves it to its customer
