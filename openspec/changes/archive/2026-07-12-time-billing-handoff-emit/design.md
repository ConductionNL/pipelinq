# Design: time-billing-handoff-emit

## Context

Verified at HEAD: the `timeEntry` schema (`lib/Settings/register.d/90-time-wip.json`)
carries `title, description, hours, date, status (draft|submitted|pending_approval|
approved|rejected), billingCategory, client, lead, project, approvedBy, approvedAt,
wipSyncStatus, wipSyncedAt` — no rate field on the entry (rates are shillinq's
rate-card domain). Approval already fires `TimeEntryApprovedEvent` →
`TimeApprovalListener` → `ShillinqWipService`, a one-way CloudEvent to a configured
webhook (`shillinq_wip_webhook_url`), idempotent via `wipSyncStatus`, with
`WipSyncNotifier` admin notification on failure; `ShillinqApService` mirrors this for
expenses. The invoice handoff itself is a deep-link only (`shillinq_app_url` admin
setting + a manifest handoff to `/index.php/apps/shillinq/`), and
`openspec/manifest.yaml` marks the real emit blocked-on-prereq. The prereq shipped:
**shillinq change `time-expense-invoice-intake` (merged to shillinq development,
commit `aa45e33b`, PR #386)** exposes the session-authenticated same-instance
endpoint `POST /apps/shillinq/api/billing/time-intake` (contract in the proposal).
The retry-job pattern to mirror is `PosRetryBackoffJob` (TimedJob, 15-min poll,
bounded attempts → `failed`). The `client` schema has **no** external shillinq
reference today.

**Cross-repo dependency:** this change consumes the shillinq intake endpoint above.
`depends_on:` stays empty (same-repo only); the dependency is recorded here and in
the proposal. If the intake contract changes in shillinq before this lands, this
design must be re-verified against it.

## Goals / Non-Goals

**Goals:**
- Turn approved hours into a shillinq draft invoice with one action — batch per
  client + period, idempotent, traceable, retried.
- Keep the existing WIP CloudEvent sync and the deep-link fallback untouched.
- Same-instance only, session-authenticated — no new public surface, no secrets.

**Non-Goals:**
- No expense rows in the intake payload (shillinq accepts-but-ignores them this
  slice; pipelinq's expense AP CloudEvent flow is unchanged).
- No remote (cross-instance) shillinq support — the endpoint is session-auth
  same-instance by contract; `shillinq_app_url` remains deep-link-only.
- No approval workflow in pipelinq (time-approval-workflow's delegation stands).
- No rate management in pipelinq — `hourlyRate`/`rateRef` are passed as null in the
  MVP and shillinq's rate cards resolve pricing (a 422 on an unresolvable rate is
  surfaced, not solved here).

## Decisions

### Batch shape and trigger

Entries are billed as a **batch per client + period**, not per entry: the intake
mints one draft invoice per batch, and per-entry calls would mint one invoice per
time entry. The trigger is a manager-facing "Send to billing" action (controller
endpoint `POST /api/billing/handoff/{clientId}` with a period, `#[NoAdminRequired]`
+ a permission check, declared in `appinfo/routes.php`) surfaced as a manifest
action on the time/billing surface. Emitting synchronously in the acting user's
request keeps a Nextcloud session available, which the intake requires for its
server-side tenant resolution. Auto-emit-on-approval is rejected for the MVP: single
approvals would create single-line invoices and approvals can trickle in over days —
the human-triggered period batch matches how invoicing actually runs. A further
verified reason: `TimeEntryApprovedEvent` currently has **no producer** — nothing in
`lib/` constructs it (the listener is registered in `Application.php:174` but the
wire is dangling), so hanging the emit on that event would build on an unfired
trigger; the manual action is the reliable seam.

### Same-instance call mechanics

`TimeBillingHandoffService` checks `IAppManager::isEnabledForUser('shillinq')` and
the `shillinq_time_intake_enabled` flag, then invokes the intake **in-process** via
the container (the app's established cross-app pattern — the same
`ContainerInterface->get(FQCN)` seam used for OpenRegister's ObjectService), calling
shillinq's intake service/controller in the current request so the session and
tenant context flow naturally. HTTP-to-self is rejected: it would require forwarding
session cookies server-side. When resolution fails (app absent/disabled), the
service degrades to `handoffAvailable() === false` and the UI keeps offering the
deep-link.

### Idempotency

`batchId` = UUIDv5 over (client UUID, period start/end, sorted entry UUIDs). The
same selection always produces the same `batchId`, so a retry after an ambiguous
failure replays (`duplicated:true`) instead of double-billing. This follows the
codebase's deterministic-key precedent — the POS bookkeeping raise uses
`SHA256(zReport.uuid + reportDate)` "so shillinq de-duplicates against any journal
it already created" (`lib/Repair/MigratePosBookkeepingToShillinq.php`). Entries with a
`billingInvoiceId` are excluded from selection, giving a second, independent guard.

### Traceability + retry

New `timeEntry` fields (incidental register overlay
`lib/Settings/register.d/91-time-billing-handoff.json`): `billingSyncStatus`
(`pending|synced|failed`), `billingBatchId`, `billingInvoiceId`. The service marks
`pending` + persists **before** the call (the `TimeApprovalListener` mark-then-act
pattern), then `synced` + invoice id on 200, or `failed` + `WipSyncNotifier`-style
admin notification on transport/5xx errors. `BillingHandoffRetryJob` (TimedJob,
mirroring `PosRetryBackoffJob`: 15-min poll, bounded attempts) re-sends failed
batches by `billingBatchId`. Because the job runs without a user session, the retry
path resolves the shillinq intake service with an explicit acting-user/tenant
context if shillinq's service accepts one; if it does not, the job only re-notifies
and the actual re-send stays a one-click manual action (session context) — this is
the main deferred question below. A 409 (in-flight) leaves `pending`; a 422 is
terminal-actionable (names the unmapped client/rate) and is never blind-retried.

### organisationRef mapping

Verified: `client` has no shillinq reference field. Add optional
`shillinqOrganisationRef` (string) to the `client` schema in the same overlay; the
admin/manager fills it per billable client (shillinq resolves it server-side; a bad
value is exactly the 422 case). A config-level "map by name" magic is rejected —
implicit matching misbills; an explicit per-client field is auditable.

### Units

`timeEntry.hours` (decimal) → intake `minutes` = `round(hours * 60)`. `externalId` =
the timeEntry UUID, so shillinq lines trace back to pipelinq entries.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| The intake emit (HTTP/cross-app call, batching, error mapping) | **Imperative** (`TimeBillingHandoffService`) | External-integration glue — the exact class ADR-003/ADR-031 keep in PHP; no `x-openregister-*` extension performs cross-app calls. |
| Retry of failed batches | **Imperative** (`BillingHandoffRetryJob`, TimedJob) | ADR-031's job carve-out: the work orchestrates an external system (shillinq) that a derived field cannot express; n8n `ScheduledWorkflow` is rejected because the call must run same-instance in-process for tenant resolution. Mirrors the existing `PosRetryBackoffJob`. |
| Handoff state on entries (`billingSyncStatus` etc.) | **Declarative** (schema overlay properties) | Plain persisted fields on the existing `timeEntry` schema — a register patch, no service-side storage. |
| "Un-billed approved hours per client" selection | **Imperative query in the service** | Multi-field filter (`status=approved`, empty `billingInvoiceId`, period window) feeding a side-effecting batch — not a stored aggregation; computed at emit time via `ObjectService->findAll`. |
| Approval lifecycle | **Unchanged** | Approval stays exactly as-is (`TimeEntryApprovedEvent` + WIP sync); this change adds a downstream leg only. |

## Seed Data

The overlay modifies the `timeEntry` and `client` schemas, so seed rows are updated
in the same fragment to keep a fresh install verifiable across the standard
archetypes:

- **Municipality** — client "Gemeente Voorbeeld" with
  `shillinqOrganisationRef: "00000000-0000-0000-0000-000000000000"` (obvious
  placeholder — the admin replaces it with the shillinq customer id); two `approved`
  time entries (e.g. 6.5 h "KCC inrichting werkplekprofielen", 3 h
  "Kennisbank redactie") in the same month, `billingSyncStatus` unset → one
  batch → one draft invoice.
- **Consultancy** — client "Meridiaan Advies B.V." with a placeholder
  `shillinqOrganisationRef`; one `approved` entry 8 h "Data-governance assessment"
  already carrying `billingSyncStatus: "synced"`, `billingBatchId` +
  `billingInvoiceId` nil-UUID placeholders — demonstrating the already-billed
  exclusion.
- **Travel agency** — client "Zonnereizen" **without** a `shillinqOrganisationRef`
  and one `approved` entry 2 h "Boekingsflow support" — the seeded 422/unmapped
  case the UI must surface actionably.

All example ids in seed/docs use the nil UUID
`00000000-0000-0000-0000-000000000000`; no realistic-looking references.

## Risks / Trade-offs

- **[Cross-repo contract drift]** → The intake contract is pinned in the proposal
  (from shillinq commit `aa45e33b`); the implementation must re-verify against
  shillinq HEAD at apply time, and the unit tests encode the request/response shape
  so drift fails loudly.
- **[No session in the retry job]** → See the retry decision: idempotent replay
  makes retries safe, but tenant resolution in job context depends on shillinq's
  service seam. Deferred question; the manual re-send action is the guaranteed path.
- **[Double representation: WIP CloudEvent + intake]** → Both legs are kept: WIP
  sync is a running ledger signal, the intake is the invoicing act. shillinq owns
  reconciling them; pipelinq's `billingInvoiceId` makes the invoiced set explicit.
- **[Unmapped clients stall billing]** → Seeded + specced as an actionable 422
  message naming the client; the mapping field is on the client record where the
  manager already works.
- **[Partial-batch ambiguity on timeout]** → mark-pending-before-call + deterministic
  `batchId` + `duplicated:true` replay make the recovery path safe and observable.

## Migration Plan

Additive: overlay fields, new service/job/route/action, flag default **off**.
Enabling requires shillinq ≥ the intake release on the same instance and per-client
`shillinqOrganisationRef` mappings. Rollback = disable the flag (deep-link continues
to work); overlay fields are inert when unused.

## Open Questions

- Does shillinq's intake service accept an explicit acting-user/tenant parameter so
  `BillingHandoffRetryJob` can re-send without a session? (Provisional: retries
  re-notify + a one-click manual re-send in session context; automatic job re-send
  lands once the seam is confirmed with the shillinq team.)
- Should `rateCardId` be selectable at send time (per batch) or always null in the
  MVP with shillinq resolving defaults? (Provisional: always null; shillinq's rate
  cards decide.)
- Currency: fixed `EUR` or read from pipelinq's `@config.currency`? (Provisional:
  read the app's configured currency, defaulting to EUR.)
