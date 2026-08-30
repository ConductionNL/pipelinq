# Tasks — pipelinq: adopt OR abstractions

Spec-only change. Code paths listed are implementation hints for the apply phase. The
register-resolver migration (Phase 1) is the biggest single win in this batch.

## Phase 1 — register-resolver consumption (BIG WIN)

Eight call sites of `$appConfig->getValueString(APP_ID, 'register', '')`. Migrate ALL to
`RegisterResolverService` per the OR-side spec. Audit citation:
`.claude/audit-2026-05-03/04-hardcoded.md`.

- [ ] 1.1 `lib/Service/QueueService.php:57` — replace
      `$appConfig->getValueString(APP_ID, 'register', '')` with
      `RegisterResolverService::resolve('queue')`.
- [ ] 1.2 `lib/Service/QueueService.php:145` — same migration.
- [ ] 1.3 `lib/Service/QueueService.php:236` — same migration.
- [ ] 1.4 `lib/Service/QueueService.php:292` — same migration.
- [ ] 1.5 `lib/Service/DefaultQueueService.php:122` — same migration.
- [ ] 1.6 `lib/Service/DefaultQueueService.php:179` — same migration.
- [ ] 1.7 `lib/Service/ContactVcardService.php:102` — replace with
      `RegisterResolverService::resolve('contact')`.
- [ ] 1.8 `lib/Service/ContactVcardWriterService.php:139` — same migration as 1.7.
- [ ] 1.9 Verify no remaining `getValueString(APP_ID, 'register', '')` matches in `lib/`
      after the migration.

## Phase 2 — lifecycle annotation migration

Dutch state literals across the kennisbank flow + several other inline status writes.
Migrate per ADR-022.

- [ ] 2.1 `lib/Service/KennisbankService.php:82,176`,
      `lib/BackgroundJob/KennisbankReviewJob.php:93`,
      `lib/Controller/PublicKennisbankController.php:75` — `'status' => 'gepubliceerd'`
      and `'nieuw'`, `'visibility' => 'openbaar'`. Define lifecycle states
      `nieuw → in_review → gepubliceerd → ingetrokken` on the kennisbank schema. Visibility
      stays as a separate field but its allowed values (`openbaar`, `intern`) become a
      JSON-schema enum, NOT a lifecycle (visibility is orthogonal to lifecycle).
- [ ] 2.2 `lib/Service/CalendarSyncService.php:76` — `'status' => 'scheduled'`. Define
      lifecycle states on the calendar-sync schema (`scheduled`, `running`, `succeeded`,
      `failed`).
- [ ] 2.3 `lib/Controller/CallbackController.php:302` — `'status' => 'open'`. Define
      lifecycle states on the callback schema (`open`, `claimed`, `completed`, `cancelled`).
- [ ] 2.4 `lib/Service/AutomationService.php:220,249` —
      `['status' => 'skipped'|'failure']`. Define lifecycle states on the automation-run
      schema (`pending`, `running`, `succeeded`, `failed`, `skipped`).
- [ ] 2.5 Document the state transition rules + per-transition authorization in each
      lifecycle annotation.

## Phase 3 — notification annotation migration

Audit citation: `04-hardcoded.md`.

- [ ] 3.1 `lib/Service/NotificationService.php:405-412` — direct
      `notificationManager->notify()` calls. Replace with
      `x-openregister-notifications` triggers on the relevant schemas (likely
      task/callback/lead).
- [ ] 3.2 `lib/Service/ActivityService.php:291` — `setSubject()` call. Same migration —
      activity events become notification triggers on lifecycle transitions.

## Phase 4 — archival annotation

pipelinq has implicit retention (callback logs, automation runs, kennisbank versions). The
audit didn't flag specific retention constants; this phase asks the apply phase to confirm
which schemas need archival.

- [ ] 4.1 Inventory pipelinq schemas that need Archiefwet retention (kennisbank versions,
      task history, callback logs). Confirm with the DPO.
- [ ] 4.2 Add `x-openregister-archival.retention` per schema where needed.

## Phase 5 — calculation annotation

Resolves the `lead-management/spec.md` open question + adds calculations for
staleness/aging/lead-value.

- [ ] 5.1 `openspec/specs/lead-management/spec.md:1024` — resolve "frontend vs backend
      qualification score" as `x-openregister-calculations`. Score is a backend
      calculation, frontend reads it.
- [ ] 5.2 `openspec/specs/lead-management/spec.md:505` — staleness as a calculation
      annotation.
- [ ] 5.3 `openspec/specs/lead-management/spec.md:519` — aging as a calculation annotation.
- [ ] 5.4 `openspec/specs/lead-management/spec.md:924` — lead-value as a calculation
      annotation.

## Phase 6 — spec rewrites (stream 2)

Audit citation: `.claude/audit-2026-05-03/02-spec-rewrite.md`.

- [ ] 6.1 Rewrite `openspec/specs/contacts-sync/spec.md`:
      - Replace custom NC Contacts sync with OR's `contacts-actions` integration provider
        (`ContactMatchingService`).
      - Drop the bespoke matching/scoring logic; consume the provider's output.
      - Document fallback behavior when the provider is not registered.
- [ ] 6.2 Update `openspec/specs/lead-management/spec.md` with calculation annotations from
      Phase 5. Keep enum patterns at lines 26/35 (correct).
