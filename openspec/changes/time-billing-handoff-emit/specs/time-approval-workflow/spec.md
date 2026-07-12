## ADDED Requirements

### Requirement: Approved time entries are emitted to shillinq's time-intake

Pipelinq SHALL emit approved time entries (`timeEntry.status = approved`) to
shillinq when the shillinq time-intake integration is enabled and the shillinq app
is installed and enabled on the same instance, using shillinq's
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
