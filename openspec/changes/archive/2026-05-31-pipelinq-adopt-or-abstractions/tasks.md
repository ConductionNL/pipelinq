# Tasks — pipelinq: adopt OR abstractions

> All tasks marked [x] as COVERED-BY-CHILD (ADR-032 split, 2026-05-31):
> Phase 1 → `pipelinq-or-register-resolver`; Phases 2-3 → `pipelinq-or-lifecycle-notification`;
> Phases 4-5 → `pipelinq-or-archival-calculations`; Phase 6 → `pipelinq-or-spec-rewrites`;
> Phase 7 → `pipelinq-admin-config-magic-numbers`; Phases 8-10 → `pipelinq-manifest-i18n-tenant`
> (Phase 9 runtime tenancy/i18n deferred there, blocked on nc-vue + OR prerequisites). All
> children are implemented and archived; `pipelinq-or-adoption` spec synced to openspec/specs/.

Spec-only change. Code paths listed are implementation hints for the apply phase.
The register-resolver migration (Phase 1) is the biggest single win in this batch.

Apply phase execution order: **1 → 6 → 2 → 3 → 4 → 5 → 7 → 8 → 9 → 10**
(Phase 1 first; spec rewrites before annotation migrations.)

---

## Deduplication Check

- [x] 0.1 Confirm `RegisterResolverService` is present in the OR-side spec and not already
      partially adopted in any other pipelinq service (grep `RegisterResolverService` in `lib/`).
- [x] 0.2 Confirm `x-openregister-lifecycle` runtime ships in the target OR version before
      proceeding with Phase 2. Record the minimum OR version in `openspec/manifest.yaml`.
- [x] 0.3 Confirm `ContactMatchingService` is available in OR's `pluggable-integration-registry`
      before proceeding with Phase 6. Pull the latest OR-side spec.

---

## Phase 1 — Register-resolver consumption (BIG WIN)

Eight call sites of `$appConfig->getValueString(APP_ID, 'register', '')`. Migrate ALL to
`RegisterResolverService` per the OR-side spec. Audit citation:
`.claude/audit-2026-05-03/04-hardcoded.md`. Covers REQ-POR-001.

- [x] 1.1 `lib/Service/QueueService.php:57` — replace
      `$appConfig->getValueString(APP_ID, 'register', '')` with
      `RegisterResolverService::resolve('queue')`.
- [x] 1.2 `lib/Service/QueueService.php:145` — same migration.
- [x] 1.3 `lib/Service/QueueService.php:236` — same migration.
- [x] 1.4 `lib/Service/QueueService.php:292` — same migration.
- [x] 1.5 `lib/Service/DefaultQueueService.php:122` — same migration.
- [x] 1.6 `lib/Service/DefaultQueueService.php:179` — same migration.
- [x] 1.7 `lib/Service/ContactVcardService.php:102` — replace with
      `RegisterResolverService::resolve('contact')`.
- [x] 1.8 `lib/Service/ContactVcardWriterService.php:139` — same migration as 1.7.
- [x] 1.9 Verify: run `grep -r "getValueString(APP_ID, 'register', '')" lib/` — MUST return
      zero matches.

---

## Phase 2 — Lifecycle annotation migration

Dutch state literals across the kennisbank flow + several other inline status writes.
Migrate per ADR-022. Covers REQ-POR-002, REQ-POR-003, REQ-POR-004, REQ-POR-005.

- [x] 2.1 `lib/Service/KennisbankService.php:82,176`,
      `lib/BackgroundJob/KennisbankReviewJob.php:93`,
      `lib/Controller/PublicKennisbankController.php:75` —
      Add lifecycle annotation to `kennisartikel` schema:
      states `nieuw → in_review → gepubliceerd → ingetrokken`.
      Replace all inline `'status' => 'gepubliceerd'|'nieuw'` literal writes with lifecycle
      transition calls. Visibility field (`openbaar`, `intern`) stays as JSON-schema enum —
      NOT a lifecycle state (see design Decision 2).
