> SUPERSEDED 2026-05-31: this umbrella was split per ADR-032 into child slices that are now all implemented and archived. Coverage: Phase 1 → `pipelinq-or-register-resolver`; Phases 2-3 → `pipelinq-or-lifecycle-notification`; Phases 4-5 → `pipelinq-or-archival-calculations`; Phase 6 → `pipelinq-or-spec-rewrites`; Phase 7 → `pipelinq-admin-config-magic-numbers`; Phases 8-10 → `pipelinq-manifest-i18n-tenant` (Phase 9 runtime tenancy/i18n deferred there, blocked on nc-vue + OR prerequisites). The `pipelinq-or-adoption` capability spec is synced to `openspec/specs/`. No uncovered scope.

# Proposal: pipelinq — adopt OpenRegister abstractions

## Problem

The 2026-05-03 OR-abstraction audit places pipelinq at **Tier 2-3**: Tier 3 on the frontend
(it is the reference implementation for `createObjectStore` — KEEP) but Tier 2 on the backend,
with the **highest density** of register-resolver and magic-number findings in this audit batch.

Specific problems driving this change:

1. **Eight register-resolver call sites** — `QueueService` (4), `DefaultQueueService` (2),
   `ContactVcardService`, `ContactVcardWriterService` all call
   `$appConfig->getValueString(APP_ID, 'register', '')` directly, bypassing the
   `RegisterResolverService` abstraction.

2. **Dutch state literals** baked into service and job classes — `'status' => 'gepubliceerd'|'nieuw'`
   and `'visibility' => 'openbaar'` in `KennisbankService`, `KennisbankReviewJob`,
   `PublicKennisbankController`; `'status' => 'scheduled'` in `CalendarSyncService`;
   `'status' => 'open'` in `CallbackController`; `'status' => 'skipped'|'failure'` in
   `AutomationService`. These should be lifecycle annotation transitions, not inline writes.

3. **Direct notification calls** — `NotificationService:405-412` and `ActivityService:291`
   use `notificationManager->notify()` / `setSubject()` directly instead of
   `x-openregister-notifications` annotations.

4. **Twelve hardcoded constants** across seven background jobs and two service classes —
   timing intervals (`KennisbankReviewJob`, `QueueOverflowJob`, `TaskExpiryJob`,
   `TaskEscalationJob`), business hours (`TaskService:73,80`), cache TTL
   (`ProspectDiscoveryService`), and third-party API base URLs (`KvkApiClient`,
   `OpenCorporatesApiClient`). None of these are tenant-tunable, which blocks multi-tenant
   SLA customisation and regional endpoint configuration.

5. **Open questions in existing specs** — `openspec/specs/lead-management/spec.md:1024` leaves
   the qualification-score computation unresolved; lines 505/519/924 have staleness, aging,
   and lead-value computations that are calculation annotation candidates.
   `openspec/specs/contacts-sync/spec.md` describes a bespoke NC Contacts sync that should
   instead consume OR's `contacts-actions` integration provider (`ContactMatchingService`).

6. **No app manifest** and no formal record of pipelinq's exemplar status.

## Solution

Migrate pipelinq's backend to OR abstractions in ten sequential phases:

1. **Register-resolver** — replace all eight `getValueString(APP_ID, 'register', '')` call
   sites with `RegisterResolverService::resolve()`.
2. **Lifecycle annotations** — migrate Dutch state literals on the kennisbank, calendar-sync,
   callback, and automation-run schemas to `x-openregister-lifecycle`.
3. **Notification annotations** — replace the two direct notification call sites with
   `x-openregister-notifications` triggers on the relevant schemas.
4. **Archival annotation** — inventory and declare `x-openregister-archival.retention` on
   schemas that need Archiefwet-compliant retention.
5. **Calculation annotations** — resolve lead-management open question and annotate
   staleness/aging/lead-value as `x-openregister-calculations`.
6. **Spec rewrites** — rewrite `contacts-sync` spec to consume `ContactMatchingService`;
   add calculation annotations to `lead-management` spec.
7. **Magic-number → admin-config** — move 12 hardcoded constants to `IAppConfig`-backed
   admin settings. All defaults preserve current behaviour.
8. **App manifest** — create `openspec/manifest.yaml` declaring Tier 3, OR dependency,
   and pipelinq's `object-store-exemplar` role.
9. **Multi-tenancy + i18n adoption** — formalise `multi-tenancy-context` dependency and
   adopt `i18n-source-of-truth` / `i18n-api-language-negotiation` for translatable fields.
10. **createObjectStore exemplar spec** — add an explicit Requirement stating that
    `src/store/modules/object.js` is the reference implementation so future audits
    cite it and do not re-investigate.

## Scope

- Migrate 8 register-resolver call sites in `lib/Service/` and implicitly related jobs.
- Declare lifecycle annotations on kennisartikel, calendarLink, callback (request), and
  automationLog schemas.
- Declare notification annotations on task, lead, and callback schemas.
- Move 12 hardcoded constants to admin-config (timing, business-hours, cache TTL, API URLs).
- Rewrite `openspec/specs/contacts-sync/spec.md`.
- Add calculation annotations to `openspec/specs/lead-management/spec.md`.
- Create `openspec/manifest.yaml`.
- Create a new `pipelinq-or-adoption` capability spec.

## Out of scope

- Replacing `createObjectStore` usage in `src/store/modules/object.js`. This is the **exemplar**
  — KEEP as-is.
- Replacing `KvkApiClient` or `OpenCorporatesApiClient` as clients. Only their base URLs move
  to admin-config.
- Re-opening `adr-000` (already reframed by Phase 1 PR #315). Cite; do not repeat.
- Touching `openspec/specs/openregister-integration/spec.md` (CURRENT, exemplar). Link only.
- Building a visual n8n workflow editor or any other unrelated CRM feature.

## Audit citations

- `.claude/audit-2026-05-03/01-code-cleanup.md` — stream 1 (keep `createObjectStore`)
- `.claude/audit-2026-05-03/02-spec-rewrite.md` — stream 2 (contacts-sync REWRITE,
  lead-management calc annotations)
- `.claude/audit-2026-05-03/04-hardcoded.md` — stream 4 (8 resolver sites + 12 magic numbers
  + Dutch state literals)
- `hydra/openspec/architecture/ADR-022.md` — lifecycle annotations
- `hydra/openspec/architecture/ADR-024.md` — archival annotations
- `hydra/openspec/architecture/ADR-025.md` — notification annotations
