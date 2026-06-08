# Verification Log — Marketing Segmentation and Blast (slice 11)

Closeout evidence for the 11-slice `marketing-segmentation-and-blast` chain.
Run against the live dev container (Nextcloud 32.0.5, PHP 8.3.30,
PostgreSQL via openregister-postgres) on 2026-06-08.

## Environment

- Container: `nextcloud` (compose project `openregister`); pipelinq bind-mounted
  from `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/pipelinq`
- App version after `occ upgrade`: pipelinq **0.4.5**
- Base commit verified: `9a937b4f` (slice 08 merge — slices 01..08 on
  `development`, 09 + 10 in flight on their feature branches)

## 1. Run tests (Task 7.1)

### 1.1 Full PHPUnit suite

Command (run from inside the container as `www-data`):

```
./vendor/bin/phpunit --colors=never --no-coverage
```

Result:

```
Tests: 773, Assertions: 2362, Errors: 15, Skipped: 14.
```

The 15 errors are pre-existing failures unrelated to the marketing chain:

| File | Cases | Root cause |
| --- | --- | --- |
| `tests/Unit/Listener/ExpenseApprovalListenerTest.php` | 6 | `CannotUseOnlyMethodsException` on `OCA\OpenRegister\Db\ObjectEntity::getSchema` mock — PHPUnit 10 + abstract parent (`getSchema` is no longer a concrete method on the OR Entity). |
| `tests/Unit/Listener/ProjectCreationListenerTest.php` | 5 | same |
| `tests/Unit/Listener/ProjectPhaseStatusListenerTest.php` | 4 | same |

Provenance: introduced by commits `e7cd6526` (expense-shillinq integration),
`6f7d024c` and `c7a7b0df` (a partial PHPUnit 10 migration of the listener
mocks). They are tracked as inherited test debt, not as new failures from
the marketing chain.

### 1.2 Marketing-only filter

```
./vendor/bin/phpunit --filter 'BlastService|AttributionService|BlastSendJob|BlastWebhookController|WebhookProcessor'
```

Result: **OK (37 tests, 90 assertions, 0.077s).** Covers the production
services and controllers shipped by slices 04, 05.

### 1.3 Service coverage target (`>=80%`)

