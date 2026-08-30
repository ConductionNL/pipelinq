# Design — pipelinq: adopt OR abstractions

## Context

pipelinq is the CRM/customer-pipeline app of the Conduction stack: leads, tasks, callbacks,
queues, kennisbank (knowledge base), automation runs, calendar sync, contact sync. The
2026-05-03 OR-abstraction audit places it at Tier 2-3: Tier 3 on the frontend (it is the
reference implementation for `createObjectStore` — KEEP), Tier 2 on the backend (highest
density of register-resolver and magic-number findings in this batch).

This change pairs with the docudesk and openconnector adoption changes and depends on the
same OR-side and Hydra-side prerequisites.

## Goals

- Eliminate eight `getValueString(APP_ID, 'register', '')` call sites by adopting
  `RegisterResolverService`.
- Migrate Dutch state literals (`gepubliceerd`, `nieuw`, `openbaar`) on the kennisbank schema
  and inline status writes on calendar-sync, callback, and automation-run schemas to
  `x-openregister-lifecycle`.
- Move 12 hardcoded constants (timing, business-hours, third-party API base URLs) to
  admin-config so each tenant can tune SLAs, regional endpoints, and timezone-dependent values.
- Replace direct notification calls with `x-openregister-notifications` annotations on
  the relevant schemas.
- Resolve the lead-management spec's open question on qualification score as a
  `x-openregister-calculations` annotation.
- Rewrite the contacts-sync spec to consume OR's `contacts-actions` integration provider.
- Document `src/store/modules/object.js` as the `createObjectStore` reference implementation.

## Non-Goals

- Replacing `createObjectStore` usage in `src/store/modules/object.js`. EXEMPLAR; KEPT.
- Replacing `KvkApiClient` or `OpenCorporatesApiClient`. KEPT — only the base URL moves to
  admin-config.
