# pipelinq: adopt OpenRegister abstractions

## Why

The OR-abstraction audit (2026-05-03) places pipelinq at Tier 2-3: it's the frontend
exemplar for `createObjectStore` (`src/store/modules/object.js` already uses
`createObjectStore('object', { plugins: [filesPlugin(), auditTrailsPlugin(),
relationsPlugin(), registerMappingPlugin()] })` — KEEP), but its backend has the highest
density of register-resolver and magic-number findings of the three apps in this batch.

Findings driving this change:

- **Eight register-resolver call sites** across `QueueService` (4 sites), `DefaultQueueService`
  (2), `ContactVcardService`, and `ContactVcardWriterService`. All read
  `$appConfig->getValueString(APP_ID, 'register', '')` directly. Migrate to
  `RegisterResolverService` per the OR-side spec.
- **Dutch state literals across 4 files**: `KennisbankService`, `KennisbankReviewJob`,
  `PublicKennisbankController`, with `'status' => 'gepubliceerd'|'nieuw'` and
  `'visibility' => 'openbaar'`. Lifecycle annotation candidates.
- **Five more inline status writes**: `CalendarSyncService` (`scheduled`),
  `CallbackController` (`open`), `AutomationService` (`skipped`, `failure`).
- **Tenant-specific timing constants in seven background jobs**:
  `KennisbankReviewJob`, `QueueOverflowJob`, `TaskExpiryJob` (3 constants),
  `TaskEscalationJob`. All currently PHP `const`; should be admin-config so each tenant
  can tune SLAs.
- **Hardcoded business hours** (`BUSINESS_HOUR_START = 8`, `BUSINESS_HOUR_END = 17` in
  `TaskService.php:73,80`) — NL-specific timezone assumption, must be tenant-tunable.
- **Hardcoded third-party API URLs**: `KvkApiClient::API_BASE`,
  `OpenCorporatesApiClient::API_BASE` — legitimate clients, but the URLs should be
  admin-config so EU/UK/etc tenants can point at regional endpoints.
- **Direct notification calls**: `NotificationService:405-412`, `ActivityService:291` use
  `notificationManager->notify()` / `setSubject()` directly. Should be
  `x-openregister-notifications`.
- **Spec rewrite needed**: `openspec/specs/contacts-sync/spec.md` (P2) describes a custom
  sync; should leverage OR's `contacts-actions` integration provider
  (`ContactMatchingService`) instead.
- **Spec hint**: `openspec/specs/lead-management/spec.md:26,35` correctly proposes JSON
  enums for source/priority. Line 1024 leaves "frontend vs backend qualification score"
  open — should mandate `x-openregister-calculations`. Lines 505/519/924 — staleness/aging/
  lead-value computations are calculation candidates.
- **No app manifest**.

Findings explicitly KEPT:

- **Frontend exemplar**: `src/store/modules/object.js` `createObjectStore` usage stays as-is.
  This change documents it as the reference pattern other apps should follow.
- **adr-000**: already reframed by Phase 1 PR #315 — cite, do NOT repeat.

The audit references this proposal must respect:

- `.claude/audit-2026-05-03/01-code-cleanup.md` (stream 1: keep `createObjectStore`)
- `.claude/audit-2026-05-03/02-spec-rewrite.md` (stream 2: contacts-sync rewrite,
  lead-management calc annotations)
- `.claude/audit-2026-05-03/04-hardcoded.md` (stream 4: 8 resolver sites + 12 magic numbers
  + Dutch state literals)
- `hydra/openspec/architecture/ADR-022.md` (lifecycle)
- `hydra/openspec/architecture/ADR-024.md` (archival)
- `hydra/openspec/architecture/ADR-025.md` (notifications)

## What Changes

### Register-resolver consumption (Phase 1, big win)

1. Migrate eight `$appConfig->getValueString(APP_ID, 'register', '')` call sites to
   `RegisterResolverService` per the OR-side spec.

### Lifecycle annotation migration

2. Migrate Dutch state literals (`gepubliceerd`, `nieuw`, `openbaar`) on the kennisbank
   schema to `x-openregister-lifecycle`.
