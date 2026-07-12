---
kind: code
depends_on: []
---

# Proposal: time-billing-handoff-emit

## Why

Pipelinq delegates the timesheet approve → invoice lifecycle to shillinq
(time-approval-workflow, hydra ADR-022), but the runtime handoff today is a
**deep-link only** (`shillinq_app_url` admin setting + a manifest handoff to
`/index.php/apps/shillinq/`) plus a one-way WIP CloudEvent — no approved hours ever
*arrive* in shillinq as invoiceable lines. `openspec/manifest.yaml` marks the real
emit **BLOCKED-ON-PREREQ** "until shillinq ships an approval/invoice route". That
prerequisite has now shipped: shillinq's `time-expense-invoice-intake` change
(merged to shillinq development, commit `aa45e33b`, PR #386) exposes
`POST /apps/shillinq/api/billing/time-intake`, turning a batch of approved time
entries into a draft invoice. Closing this loop answers user wish #4 — an
all-in-one CRM + invoicing flow (6 independent sources) — and the ecosystem
leaf-ownership insight "invoicing → shillinq": pipelinq captures and approves-links
hours, shillinq bills them, with no re-implementation on either side.

## Cross-repo dependency (prominent)

**This change depends on the shillinq repo change `time-expense-invoice-intake`**
(already merged to shillinq development — commit `aa45e33b`, PR #386), which provides
the intake endpoint and its contract. `depends_on:` in the frontmatter stays empty
because the Hydra chain mechanism is same-repo only; the dependency is recorded here
and in design.md instead. The endpoint contract (authoritative, from that change):
session-authenticated same-instance `POST /apps/shillinq/api/billing/time-intake`
(`#[NoAdminRequired]`, no public page; administration/tenant resolved server-side,
client-supplied administrationId ignored); request
`{batchId (uuid, idempotency key), organisationRef, currency, billingModel:
"t_and_m", period:{start,end}, rateCardId|null, projectRef, notes, entries:[
{externalId, date, minutes, description, hourlyRate|null, rateRef|null,
projectRef}], expenses:[] (ignored this slice)}`; response 200
`{invoiceId, invoiceNumber, status:"draft", lines, duplicated}` where a replayed
`batchId` returns the same invoice with `duplicated:true`; errors 400/401/409
(batch in-flight)/422 (unresolvable `rateRef` without inline rate, or unresolvable
`organisationRef`)/500.

## What Changes

- **Real emit** — a `TimeBillingHandoffService` that groups **approved** time
  entries (`timeEntry.status = approved`, from `register.d/90-time-wip.json`) per
  client + period into an intake batch and posts it to shillinq's intake, invoked
  same-instance via the app's established cross-app pattern (container-resolved
  service, guarded by `IAppManager` app-enabled detection), in the acting user's
  session so shillinq resolves the tenant server-side.
- **Idempotency** — `batchId` is derived deterministically from (client, period,
  sorted entry ids), so a re-send replays to the same draft invoice
  (`duplicated:true`) instead of double-billing.
- **Traceability + retry state** — new `billingSyncStatus`, `billingBatchId`,
  `billingInvoiceId` fields on the `timeEntry` schema (incidental register overlay);
  the returned `invoiceId` is stored against every entry in the batch. Failures mark
  `billingSyncStatus=failed` + notify admins (the `WipSyncNotifier` pattern); a
  `BillingHandoffRetryJob` TimedJob (the `PosRetryBackoffJob` pattern) re-attempts
  transient failures with backoff.
- **organisationRef mapping** — the `client` schema has **no** shillinq reference
  today (verified: `name,type,email,phone,address,website,industry,notes,contactsUid`);
  add a `shillinqOrganisationRef` property (incidental overlay) that admins fill per
  client; an unmapped client surfaces the 422 as an actionable per-batch error.
- **Feature flag + fallback** — a `shillinq_time_intake_enabled` admin setting
  (default off) gates the emit; when shillinq is absent/disabled or the flag is off,
  behaviour stays exactly today's deep-link handoff (`shillinq_app_url`), preserved
  unchanged.

## Capabilities

### New Capabilities
<!-- none: this extends the delegated handoff already specified -->

### Modified Capabilities
- `time-approval-workflow`: the shillinq handoff gains a real emit — approved time
  entries are batched and POSTed to shillinq's time-intake endpoint (idempotent,
  retried, traceable), superseding "deep-link only" while keeping the deep-link as
  the no-shillinq fallback.

## Impact

- **Code:** new `lib/Service/TimeBillingHandoffService.php`, new
  `lib/BackgroundJob/BillingHandoffRetryJob.php`, a controller action + route to
  trigger "Send to billing" for a client/period, admin-setting wiring, unit tests.
- **Config (incidental):** a `lib/Settings/register.d/` overlay adding
  `billingSyncStatus`/`billingBatchId`/`billingInvoiceId` to `timeEntry` and
  `shillinqOrganisationRef` to `client`; a manifest action on the time surface.
- **Cross-repo:** shillinq `time-expense-invoice-intake` (merged; see above).
- **Existing WIP sync:** untouched — the CloudEvent WIP dispatch
  (`ShillinqWipService`) and the AP expense dispatch (`ShillinqApService`) keep
  working as-is; this change adds the invoice-intake leg only.
- **Feature tier:** V1.