- Re-opening `adr-000` (already reframed by Phase 1 PR #315). Cite, do not repeat.
- Touching `openspec/specs/openregister-integration/spec.md` (CURRENT, exemplar). Link only.

## Decisions

### Decision 1 — Eight resolver migrations as a single phase

The audit identified eight distinct call sites of the same anti-pattern. A single phase
(not eight) keeps the change cohesive and lets the apply phase do them in one pass.

**Decision**: Phase 1 lists all eight as separate sub-tasks. Apply phase does them together.
Phase ends with a verification grep (`getValueString(APP_ID, 'register', '')` returns zero
matches in `lib/`).

**Why**: stream 4 hint structure — file paths verbatim. Apply phase has zero ambiguity about
scope.

### Decision 2 — Visibility is orthogonal to lifecycle, not a lifecycle state

The kennisbank schema currently mixes `'visibility' => 'openbaar'` with
`'status' => 'gepubliceerd'`. Visibility (public vs internal) is a permission concern;
status (new, in review, published, withdrawn) is a lifecycle concern.

**Decision**: lifecycle annotation declares status states
(`nieuw → in_review → gepubliceerd → ingetrokken`). Visibility stays as a separate field
with a JSON-schema enum of `openbaar`, `intern`. The two are independent — a withdrawn item
can still have visibility `openbaar` (read-only public archive).

**Why**: ADR-022 lifecycle is about state transitions with hooks. Visibility does not have
transitions; it has authorization. Mixing them muddies the annotation semantics.

### Decision 3 — `lead-management` keeps its enums; ADDs calculations

`lead-management/spec.md:26,35` correctly proposes JSON-schema `enum` for `source` and
`priority` (these are taxonomies, not lifecycles). Lines 1024/505/519/924 cover qualification
score, staleness, aging, and lead-value — all computed.

**Decision**: KEEP the existing enums (correct pattern). ADD calculation annotations for the
four computed values. Do NOT rewrite the spec; minor edit only.

**Why**: stream 2 audit was specific — `contacts-sync` is REWRITE, `lead-management` is edit.
Scope discipline.

### Decision 4 — Contacts-sync rewrite consumes `contacts-actions` provider

The audit's stream 2 finding: `contacts-sync` describes a custom NC Contacts sync. OR ships a
`contacts-actions` integration provider via `ContactMatchingService`.

**Decision**: rewrite the spec to consume `ContactMatchingService` from OR's
`pluggable-integration-registry`. Drop bespoke matching. Document fallback behavior (when the
provider is not registered, sync degrades gracefully — no hard dependency deadlock).

**Why**: stream 2 finding. Reuse over re-implement.

### Decision 5 — Third-party API clients stay; their URLs become admin-config

`KvkApiClient::API_BASE = 'https://api.kvk.nl/api/v1'` and
`OpenCorporatesApiClient::API_BASE = 'https://api.opencorporates.com/v0.4'` are LEGITIMATE
third-party clients (the audit explicitly marks them so). They are not duplications.

**Decision**: keep the clients. Move the URLs to admin-config. Default values preserved.

**Why**: stream 4 finding. EU and UK tenants may need to point at regional endpoints; NL
default is preserved for the existing tenant base.

### Decision 6 — Magic-number defaults preserve current behavior

Default = current constant value. Apply phase does zero-behavior-change install.

**Why**: tenants in production depend on the current timing values. A behavior change at
install time would break SLAs silently.

### Decision 7 — `src/store/modules/object.js` is exemplar; document explicitly

The audit identifies pipelinq as the frontend exemplar for `createObjectStore`. Without an
explicit Requirement, future audits may re-investigate.

**Decision**: capability spec ADDS a Requirement stating the file is the reference
implementation. Future audits cite this Requirement and skip re-investigation.

**Why**: ratchet effect. Document the audit's positive finding so it survives audit churn.

### Decision 8 — Tenant-specific timing constants get tenant-tunable defaults

Background-job intervals (`KennisbankReviewJob`, `QueueOverflowJob`, `TaskExpiryJob`,
`TaskEscalationJob`) and business hours (`TaskService`) are tenant-tunable SLAs and
timezone-dependent values. They MUST be admin-config.

**Why**: stream 4 finding. SaaS tenants in different timezones / SLAs cannot share a
single hardcoded value. Critical for multi-tenant deployments.

### Decision 9 — `adr-000` is cited, not repeated

Phase 1 PR #315 already reframed `adr-000`. This change cites it under "See Also" and does
not repeat its content.

**Why**: spec-only discipline. Don't double-document.

## Schema Changes

This change does not introduce new schemas. It adds annotations to existing schemas. Key
annotation changes per entity (see ADR-000 for full property definitions):

### kennisartikel — lifecycle annotation

```yaml
x-openregister-lifecycle:
  states:
    - nieuw          # initial on creation
    - in_review      # submitted for editorial review
    - gepubliceerd   # visible per visibility field
    - ingetrokken    # withdrawn, read-only archive
  transitions:
    - from: nieuw       to: in_review
    - from: in_review   to: gepubliceerd
    - from: in_review   to: nieuw
    - from: gepubliceerd to: ingetrokken
  hooks:
    on_enter_gepubliceerd: notify-subscribers
    on_enter_ingetrokken:  notify-author
```

`visibility` field changes: was an inline literal; becomes JSON-schema `enum: [openbaar, intern]`.
`visibility` is NOT a lifecycle state (see Decision 2).

### calendarLink — lifecycle annotation

```yaml
x-openregister-lifecycle:
  states: [scheduled, running, succeeded, failed]
  initial: scheduled
```

### callback (request schema) — lifecycle annotation

```yaml
x-openregister-lifecycle:
  states: [open, claimed, completed, cancelled]
  initial: open
```

### automationLog — lifecycle annotation

```yaml
x-openregister-lifecycle:
  states: [pending, running, succeeded, failed, skipped]
  initial: pending
```

### lead — calculation annotations

```yaml
x-openregister-calculations:
  - property: qualificationScore    # replaces open question at spec:1024
    backend: true
    frontend: readonly
  - property: staleness             # spec:505
    backend: true
  - property: aging                 # spec:519
    backend: true
  - property: leadValue             # spec:924
    backend: true
```

### Schemas receiving notification annotations (ADR-025)

| Schema | Trigger event | Recipient |
|--------|---------------|-----------|
| task | on_create | assigneeUserId / assigneeGroupId |
| task | on_deadline_approaching | assigneeUserId |
| lead | on_stage_change | assignee |
| request (callback) | on_status_open | assignedAgents queue |

## Reuse Analysis

| Concern | Existing OR mechanism | Pipelinq usage after this change |
|---------|----------------------|----------------------------------|
| Register lookup | `RegisterResolverService` | All 8 call sites migrated |
| State tracking | `x-openregister-lifecycle` (ADR-022) | kennisartikel, calendarLink, callback, automationLog |
| Computed values | `x-openregister-calculations` | lead: qualificationScore, staleness, aging, leadValue |
| Notifications | `x-openregister-notifications` (ADR-025) | task, lead, request schemas |
| Archival | `x-openregister-archival` (ADR-024) | kennisartikel versions, automationLog |
| Contact matching | OR `contacts-actions` provider / `ContactMatchingService` | contacts-sync rewrite |
| Object store | `createObjectStore` + plugins | `src/store/modules/object.js` — KEEP as exemplar |
| Multi-tenancy | `multi-tenancy-context` (nc-vue) | Formalise existing implicit usage |
| i18n | `i18n-source-of-truth`, `i18n-api-language-negotiation` | kennisartikel, lead, task, callback |

## Seed Data

This change does not introduce new schemas, so seed data additions are limited to the
schemas receiving annotation changes. These examples are suitable for
`lib/Settings/pipelinq_register.json` under `components.objects[]`.

### kennisartikel — 3 seed objects

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "kennisartikel",
      "slug": "ka-parkeervergunning-aanvragen"
    },
    "title": "Parkeervergunning aanvragen — stap voor stap",
    "summary": "Uitleg over de aanvraagprocedure voor een bewonersparkeervergunning in gemeente Delft.",
    "body": "## Wat heb je nodig?\n\nEen geldig rijbewijs en kentekenbewijs...",
    "status": "gepubliceerd",
    "visibility": "openbaar",
    "author": "admin",
    "version": 3,
    "publishedAt": "2026-02-10T09:00:00Z",
    "usefulnessScore": 87.5
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "kennisartikel",
      "slug": "ka-bijstandsuitkering-intern"
    },
    "title": "Bijstandsuitkering — beoordelingscriteria (intern)",
    "summary": "Interne werkinstructie voor KCC-medewerkers bij vragen over bijstand.",
    "body": "## Drempelwaarden\n\nZie bijlage Participatiewet art. 17...",
    "status": "gepubliceerd",
    "visibility": "intern",
    "author": "jvandijk",
    "version": 1,
    "publishedAt": "2026-01-15T14:00:00Z",
    "usefulnessScore": 92.0
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "kennisartikel",
      "slug": "ka-omgevingsvergunning-nieuw"
    },
    "title": "Omgevingsvergunning kleine bouwwerken",
    "summary": "Concept-artikel over vergunningvrij bouwen onder de Omgevingswet.",
    "body": "## Omgevingswet 2024\n\nSinds 1 januari 2024 geldt...",
    "status": "nieuw",
    "visibility": "intern",
    "author": "mvanrooden",
    "version": 1,
    "usefulnessScore": 0
  }
]
```

### lead — 3 seed objects

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "lead",
      "slug": "lead-gemeente-amsterdam-digitaal"
    },
    "title": "Gemeente Amsterdam — digitale dienstverlening platform",
    "source": "referral",
    "value": 85000,
    "probability": 60,
    "expectedCloseDate": "2026-08-01",
    "priority": "high",
    "stage": "Voorstel",
    "stageOrder": 2,
    "status": "open"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "lead",
      "slug": "lead-provincie-utrecht-crm"
    },
    "title": "Provincie Utrecht — CRM-implementatie",
    "source": "event",
    "value": 42000,
    "probability": 35,
    "expectedCloseDate": "2026-09-15",
    "priority": "normal",
    "stage": "Eerste contact",
    "stageOrder": 1,
    "status": "open"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "lead",
      "slug": "lead-waterschap-rijnland-intake"
    },
    "title": "Waterschap Rijnland — klantcontact intakemodule",
    "source": "website",
    "value": 28500,
    "probability": 80,
    "expectedCloseDate": "2026-07-01",
    "priority": "high",
    "stage": "Contract",
    "stageOrder": 3,
    "status": "open"
  }
]
```