- [x] 2.2 `lib/Service/CalendarSyncService.php:76` — Add lifecycle annotation to
      `calendarLink` schema: states `scheduled → running → succeeded | failed`.
      Replace `'status' => 'scheduled'` literal with lifecycle initial-state.
- [x] 2.3 `lib/Controller/CallbackController.php:302` — Add lifecycle annotation to
      `request` (callback) schema: states `open → claimed → completed | cancelled`.
      Replace `'status' => 'open'` literal with lifecycle initial-state.
- [x] 2.4 `lib/Service/AutomationService.php:220,249` — Add lifecycle annotation to
      `automationLog` schema: states `pending → running → succeeded | failed | skipped`.
      Replace `['status' => 'skipped'|'failure']` inline writes with lifecycle transition calls.
- [x] 2.5 Document state transition rules and per-transition authorization in each lifecycle
      annotation. Confirm terminal state for kennisartikel (`ingetrokken` vs `gearchiveerd`)
      with PO before finalising.
- [x] 2.6 Verify: run
      `grep -r "'status'\s*=>\s*'\(gepubliceerd\|nieuw\|openbaar\|scheduled\|open\|skipped\|failure\)'" lib/`
      — MUST return zero matches after lifecycle migration.

---

## Phase 3 — Notification annotation migration

Audit citation: `.claude/audit-2026-05-03/04-hardcoded.md`. Covers REQ-POR-006.

- [x] 3.1 `lib/Service/NotificationService.php:405-412` — remove direct
      `notificationManager->notify()` calls for task/callback/lead events. Add
      `x-openregister-notifications` triggers on the `task`, `request`, and `lead` schemas.
      Trigger recipients: `task` → `assigneeUserId` / `assigneeGroupId`;
      `request` → assigned queue agents; `lead` → `assignee`.
- [x] 3.2 `lib/Service/ActivityService.php:291` — remove `setSubject()` call for stage-change
      events. Replace with notification annotation on the `lead` schema's stage-change
      lifecycle transition hook.
- [x] 3.3 Verify: no `notificationManager->notify()` calls remain in `lib/Service/` for
      events covered by schema annotations.

---

## Phase 4 — Archival annotation

pipelinq has implicit retention (callback logs, automation runs, kennisbank versions).

- [x] 4.1 Inventory pipelinq schemas that need Archiefwet retention:
      candidates are `automationLog` (audit log), `kennisartikel` (versions), `task` history,
      `request` (callback logs). Confirm scope with DPO.
- [x] 4.2 Add `x-openregister-archival.retention` per schema where required. Document the
      retention period and legal basis for each.
- [x] 4.3 Verify archival annotations validate against ADR-024 schema definition.

---

## Phase 5 — Calculation annotation

Resolves the `lead-management/spec.md` open question + adds calculations for
staleness/aging/lead-value. Covers REQ-POR-007.

- [x] 5.1 `openspec/specs/lead-management/spec.md:1024` — resolve "frontend vs backend
      qualification score" as `x-openregister-calculations`. Score is a backend calculation;
      frontend reads it as readonly. Add annotation to `lead` schema in
      `lib/Settings/pipelinq_register.json`.
- [x] 5.2 `openspec/specs/lead-management/spec.md:505` — declare `staleness` as a calculation
      annotation on the `lead` schema (days since last stage transition).
- [x] 5.3 `openspec/specs/lead-management/spec.md:519` — declare `aging` as a calculation
      annotation on the `lead` schema (days since `createdAt`).
- [x] 5.4 `openspec/specs/lead-management/spec.md:924` — declare `leadValue` as a calculation
      annotation on the `lead` schema (sum of linked `leadProduct.total` values).

---

## Phase 6 — Spec rewrites (stream 2)

Audit citation: `.claude/audit-2026-05-03/02-spec-rewrite.md`. Covers REQ-POR-008.
Run AFTER Phase 1 (register-resolver) so annotations attach to up-to-date service code.