3. Migrate `'status' => 'scheduled'` (calendar-sync), `'open'` (callback), `'skipped'` /
   `'failure'` (automation-run) inline writes to lifecycle transitions.

### Notification annotation migration

4. `NotificationService:405-412` and `ActivityService:291` — direct notification calls
   replaced with `x-openregister-notifications` annotations on the relevant schemas.

### Calculation annotation migration

5. `openspec/specs/lead-management/spec.md:1024` — open question on qualification score
   resolved as `x-openregister-calculations`. Lines 505/519/924 — staleness, aging, and
   lead-value computations declared as calculations.

### Spec rewrites

6. `openspec/specs/contacts-sync/spec.md` — replace custom NC Contacts sync with OR's
   `contacts-actions` integration provider.
7. `openspec/specs/lead-management/spec.md` — keep enum patterns at lines 26/35 (correct);
   add calculation annotations per Phase 5; link this change.

### Hardcoded magic-number cleanup

8. Twelve constants migrated to admin-config (timing, business-hours, cache TTL, third-party
   API base URLs, default review intervals). Defaults preserve current behavior.

### Manifest + multi-tenancy + i18n adoption

9. `openspec/manifest.yaml` — Tier 2-3, `dependencies: ["openregister"]`, declares
   pipelinq's role as `createObjectStore` exemplar.
10. Frontend stores already pass tenant context via `createObjectStore`; consume
    `multi-tenancy-context` to formalize.
11. i18n adoption for kennisbank, lead, task, callback schemas.

### Spec note: createObjectStore exemplar status

12. The new capability spec EXPLICITLY records that `src/store/modules/object.js` is the
    reference implementation; future audits cite this rather than re-investigating.

## Impact

- Affected code (apply-phase hints, NOT changed here):
  `lib/Service/QueueService.php` (4 sites), `lib/Service/DefaultQueueService.php` (2),
  `lib/Service/ContactVcardService.php`, `lib/Service/ContactVcardWriterService.php`,
  `lib/Service/KennisbankService.php`, `lib/BackgroundJob/KennisbankReviewJob.php`,
  `lib/Controller/PublicKennisbankController.php`, `lib/Service/CalendarSyncService.php`,
  `lib/Controller/CallbackController.php`, `lib/Service/AutomationService.php`,
  `lib/Service/KvkApiClient.php`, `lib/Service/OpenCorporatesApiClient.php`,
  `lib/BackgroundJob/QueueOverflowJob.php`, `lib/BackgroundJob/TaskExpiryJob.php`,
  `lib/BackgroundJob/TaskEscalationJob.php`, `lib/Service/TaskService.php`,
  `lib/Service/ProspectDiscoveryService.php`, `lib/Service/NotificationService.php`,
  `lib/Service/ActivityService.php`.
- Affected specs: `openspec/specs/contacts-sync/spec.md` (REWRITE),
  `openspec/specs/lead-management/spec.md` (calc annotations + minor edit),
  `openspec/specs/openregister-integration/spec.md` (CURRENT, exemplar — link only). New
  `pipelinq-or-adoption` capability spec.
- Breaking changes: state-literal migration produces same on-wire values (no API break).
  Magic-number defaults preserved.
- Dependencies: same as docudesk + openconnector — OR + nc-vue + Hydra ship prerequisites.



## Design

# Design — pipelinq: adopt OR abstractions

## Context

pipelinq is the CRM/customer-pipeline app of the Conduction stack: leads, tasks, callbacks,
queues, kennisbank (knowledge base), automation runs, calendar sync, contact sync. The
2026-05-03 OR-abstraction audit places it at Tier 2-3: Tier 3 on the frontend (it's the
reference implementation for `createObjectStore` — KEEP), Tier 2 on the backend (highest
density of register-resolver and magic-number findings in this batch).

This change pairs with the docudesk and openconnector adoption changes and depends on the
same OR-side and Hydra-side prerequisites.

## Goals

- Eliminate eight `getValueString(APP_ID, 'register', '')` call sites by adopting
  `RegisterResolverService`.