### task — 3 seed objects

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "task",
      "slug": "task-tbv-jansen-maandag"
    },
    "type": "terugbelverzoek",
    "subject": "Terugbelverzoek mevr. C. Jansen — parkeervergunning",
    "status": "open",
    "priority": "normal",
    "deadline": "2026-05-21T16:00:00Z",
    "callbackPhoneNumber": "06-12345678",
    "preferredTimeSlot": "Maandag 14:00 - 16:00",
    "createdBy": "kcc-agent1"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "task",
      "slug": "task-opvolging-gemeente-leiden"
    },
    "type": "opvolgtaak",
    "subject": "Opvolging offerte Gemeente Leiden — Q3 2026",
    "status": "in_progress",
    "priority": "high",
    "deadline": "2026-06-01T09:00:00Z",
    "assigneeUserId": "salesrep2",
    "createdBy": "salesrep1"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "task",
      "slug": "task-info-wmo-aanvraag"
    },
    "type": "informatievraag",
    "subject": "Informatievraag WMO-aanvraag dhr. P. de Vries",
    "status": "completed",
    "priority": "normal",
    "completedAt": "2026-05-19T11:30:00Z",
    "resultText": "Aanvrager doorverbonden met WMO-loket Delft (088-1234567).",
    "createdBy": "kcc-agent3"
  }
]
```

### automationLog — 3 seed objects

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "automationLog",
      "slug": "log-auto-lead-created-001"
    },
    "triggeredAt": "2026-05-19T08:15:00Z",
    "status": "succeeded",
    "actionsExecuted": [{"action": "send_notification", "result": "ok"}]
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "automationLog",
      "slug": "log-auto-lead-stale-002"
    },
    "triggeredAt": "2026-05-18T23:00:00Z",
    "status": "skipped",
    "actionsExecuted": [],
    "error": "Trigger condition not met: lead.stageOrder < 2"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "automationLog",
      "slug": "log-auto-webhook-fail-003"
    },
    "triggeredAt": "2026-05-17T14:42:00Z",
    "status": "failed",
    "actionsExecuted": [{"action": "webhook", "result": "timeout"}],
    "error": "Webhook endpoint https://n8n.example.com/hook/abc123 returned 504"
  }
]
```