- [ ] 6.3 Cross-link `openspec/specs/openregister-integration/spec.md` (CURRENT, exemplar)
      from this change's spec under "See Also". Do NOT rewrite it.
- [ ] 6.4 Reference `openspec/changes/archive/.../adr-000` (already reframed by Phase 1
      PR #315) — cite, do NOT repeat its content.

## Phase 7 — hardcoded magic-number cleanup

All paths per `.claude/audit-2026-05-03/04-hardcoded.md`. Each becomes admin-config (default
preserved).

- [ ] 7.1 `lib/BackgroundJob/KennisbankReviewJob.php:41` —
      `DEFAULT_REVIEW_INTERVAL = 180` (days?) → admin-config
      `pipelinq.kennisbank.review_interval_days` (default `180`).
- [ ] 7.2 `lib/BackgroundJob/QueueOverflowJob.php:41` — `INTERVAL = 300` (seconds) →
      admin-config `pipelinq.queue_overflow.poll_interval_seconds` (default `300`).
- [ ] 7.3 `lib/BackgroundJob/TaskExpiryJob.php:43` — `INTERVAL = 900` → admin-config
      `pipelinq.task_expiry.poll_interval_seconds` (default `900`).
- [ ] 7.4 `lib/BackgroundJob/TaskExpiryJob.php:50` —
      `ESCALATION_THRESHOLD = 14400` → admin-config
      `pipelinq.task_expiry.escalation_threshold_seconds` (default `14400`).
- [ ] 7.5 `lib/BackgroundJob/TaskExpiryJob.php:57` —
      `IN_PROGRESS_GRACE = 86400` → admin-config
      `pipelinq.task_expiry.in_progress_grace_seconds` (default `86400`).
- [ ] 7.6 `lib/BackgroundJob/TaskEscalationJob.php:43` —
      `ESCALATION_THRESHOLD_HOURS = 4` → admin-config
      `pipelinq.task_escalation.threshold_hours` (default `4`).
- [ ] 7.7 `lib/Service/TaskService.php:73` — `BUSINESS_HOUR_START = 8` → admin-config
      `pipelinq.task.business_hour_start` (default `8`). NL-specific assumption removed.
- [ ] 7.8 `lib/Service/TaskService.php:80` — `BUSINESS_HOUR_END = 17` → admin-config
      `pipelinq.task.business_hour_end` (default `17`).
- [ ] 7.9 `lib/Service/ProspectDiscoveryService.php:36` — `CACHE_TTL = 3600` →
      admin-config `pipelinq.prospect_discovery.cache_ttl_seconds` (default `3600`).
- [ ] 7.10 `lib/Service/KvkApiClient.php:37` —
      `API_BASE = 'https://api.kvk.nl/api/v1'` → admin-config
      `pipelinq.kvk.api_base_url` (default `https://api.kvk.nl/api/v1`). Class is
      LEGITIMATE third-party client; only the URL becomes admin-config so EU/UK regional
      endpoints can be configured.
- [ ] 7.11 `lib/Service/OpenCorporatesApiClient.php:37` —
      `API_BASE = 'https://api.opencorporates.com/v0.4'` → admin-config
      `pipelinq.opencorporates.api_base_url`.
- [ ] 7.12 Confirm Dutch state literals from Phase 2 are removed from source after
      lifecycle migration (no `'gepubliceerd'|'nieuw'|'openbaar'` literals in `lib/`).

## Phase 8 — manifest adoption

Cite `hydra/openspec/changes/adopt-app-manifest/`.

- [ ] 8.1 Create `openspec/manifest.yaml` with: `tier: 3` (frontend exemplar),
      `dependencies: ["openregister"]`,
      `consumes: [register-resolver-service, pluggable-integration-registry,
      i18n-source-of-truth, i18n-api-language-negotiation, multi-tenancy-context]`.
- [ ] 8.2 Pin minimum OR version in the manifest (must include
      `register-resolver-service` and `contacts-actions` integration provider).
- [ ] 8.3 In the manifest, declare `pipelinq.role: object-store-exemplar` (or equivalent
      key as defined by `adopt-app-manifest`) so other apps can find the reference
      implementation.
- [ ] 8.4 Validate the manifest with the Hydra manifest schema once it ships.

## Phase 9 — multi-tenancy + i18n adoption

Gated on nc-vue `multi-tenancy-context` and OR `i18n-source-of-truth` /
`i18n-api-language-negotiation` shipping.

- [ ] 9.1 Adopt `multi-tenancy-context` formally: `src/store/modules/object.js` already
      receives tenant context implicitly via `createObjectStore`; declare the dependency
      explicitly in the store factory call.
- [ ] 9.2 Adopt `i18n-source-of-truth` for translatable fields on kennisbank, lead, task,
      callback schemas (label, description, lifecycle-state-display-name, notification
      copy from Phase 3).
- [ ] 9.3 Adopt `i18n-api-language-negotiation` for the pipelinq API: respect the
      `Accept-Language` header on read responses.

## Phase 10 — spec note: createObjectStore exemplar status

Distinct from apply work; spec-side declaration so the exemplar status doesn't get lost.

- [ ] 10.1 Add an EXPLICIT requirement in the capability spec stating
      `src/store/modules/object.js` is the reference implementation of the
      `createObjectStore` pattern.
- [ ] 10.2 Add a scenario stating future audits SHALL cite this Requirement and SHALL NOT
      flag the file as needing rewrite.