- Migrate Dutch state literals onto lifecycle annotations.
- Move 12 hardcoded constants (timing, business-hours, third-party API base URLs) to
  admin-config so each tenant can tune SLAs, regional endpoints, and timezone-dependent
  values.
- Replace direct notification calls with notification annotations.
- Resolve the lead-management spec's open question on qualification score as a
  calculation annotation.
- Rewrite the contacts-sync spec to consume OR's `contacts-actions` integration provider.
- Document `src/store/modules/object.js` as the `createObjectStore` reference
  implementation.

## Non-Goals

- Replacing `createObjectStore` usage in `src/store/modules/object.js`. EXEMPLAR; KEPT.
- Replacing the third-party API clients (`KvkApiClient`,
  `OpenCorporatesApiClient`). KEPT — only the base URL moves to admin-config.
- Re-opening `adr-000` (already reframed by Phase 1 PR #315). Cite, do not repeat.
- Touching `openspec/specs/openregister-integration/spec.md` (CURRENT, exemplar). Link
  only.

## Decisions

### Decision 1 — Eight resolver migrations as a single phase

The audit identified eight distinct call sites of the same anti-pattern. A single phase
(not eight) keeps the change cohesive and lets the apply phase do them in one pass.

**Decision**: Phase 1 lists all eight as separate sub-tasks. Apply phase does them
together. Phase ends with a verification grep (`getValueString(APP_ID, 'register', '')`
returns zero matches in `lib/`).

**Why**: stream 4 hint structure — file paths verbatim. Apply phase has zero ambiguity
about scope.

### Decision 2 — Visibility is orthogonal to lifecycle, not a lifecycle state

The kennisbank schema currently mixes `'visibility' => 'openbaar'` with
`'status' => 'gepubliceerd'`. Visibility (public vs internal) is a permission concern;
status (new, in review, published, withdrawn) is a lifecycle concern.

**Decision**: lifecycle annotation declares status states. Visibility stays as a separate
field with a JSON-schema enum of `openbaar`, `intern`. The two are independent — a
withdrawn item can still have visibility `openbaar` (read-only public archive).

**Why**: ADR-022 lifecycle is about state transitions with hooks. Visibility doesn't have
transitions; it has authorization. Mixing them muddies the annotation semantics.

### Decision 3 — `lead-management` keeps its enums; ADDs calculations

`lead-management/spec.md:26,35` correctly proposes JSON-schema `enum` for `source` and
`priority` (these are taxonomies, not lifecycles). Lines 1024 / 505 / 519 / 924 cover
qualification score, staleness, aging, and lead-value — all computed.

**Decision**: KEEP the existing enums (correct pattern). ADD calculation annotations for
the four computed values. Do NOT rewrite the spec; minor edit only.

**Why**: stream 2 audit was specific — `contacts-sync` is REWRITE, `lead-management` is
edit. Scope discipline.

### Decision 4 — Contacts-sync rewrite consumes `contacts-actions` provider

The audit's stream 2 finding: `contacts-sync` describes a custom NC Contacts sync. OR ships
a `contacts-actions` integration provider via `ContactMatchingService`.

**Decision**: rewrite the spec to consume `ContactMatchingService` from OR's
`pluggable-integration-registry`. Drop bespoke matching. Document fallback behavior
(when the provider is not registered, sync degrades gracefully — no hard dependency
deadlock).

**Why**: stream 2 finding. Reuse over re-implement.

### Decision 5 — Third-party API clients stay; their URLs become admin-config

`KvkApiClient::API_BASE = 'https://api.kvk.nl/api/v1'` and
`OpenCorporatesApiClient::API_BASE = 'https://api.opencorporates.com/v0.4'` are
LEGITIMATE third-party clients (the audit explicitly marks them so). They are not
duplications.

**Decision**: keep the clients. Move the URLs to admin-config. Default values preserved.

**Why**: stream 4 finding. EU and UK tenants may need to point at regional endpoints; NL
default is preserved for the existing tenant base.

### Decision 6 — Magic-number defaults preserve current behavior

Same rule as the other two apps: default = current constant value. Apply phase does
zero-behavior-change install.

### Decision 7 — `src/store/modules/object.js` is exemplar; document explicitly

The audit identifies pipelinq as the frontend exemplar for `createObjectStore`. Without
an explicit Requirement, future audits may re-investigate.

**Decision**: capability spec ADDS a Requirement stating the file is the reference
implementation. Future audits cite this Requirement and skip re-investigation.

**Why**: ratchet effect. Document the audit's positive finding so it survives audit
churn.

### Decision 8 — Tenant-specific timing constants get tenant-tunable defaults

Background-job intervals (`KennisbankReviewJob`, `QueueOverflowJob`, `TaskExpiryJob`,
`TaskEscalationJob`) and business hours (`TaskService`) are tenant-tunable SLAs and
timezone-dependent values. They MUST be admin-config.

**Why**: stream 4 finding. SaaS tenants in different timezones / SLAs cannot share a
single hardcoded value. Critical for multi-tenant deployments.

### Decision 9 — `adr-000` is cited, not repeated

Phase 1 PR #315 already reframed `adr-000`. This change cites it under "See Also" and
does not repeat its content.

**Why**: spec-only discipline. Don't double-document.

## Risks / Trade-offs

| Risk | Mitigation |
| --- | --- |
| Eight resolver migrations may have subtle differences (some sites read schema, not register; some have fallback values). | Phase 1 lists each call site separately; apply phase reads the surrounding context per file before migrating. Verification grep at end. |
| Dutch state literal migration on the wire — Dutch consumers may expect literal `'gepubliceerd'`. | Lifecycle annotation preserves on-wire string; the literal is the lifecycle-state name, only the WRITE call changes. |
| `BUSINESS_HOUR_START/END` migration may break tenants relying on default UTC interpretation. | Default value (8/17) preserved. Apply phase ALSO documents the timezone assumption (Europe/Amsterdam) and routes through `TimezoneService` so admin-config defines hours in the tenant's timezone, not UTC. |
| `contacts-sync` rewrite depends on OR shipping `contacts-actions` integration provider. | Phase 6 gated on prerequisite; manifest minimum OR version pins the requirement. |
| Background-job intervals, if mistuned by an admin, can flood the queue. | Apply phase adds a `validateAdminConfig` step that bounds-checks the values (e.g. `INTERVAL >= 60` seconds). |
| Eight register reads in Phase 1 may have been cached implicitly by call frequency; switching to `RegisterResolverService` may change perf characteristics. | `RegisterResolverService` per OR-side spec is request-scoped cached. Behavior should be neutral or better. |

## Migration path

1. OR ships `register-resolver-service`, `pluggable-integration-registry`,
   `i18n-source-of-truth`, `i18n-api-language-negotiation`, AND the `contacts-actions`
   integration provider (gates Phases 1, 6, 9).
2. OR ships ADR-022 lifecycle + ADR-024 archival + ADR-025 notification annotation runtime
   (gates Phases 2, 3, 4, 5).
3. nc-vue ships `multi-tenancy-context` (gates Phase 9).
4. Hydra ships `adopt-app-manifest` (gates Phase 8).
5. pipelinq apply phase runs in order: 1 → 6 → 2 → 3 → 4 → 5 → 7 → 8 → 9 → 10. Phase 1
   first because it's the largest, simplest find-and-replace win. Spec rewrites (Phase 6)
   precede annotation migrations so the annotations attach to the rewritten schemas.

## Open Questions

- `contacts-actions` integration provider's exact API surface: needs confirmation from
  the OR-side spec authors before Phase 6 rewrite. Apply phase pulls the latest spec.
- Kennisbank lifecycle: is `ingetrokken` (withdrawn) the right terminal state, or is
  there a separate `gearchiveerd` (archived)? Apply phase confirms with PO.
- Calendar-sync lifecycle: is `succeeded` distinct from `running` (a sync that's still
  posting events to the calendar but the source-side fetch is done)? Apply phase confirms.
- `KvkApiClient` and `OpenCorporatesApiClient` regional endpoints: do tenants actually
  need this configurability, or is admin-config gold-plating? Audit flagged it; apply
  phase confirms with PO before shipping.



## Tasks

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