## Admin-Config Keys

All twelve constants migrated to admin-config. Defaults preserve current behaviour.

| Config key | Current constant | Default | Location |
|------------|-----------------|---------|----------|
| `pipelinq.kennisbank.review_interval_days` | `DEFAULT_REVIEW_INTERVAL = 180` | `180` | `KennisbankReviewJob:41` |
| `pipelinq.queue_overflow.poll_interval_seconds` | `INTERVAL = 300` | `300` | `QueueOverflowJob:41` |
| `pipelinq.task_expiry.poll_interval_seconds` | `INTERVAL = 900` | `900` | `TaskExpiryJob:43` |
| `pipelinq.task_expiry.escalation_threshold_seconds` | `ESCALATION_THRESHOLD = 14400` | `14400` | `TaskExpiryJob:50` |
| `pipelinq.task_expiry.in_progress_grace_seconds` | `IN_PROGRESS_GRACE = 86400` | `86400` | `TaskExpiryJob:57` |
| `pipelinq.task_escalation.threshold_hours` | `ESCALATION_THRESHOLD_HOURS = 4` | `4` | `TaskEscalationJob:43` |
| `pipelinq.task.business_hour_start` | `BUSINESS_HOUR_START = 8` | `8` | `TaskService:73` |
| `pipelinq.task.business_hour_end` | `BUSINESS_HOUR_END = 17` | `17` | `TaskService:80` |
| `pipelinq.prospect_discovery.cache_ttl_seconds` | `CACHE_TTL = 3600` | `3600` | `ProspectDiscoveryService:36` |
| `pipelinq.kvk.api_base_url` | `API_BASE = 'https://api.kvk.nl/api/v1'` | `https://api.kvk.nl/api/v1` | `KvkApiClient:37` |
| `pipelinq.opencorporates.api_base_url` | `API_BASE = 'https://api.opencorporates.com/v0.4'` | `https://api.opencorporates.com/v0.4` | `OpenCorporatesApiClient:37` |
| *(Dutch state literals removed per Phase 2 — no admin-config needed)* | — | — | various |