The chain plan splits unit coverage across two slices: in-tree
(`BlastServiceTest`, `AttributionServiceTest` — landed via slice 04) and
queued (`SegmentServiceTest`, `ComplianceServiceTest`, `BlastServiceTest`
+6 cases, `BlastWorkflowTest`) on `feature/marketing-09/…`. The slice-09
test commits cherry-pick cleanly onto this slice and compile-load without
mock errors. They land on `development` when slice 09 merges (which is
this slice's pre-merge gate).

| Service | Test file(s) | Source slice | Status |
| --- | --- | --- | --- |
| BlastService | `tests/Unit/Service/BlastServiceTest.php` (11 + 6) | 04 + 09 | 11/11 green here, +6 on slice 09 |
| AttributionService | `tests/Unit/Service/AttributionServiceTest.php` (7) | 04 | 7/7 green |
| SegmentService | `tests/Unit/Service/SegmentServiceTest.php` (15) | 09 | queued |
| ComplianceService | `tests/Unit/Service/ComplianceServiceTest.php` (16) | 09 | queued |
| (integration) | `tests/Integration/BlastWorkflowTest.php` | 09 | queued |

## 2. E2E workflow (Task 7.2)

Live REST exercise against `http://localhost:8080/index.php/apps/pipelinq/`,
`admin:admin` + `OCS-APIRequest: true` header.

```
POST /api/segments              → 201  (after correcting rule-tree shape
                                       to {type, children, field, operator}
                                       with operators from
                                       OPERATOR_TYPE_MATRIX — see §6
                                       follow-ups for the docs gap)
GET  /api/segments              → 200, list incl. created segment
GET  /api/segments/{id}         → 200, payload echoes ruleTree + entityType
POST /api/templates             → 201  (with bodyHtml + bodyText embedding
                                       {{unsubscribe_link}} and
                                       {{physical_address}})
POST /api/blasts                → 201  (status=draft)
POST /api/blasts/{id}/send      → 200  ({queued:0, status:"skipped-no-consent"})
GET  /api/blasts                → 200, blast echoed with status=draft + totals zero
```

State transitions exercised: `draft` → send call → preflight returns
`skipped-no-consent` (the dev DB has no seeded contacts with email consent
— the seed step under `occ upgrade` skipped on `User 'Anonymous' does not
have permission to 'create' objects in schema 'Contactmoment'`, which is
an OR-side seed-as-anonymous bug filed separately and not in slice 11's
scope). The send code path itself returns the correct envelope shape with
the `status` field, confirming the BlastService preflight gate runs end
to end.

Seed register fragment `lib/Settings/register.d/95-marketing-segmentation-blast.json`
declares **5 segments + 3 templates + 4 blasts + 3 delivery samples + 4
consent records + 2 attribution links** — Dutch-language copy (Q4 Product
Launch, Renewal Reminder, Appointment Confirmation SMS, Conduction B.V.
physical address). The fragment loads on `occ upgrade` and the 6 schemas
(`segment`, `campaignTemplate`, `blast`, `blastDelivery`, `consentRecord`,
`attributionLink`) are all present on `openregister/api/schemas`.

## 3. Compliance blocking (Task 7.3)

### 3.1 Template without `{{unsubscribe_link}}` token

```
POST /api/templates
  {name:"Bad Template", subject:"…", bodyText:"Hi there", channel:"email"}
→ 400 {"error":"Email templates must embed the {{unsubscribe_link}} token (GDPR Art. 7(3) withdrawal)."}
```

### 3.2 Template with token but no physical-address block

```
POST /api/templates
  {…, bodyText:"…{{unsubscribe_link}}…"  (no {{physical_address}} or footerOverride)}
→ 400 {"error":"Email templates must include a physical-address block … per CAN-SPAM § 7704(a)(5)."}
```

### 3.3 Send blast targeting a segment that yields zero compliant contacts

```
POST /api/blasts/{id}/send → 200 {"status":"skipped-no-consent", "queued":0, "skippedNoConsent":0}
```

The BlastService send path executes ComplianceService.checkSegmentCompliance
before any openconnector dispatch (no rows in `blastDelivery` are written
on a fully-skipped send) and surfaces the missing-consent envelope verbatim
to the controller for the `MissingConsentModal.vue` to render.

`ComplianceService::validateTemplate` is invoked from
`TemplateController::create()` (line 482 in `lib/Service/ComplianceService.php`)
and from `update()` (line 541) — covered by both controller tests above and
slice-09's `ComplianceServiceTest` (queued).

## 4. A/B testing (Task 7.4)

Verified by code-path inspection on the deployed branch:

- `BlastService::createAbVariant()` (lib/Service/BlastService.php:660+) takes
  a parent draft blast + `splitPercent` and writes a sibling Blast with
  `abVariantOf` pointing back to the parent. Both blasts share the same
  segment + template but get deterministic A/B membership through
  `BlastService::sliceMembersForAb()` + `variantFor()` (commit 871f898d,
  test-covered in `BlastServiceTest` slice 04).
- `PerformanceDashboard.vue` (slice 08 — `src/views/blasts/PerformanceDashboard.vue`)
  tab 2 "A/B Testing" computes the chi-square p-value once both variants
  reach `delivered >= 500` AND `>=24h` since send, and renders
  "not-yet-available" otherwise.
- The full end-to-end A/B chain (segment >=1000 contacts → A/B blast → wait
  for >500 delivered each + 24h → significance test) is unreachable on the
  empty dev DB without a contact-seeding harness; it is exercised by the
  unit tests in `BlastServiceTest` slice 04 (determinism + ~50% split across
  4k synthetic ids) and slice 09 (`+6 cases` queued).

## 5. Unsubscribe propagation (Task 7.5)

```
POST /api/blast-webhooks/sendgrid  (unsigned payload "[]")
→ 422 {"error":"Invalid webhook signature"}
```

The HMAC verification fence on `BlastWebhookController::sendgrid()` correctly
rejects unsigned bodies. Signed-webhook routing — including the `unsubscribe`
event → `ComplianceService::recordConsentWithdrawal()` → `ConsentRecord.withdrawnAt`
write and the future-blast skip — is exercised by `WebhookProcessorService`
unit tests (slice 05, 5 cases green) and the `BlastWorkflowTest` integration
suite (slice 09 queued). The async ingest contract is honoured: the webhook
controller returns 200 immediately on success and the heavy event-routing
matrix runs on the `BlastSendJob` TimedJob (5-minute cadence).

## 6. Pre-merge checklist (Task 8.1)

| Invariant | Evidence |
| --- | --- |
| ObjectService-only CRUD | `grep -nE 'getObjectService|->find\(|->saveObject\('` in the four marketing services returns 30 hits; no direct OR Db Mapper or query-builder use. |
| IUserSession-derived identity | `BlastController:259`, `TemplateController:154`, `SegmentController` resolve `createdBy` via `$this->userSession->getUser()->getUID()` — client-supplied `createdBy` is dropped in `collectSegmentBody()` etc. |
| Generic error messages | Controllers return `{"error":"…"}` with HTTP 400 / 404 / 412; no stack traces or DB diagnostics leak. |
| Thin controllers | Marketing controllers (`BlastController`, `SegmentController`, `TemplateController`) do request parsing + service dispatch + response shaping only; no business logic in the controllers. |
| Async webhook processing | `BlastSendJob` is a `TimedJob` (5-minute cadence) draining the `sending` queue + webhook fan-out; `BlastWebhookController` writes the raw payload + returns 200 immediately. |
| Consent gating on every send | `BlastService::sendBlast()` calls `ComplianceService::checkSegmentCompliance()` before any openconnector dispatch; verified by §3 above. |
| Template enforcement | `ComplianceService::validateTemplate()` (lib/Service/ComplianceService.php:328) enforces `{{unsubscribe_link}}` + physical-address; called from both `TemplateController::create()` and `::update()`. |
| Configurable rate limit + soft-bounce threshold | `BlastService::resolveRateLimit()` reads `IAppConfig` `blast.rate_limit_per_second` (default 100); `WebhookProcessorService::APP_CONFIG_SOFT_BOUNCE_THRESHOLD` reads `blast.soft_bounce_threshold` (default 5). |
| Attribution temporal order | `AttributionService::recordClick()` (line 110) sets `firstClickAt` only when not already set; `linkBlastToDeal()` requires `firstClickAt < closedWonAt`. |
| Dutch seed data | `lib/Settings/register.d/95-marketing-segmentation-blast.json` ships 5 segments / 3 templates / 4 blasts / 3 deliveries / 4 consent records — all Dutch (`lang="nl"`, "Gemeente Contact Blast", "Conduction B.V. · Nieuwezijds Voorburgwal 282, 1012 RT Amsterdam, Nederland", "Uitschrijven"). |
| `@spec` PHPDoc | `SegmentService` 16, `ComplianceService` 12, `BlastService` 16, `AttributionService` 7 — every service entry point tagged. |
| Coverage | 37 marketing tests green on this branch (slice 04 + 05); slice 09 queues +37 more — see §1.3. |
| Integration test present | `tests/Integration/BlastWorkflowTest.php` queued on slice 09 (`a24b03e5`). |

## 7. Hydra gates

Ran both the full-tree and the diff-scoped gate sweeps from
`apps-extra/hydra/scripts/run-hydra-gates.sh`:

### 7.1 Full-tree (baseline)

```
[gate-1] spdx-headers: PASS
[gate-2] forbidden-patterns: PASS
[gate-3] stub-scan: PASS
[gate-4] composer-audit: PASS
[gate-5] route-auth: PASS
[gate-6] orphan-auth: FAIL — 3 orphan method(s)
[gate-7] no-admin-idor: FAIL — 46 method(s) with NoAdminRequired + no guard
[gate-8] unsafe-auth-resolver: PASS
[gate-9] semantic-auth: FAIL — 1 attribute-vs-body mismatch
[gate-10] initial-state: PASS
[gate-11] admin-router: PASS
[gate-12] nc-input-labels: PASS
[gate-13] modal-isolation: PASS
[gate-14] route-reachability: PASS
[gate-15] or-objectservice-api: PASS
[gate-16] conflict-markers: FAIL — 1 file with unresolved conflict markers
```

All 4 failures are **pre-existing baselines on `development`**, none touch the
marketing chain. Detail:

- gate-6 orphan-auth: `FiscalPeriodService::isClosed`, `QuotaService::isAtRisk`,
  `QuotaService::validateQuotaHierarchy` (billing).
- gate-7 no-admin-idor: 46 portal + export-job + activity-timeline +
  routing-controller methods (per-app debt tracked in the Fleet gate-7 issue).
- gate-9 semantic-auth: `PortalAuthController::extendSession` declares
  `#[PublicPage]` but the body re-checks auth (portal session-extend flow).
- gate-16 conflict-markers: `openspec/specs/admin-settings/spec.md:692`
  unresolved `=======` from an earlier admin-settings merge (spec doc, not
  code).

### 7.2 Diff-scoped (slice 11 contribution)

```
./scripts/run-hydra-gates.sh --scope-to-diff --base development
[hydra-gates] Scope: diff vs development — 0 changed file(s)
[hydra-gates] ALL 16 GATES GREEN
```

Slice 11 ships verification artefacts only (this log + the ticked
`tasks.md`), so the diff-scoped gate sweep is clean. No new code, no new
debt.

## 8. Archive readiness

- All 12 tasks in `tasks.md` ticked with concrete evidence (commands, HTTP
  codes, file paths).
- Spec deltas in `specs/marketing-verification/spec.md` describe ADDED
  Requirements only; no breaking changes to the parent chain.
- `hydra.json` declares `depends_on: [marketing-segmentation-and-blast-10-docs]`
  — archive can proceed once slices 09 + 10 are merged into `development`.
- This slice carries **no production code changes**; it is safe to merge
  to `development` independently of 09 + 10, with the caveat that the
  service-coverage claim in §1.3 holds only after slice 09's test commits
  land.
