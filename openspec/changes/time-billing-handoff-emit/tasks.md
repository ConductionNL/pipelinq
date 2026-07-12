# Tasks: time-billing-handoff-emit

> Cross-repo dependency: consumes shillinq's `POST /apps/shillinq/api/billing/time-intake`
> (shillinq change `time-expense-invoice-intake`, merged — commit `aa45e33b`, PR #386).
> Re-verify the contract against shillinq HEAD before implementing.

## 1. Schema overlay + seed (register patch)

- [ ] 1.1 Add `lib/Settings/register.d/91-time-billing-handoff.json` overlay: `billingSyncStatus` (`pending|synced|failed`), `billingBatchId`, `billingInvoiceId` on `timeEntry`; optional `shillinqOrganisationRef` on `client`
- [ ] 1.2 Seed archetype data in the same overlay: municipality (2 approved un-billed entries + mapped org-ref placeholder), consultancy (1 already-synced entry with nil-UUID invoice refs), travel agency (approved entry, **no** org-ref → the 422 case)
  - files: `lib/Settings/register.d/91-time-billing-handoff.json`
  - Acceptance criteria:
    - Overlay merges cleanly on register import; existing `timeEntry`/`client` objects stay valid (fields optional)
    - All placeholder ids are nil UUIDs; no realistic-looking references

## 2. Handoff service

- [ ] 2.1 Create `lib/Service/TimeBillingHandoffService.php`: select approved, un-billed entries per client + period (`ObjectService->findAll`), build the intake payload (`batchId` = UUIDv5 over client+period+sorted entry UUIDs; `externalId` = entry UUID; `minutes` = round(hours×60); `hourlyRate`/`rateRef` null; `organisationRef` from `client.shillinqOrganisationRef`; `expenses: []`)
- [ ] 2.2 Implement the same-instance call: `IAppManager::isEnabledForUser('shillinq')` + `shillinq_time_intake_enabled` flag gate, container-resolved shillinq intake seam in the acting user's request; `handoffAvailable()` false → callers keep the deep-link
- [ ] 2.3 Implement outcome handling: mark entries `pending` before the call; on 200 store `synced` + `billingBatchId` + `billingInvoiceId` on every entry (honour `duplicated:true` replays); 409 → leave `pending`; 422 → actionable error naming the unmapped client/rate (no blind retry); 5xx/transport → `failed` + admin notification (WipSyncNotifier pattern)
  - files: `lib/Service/TimeBillingHandoffService.php`
  - Acceptance criteria:
    - Entries with a `billingInvoiceId` are never re-selected; the same selection always yields the same `batchId`
    - No secrets, no public route, no cross-instance HTTP

## 3. Trigger + retry

- [ ] 3.1 Add the "Send to billing" controller action + route (`POST /api/billing/handoff/{clientId}` with a period; `#[NoAdminRequired]` + permission guard) in `appinfo/routes.php`, and surface it as a manifest action on the time/billing surface with the deep-link fallback when `handoffAvailable()` is false
- [ ] 3.2 Create `lib/BackgroundJob/BillingHandoffRetryJob.php` (TimedJob, `PosRetryBackoffJob` pattern: 15-min poll, bounded attempts) re-attempting `failed` batches by `billingBatchId` via the intake's idempotency; if no sessionless tenant seam exists in shillinq, the job re-notifies and the re-send stays the manual action (design.md open question)
- [ ] 3.3 Add the `shillinq_time_intake_enabled` admin setting (default off; default registered in `SettingsService` like `shillinq_app_url`) and register the retry job declaratively in `appinfo/info.xml` `<background-jobs>` (the `TenderPostedRetryJob`/`PosRetryBackoffJob` pattern — not `IJobList->add`)
  - files: `lib/Controller/*`, `appinfo/routes.php`, `src/manifest.json`, `lib/BackgroundJob/BillingHandoffRetryJob.php`, `lib/Service/SettingsService.php`, `appinfo/info.xml`
  - Acceptance criteria:
    - Route declares its auth posture (route-auth gate) and guards the acting user's permission (no IDOR)
    - Flag off / shillinq absent ⇒ behaviour is byte-for-byte today's deep-link handoff

## 4. Tests + docs

- [ ] 4.1 Unit-test the service (batch selection excludes billed entries, deterministic `batchId`, minutes conversion, payload contract shape, 200/409/422/5xx outcome handling incl. `duplicated:true`) and the retry job, using the three archetype fixtures
  - files: `tests/Unit/Service/TimeBillingHandoffServiceTest.php`, `tests/Unit/BackgroundJob/BillingHandoffRetryJobTest.php`
  - Acceptance criteria:
    - The encoded request/response fixtures pin the shillinq contract so drift fails loudly
    - `composer check:strict` passes
- [ ] 4.2 Update `openspec/manifest.yaml` (remove the blocked-on-prereq note; record the consumed shillinq capability) and `docs/` to describe the emit + mapping workflow

## Acceptance criteria (change-level)

- Approved hours become a shillinq draft invoice via one idempotent, traceable, retried batch per client + period; replay never double-bills.
- The existing WIP CloudEvent sync and the deep-link fallback are unchanged; everything is gated behind an off-by-default flag and same-instance detection.
- The cross-repo contract is pinned in tests and re-verified against shillinq HEAD at apply time.