## Migration Path

1. OR ships `register-resolver-service`, `pluggable-integration-registry`,
   `i18n-source-of-truth`, `i18n-api-language-negotiation`, AND the `contacts-actions`
   integration provider (gates Phases 1, 6, 9).
2. OR ships ADR-022 lifecycle + ADR-024 archival + ADR-025 notification annotation runtime
   (gates Phases 2, 3, 4, 5).
3. nc-vue ships `multi-tenancy-context` (gates Phase 9).
4. Hydra ships `adopt-app-manifest` (gates Phase 8).
5. pipelinq apply phase runs in order: **1 → 6 → 2 → 3 → 4 → 5 → 7 → 8 → 9 → 10**.
   Phase 1 first (largest, simplest find-and-replace win). Spec rewrites (Phase 6) precede
   annotation migrations so annotations attach to the rewritten schemas.

## Risks / Trade-offs

| Risk | Mitigation |
|------|------------|
| Eight resolver migrations may have subtle differences (some sites read schema, not register; some have fallback values). | Phase 1 lists each call site separately; apply phase reads the surrounding context per file before migrating. Verification grep at end. |
| Dutch state literal migration on the wire — Dutch consumers may expect literal `'gepubliceerd'`. | Lifecycle annotation preserves on-wire string; the literal is the lifecycle-state name, only the WRITE call changes. |
| `BUSINESS_HOUR_START/END` migration may break tenants relying on default UTC interpretation. | Default value (8/17) preserved. Apply phase ALSO documents the timezone assumption (Europe/Amsterdam) and routes through `TimezoneService`. |
| `contacts-sync` rewrite depends on OR shipping `contacts-actions` integration provider. | Phase 6 gated on prerequisite; manifest minimum OR version pins the requirement. |
| Background-job intervals mistuned by an admin can flood the queue. | Apply phase adds a `validateAdminConfig` step that bounds-checks values (e.g. `INTERVAL >= 60` seconds). |
| `RegisterResolverService` may change perf characteristics vs direct `getValueString`. | `RegisterResolverService` per OR-side spec is request-scoped cached. Behaviour should be neutral or better. |

## Open Questions

- `contacts-actions` integration provider's exact API surface: needs confirmation from the
  OR-side spec authors before Phase 6 rewrite. Apply phase pulls the latest spec.
- Kennisbank lifecycle: is `ingetrokken` (withdrawn) the right terminal state, or is there a
  separate `gearchiveerd` (archived)? Apply phase confirms with PO.
- Calendar-sync lifecycle: is `succeeded` distinct from `running` (a sync still posting events
  while the source-side fetch is done)? Apply phase confirms.
- `KvkApiClient` and `OpenCorporatesApiClient` regional endpoints: do tenants actually need
  this configurability? Audit flagged it; apply phase confirms with PO.

## See Also

- `openspec/architecture/adr-000-data-model.md` — entity definitions (reframed by PR #315)
- `openspec/architecture/adr-001-international-first-dutch-mapping.md` — Dutch mapping layer
- `.claude/openspec/architecture/adr-001-data-layer.md` — OR data layer and seed data rules
- `.claude/openspec/architecture/adr-019-integration-registry.md` — integration registry
- `openspec/specs/openregister-integration/spec.md` — CURRENT exemplar (LINK ONLY, do not edit)
- `hydra/openspec/architecture/ADR-022.md` — lifecycle annotations
- `hydra/openspec/architecture/ADR-024.md` — archival annotations
- `hydra/openspec/architecture/ADR-025.md` — notification annotations