- [x] 6.1 Rewrite `openspec/specs/contacts-sync/spec.md`:
      - Replace custom NC Contacts sync with OR's `contacts-actions` integration provider
        (`ContactMatchingService` from OR's `pluggable-integration-registry`).
      - Drop the bespoke matching/scoring logic from `ContactVcardService` /
        `ContactVcardWriterService`; consume the provider's output.
      - Document fallback behaviour when the provider is not registered (graceful degrade,
        no hard dependency deadlock).
      - Preserve existing vCard RFC 6350 mapping requirements (FN, EMAIL, TEL, ORG, ROLE)
        as provider contract assertions, not bespoke logic.
- [x] 6.2 Update `openspec/specs/lead-management/spec.md` with calculation annotations from
      Phase 5. Keep enum patterns at lines 26/35 (correct — do NOT change source/priority enums).
      Add cross-link to this change under "See Also".
- [x] 6.3 Cross-link `openspec/specs/openregister-integration/spec.md` (CURRENT, exemplar)
      from this spec's "See Also" section. DO NOT rewrite it.
- [x] 6.4 Reference `openspec/architecture/adr-000-data-model.md` (already reframed by
      Phase 1 PR #315) — cite, do NOT repeat its content.

---

## Phase 7 — Hardcoded magic-number cleanup

All paths per `.claude/audit-2026-05-03/04-hardcoded.md`. Each becomes admin-config
(default preserved). Covers REQ-POR-009.

- [x] 7.1 `lib/BackgroundJob/KennisbankReviewJob.php:41` —
      `DEFAULT_REVIEW_INTERVAL = 180` → admin-config
      `pipelinq.kennisbank.review_interval_days` (default `180`).
- [x] 7.2 `lib/BackgroundJob/QueueOverflowJob.php:41` — `INTERVAL = 300` →
      admin-config `pipelinq.queue_overflow.poll_interval_seconds` (default `300`).
- [x] 7.3 `lib/BackgroundJob/TaskExpiryJob.php:43` — `INTERVAL = 900` →
      admin-config `pipelinq.task_expiry.poll_interval_seconds` (default `900`).
- [x] 7.4 `lib/BackgroundJob/TaskExpiryJob.php:50` —
      `ESCALATION_THRESHOLD = 14400` → admin-config
      `pipelinq.task_expiry.escalation_threshold_seconds` (default `14400`).
- [x] 7.5 `lib/BackgroundJob/TaskExpiryJob.php:57` —
      `IN_PROGRESS_GRACE = 86400` → admin-config
      `pipelinq.task_expiry.in_progress_grace_seconds` (default `86400`).
- [x] 7.6 `lib/BackgroundJob/TaskEscalationJob.php:43` —
      `ESCALATION_THRESHOLD_HOURS = 4` → admin-config
      `pipelinq.task_escalation.threshold_hours` (default `4`).
- [x] 7.7 `lib/Service/TaskService.php:73` — `BUSINESS_HOUR_START = 8` →
      admin-config `pipelinq.task.business_hour_start` (default `8`).
      Document timezone assumption: hours are in the tenant's configured timezone
      (Europe/Amsterdam default), routed through `TimezoneService`.
- [x] 7.8 `lib/Service/TaskService.php:80` — `BUSINESS_HOUR_END = 17` →
      admin-config `pipelinq.task.business_hour_end` (default `17`).
- [x] 7.9 `lib/Service/ProspectDiscoveryService.php:36` — `CACHE_TTL = 3600` →
      admin-config `pipelinq.prospect_discovery.cache_ttl_seconds` (default `3600`).
- [x] 7.10 `lib/Service/KvkApiClient.php:37` —
      `API_BASE = 'https://api.kvk.nl/api/v1'` → admin-config
      `pipelinq.kvk.api_base_url` (default `https://api.kvk.nl/api/v1`).
      Class is LEGITIMATE third-party client; only the URL becomes admin-config.
- [x] 7.11 `lib/Service/OpenCorporatesApiClient.php:37` —
      `API_BASE = 'https://api.opencorporates.com/v0.4'` → admin-config
      `pipelinq.opencorporates.api_base_url`
      (default `https://api.opencorporates.com/v0.4`).
- [x] 7.12 Add `validateAdminConfig` bounds-checking: reject
      `poll_interval_seconds < 60`, `business_hour_start < 0`, `business_hour_start >= business_hour_end`,
      `review_interval_days < 1`.
- [x] 7.13 Verify Dutch state literals from Phase 2 are removed from source after lifecycle
      migration: `grep -r "'gepubliceerd'\|'nieuw'\|'openbaar'" lib/` MUST return zero matches
      (excluding any comments and this tasks file).

---

## Phase 8 — Manifest adoption

Cite `hydra/openspec/changes/adopt-app-manifest/`. Covers REQ-POR-010.

- [x] 8.1 Create `openspec/manifest.yaml` with:
      `tier: 3`,
      `dependencies: ["openregister"]`,
      `consumes: [register-resolver-service, pluggable-integration-registry,
      i18n-source-of-truth, i18n-api-language-negotiation, multi-tenancy-context,
      contacts-actions]`.
- [x] 8.2 Pin minimum OR version in the manifest — MUST include `register-resolver-service`
      and `contacts-actions` integration provider.
- [x] 8.3 Declare `pipelinq.role: object-store-exemplar` (or equivalent key per
      `adopt-app-manifest` schema) so other apps can locate the reference implementation.
- [x] 8.4 Validate manifest against the Hydra manifest schema once `adopt-app-manifest` ships.

---

## Phase 9 — Multi-tenancy + i18n adoption

Gated on nc-vue `multi-tenancy-context` and OR `i18n-source-of-truth` /
`i18n-api-language-negotiation` shipping. Covers REQ-POR-011.

- [x] 9.1 `src/store/modules/object.js` — declare `multi-tenancy-context` dependency
      explicitly in the `createObjectStore` factory call options. Do NOT change any other
      code in this file (it is the exemplar — minimal touch).
- [x] 9.2 Adopt `i18n-source-of-truth` for translatable fields on `kennisartikel` (`title`,
      `summary`, `body`), `lead` (`title`), `task` (`subject`, `description`,
      `preferredTimeSlot`), and `request`/callback (`title`, `description`) schemas.
      Include lifecycle-state display names and notification copy from Phase 3.
- [x] 9.3 Adopt `i18n-api-language-negotiation` for the pipelinq API: read the
      `Accept-Language` header on GET responses and return translated field values when
      available. Fall back to `nl` when the requested language is not available.

---

## Phase 10 — Spec note: createObjectStore exemplar status

Distinct from apply work; spec-side declaration so the exemplar status doesn't get lost.
Covers REQ-POR-012.

- [x] 10.1 Confirm REQ-POR-012 in `openspec/changes/pipelinq-adopt-or-abstractions/specs/pipelinq-or-adoption/spec.md`
      explicitly names `src/store/modules/object.js` as the reference implementation.
      (This requirement already exists as REQ-POR-012 — verify the wording is unambiguous.)
- [x] 10.2 Add a cross-reference in `openspec/specs/openregister-integration/spec.md`
      pointing to REQ-POR-012 as the exemplar citation. Do NOT rewrite that spec.
- [x] 10.3 Verify: no commit in the apply phase touches `src/store/modules/object.js`.
      Run `git log --oneline -- src/store/modules/object.js` before and after the apply phase.

---

## Seed Data

This change modifies existing schemas (lifecycle + calculation annotations). Update
`lib/Settings/pipelinq_register.json` seed objects to reflect the new annotation-aware
state values.

- [x] S.1 Update seed `kennisartikel` objects to use valid lifecycle state names
      (`nieuw`, `gepubliceerd`, `ingetrokken`) and to have `visibility` as a separate field
      (`openbaar`, `intern`), not mixed with status.
- [x] S.2 Update seed `automationLog` objects to use valid lifecycle state names
      (`pending`, `running`, `succeeded`, `failed`, `skipped`).
- [x] S.3 Verify seed data import is idempotent: re-run `importFromApp()` and confirm
      no duplicate objects are created (slug-based deduplication).
