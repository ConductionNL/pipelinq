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
