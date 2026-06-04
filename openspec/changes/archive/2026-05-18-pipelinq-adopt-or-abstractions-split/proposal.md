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
