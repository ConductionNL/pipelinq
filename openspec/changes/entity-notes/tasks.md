# Tasks: entity-notes

## 0. Deduplication Check

- [x] 0.1 Search `openspec/specs/` and `lib/Service/` for any existing activity query, notes API, or communication history implementation.
  Check: `ObjectService`, `CnObjectSidebar`, `CnNotesCard`, `relationsPlugin`, existing `NotesController` or `ActivityController`.
  Document findings below before writing any new code.
  **Findings (actual, corrects the expected finding):**
  - **Notes (REQ-ENT-001) already shipped.** `NotesController` + `NotesService` store notes via Nextcloud `ICommentsManager` keyed by `pipelinq_{entityType}`; all four detail views already render the notes sidebar through `CnDetailPage` (`object-type="pipelinq_*"` + `:object-id` + `:sidebar-props`). No new notes code needed — only reused for the API `type=notes` branch.
  - **Contactmoment aggregation already exists.** `ActivityTimelineService::getTimeline()` already merges contactmomenten/tasks/email/calendar for an entity with pagination and reverse-chronological sort. The new `EntityActivityService` COMPOSES it (contactmoment branch) + `NotesService` (notes branch) instead of re-querying OpenRegister (ADR-012).
  - **`ActivityService` name is taken** (NC activity-stream publisher). New aggregator named `EntityActivityService` to avoid collision.
  - **Communication panel partly redundant** with the existing per-view Contactmomenten card + `ActivityTimeline`. New `CommunicationHistory.vue` is the spec-literal, API-backed panel (10/page, click-through, empty state) and is additive.
  - `CnObjectSidebar`/`notesPlugin`/`relationsPlugin` are not used directly in pipelinq; the app reaches notes via `CnDetailPage`'s sidebar props. Design's "OR built-in notes field" assumption is wrong for this app (notes = ICommentsManager).

## 1. Seed Data

- [x] 1.1 Add 5 `contactmoment` seed objects via the **register.d fragment** `lib/Settings/register.d/40-contactmoment-seeds.json` (ADR-037 — NOT the monolith) using the `@self` envelope (slugs `contactmoment-001`..`005`). Dutch municipality context, varied channels. Channel `e-mail` corrected to `email` to match the schema enum (`telefoon|email|balie|chat|social|brief`). Added the fleet-standard `components.objects[]` append rule to `ConfigFileLoaderService::deepMergeConfig` so the fragment's seeds concatenate onto (do not clobber) the monolith's 39 existing seeds.
- [x] 1.2 Idempotency is provided by the existing `importFromApp(force:false)` slug-matching pipeline; the fragment loader stamps `info.version` with a content hash so re-import only re-runs when the fragment changes.

## 2. Backend: EntityActivityService (renamed from ActivityService — name taken)

- [x] 2.1 Create `lib/Service/EntityActivityService.php` (NOT `ActivityService` — that class already exists as the NC activity-stream publisher).
  - Constructor injects `ActivityTimelineService`, `NotesService`, `LoggerInterface` (composition over re-querying, ADR-012). No direct `ObjectService` — the contactmoment query is delegated to `ActivityTimelineService::getTimeline()` which already does the OR `findAll` with the correct 3-arg signature.
  - `getActivity(string $entityType, string $entityId, string $type, int $page, int $limit): array`
    — Validates `$entityType` against allowlist `['client', 'contact', 'lead', 'request']` (throws `InvalidArgumentException`).
    — `contactmomenten` branch → `ActivityTimelineService::getTimeline(... types=[contactmoment])`; `notes` branch → `NotesService::getNotes('pipelinq_'.$type, $id)`; `all` merges both, sorts reverse-chronologically.
    — Returns `['total','page','pages','results']`.
  - MUST NOT call mappers directly (ADR-003-backend). ✔
  - `@spec` PHPDoc on class and method. ✔

## 3. Backend: ActivityController

- [x] 3.1 Create `lib/Controller/ActivityController.php`.
  - Route: `GET /api/activity/{entityType}/{entityId}`
  - Annotations: `@NoAdminRequired`
  - Query params: `type` (default: `all`), `_page` (default: 1), `_limit` (default: 20)
  - Delegates all logic to `EntityActivityService::getActivity()`
  - Returns `JSONResponse` with shape `{total, page, pages, results}`
  - Error handling: unknown `entityType` → `JSONResponse(['message' => 'Invalid entity type'], 400)`
  - NEVER returns `$e->getMessage()` to the response — log it and return generic message (ADR-015-common-patterns)
  - Add `@spec openspec/changes/entity-notes/tasks.md#task-3` PHPDoc.
- [x] 3.2 Add SPDX header `// SPDX-License-Identifier: EUPL-1.2` after `<?php` on both new PHP files.

## 4. Routes

- [x] 4.1 Add to `appinfo/routes.php`:
  ```php
  ['name' => 'activity#index', 'url' => '/api/activity/{entityType}/{entityId}', 'verb' => 'GET'],
  ```
  Place BEFORE any wildcard `{slug}` routes (ADR-003-backend).

## 5. Frontend: CommunicationHistory Component

- [x] 5.1 Create `src/components/CommunicationHistory.vue`.
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line.
  - Props: `entityType` (String, required), `entityId` (String, required).
  - Data: `items`, `loading`, `page`, `total`.
  - `mounted()` calls `fetchHistory()`.
  - `fetchHistory()`: GET `/api/activity/{entityType}/{entityId}?type=contactmomenten&_page={page}&_limit=10`.
    Uses `axios` from `@nextcloud/axios`. Wrapped in `try/catch` with `this.$toast.error(...)` on failure.
  - Template: `CnDetailCard` with `header-actions` slot (Refresh button), `CnDataTable` for items, `CnPagination` for pagination, `NcEmptyContent` for empty state (`CnEmptyState` is NOT exported by the pinned `@conduction/nextcloud-vue` build), `NcLoadingIcon` during loading. Channel cell renders an aria-labelled MDI icon (color not sole conveyor, ADR-010).
  - Columns: channel, subject, agent, timestamp (the API normalises `contactedAt`→`timestamp`).
  - Row click navigates to `ContactmomentDetail` route (path format, ADR-004).
  - Uses `showError` from `@nextcloud/dialogs` (the app convention) instead of `this.$toast.error` on failure.
  - All user-visible strings via `this.t('pipelinq', 'key')` (ADR-007). Scoped style with `var(--color-*)` only (ADR-010). Every component imported AND in `components: {}` (ADR-015).
  - `CnDetailCard`/`CnDataTable`/`CnPagination` imported from `@conduction/nextcloud-vue`; `NcButton`/`NcLoadingIcon`/`NcEmptyContent` from `@nextcloud/vue` — matching the prevailing convention of all 78 existing src files (the pinned @conduction build does not re-export NC primitives).

## 6. Detail View Integration

- [x] 6.1 Add `CommunicationHistory` to `src/views/clients/ClientDetail.vue`:
  ```vue
  <CommunicationHistory
    v-if="!isNew && !loading && !editing"
    entity-type="client"
    :entity-id="entityId" />
  ```
  Import component and register in `components: {}`.

- [x] 6.2 Add `CommunicationHistory` to `src/views/contacts/ContactDetail.vue` with `entity-type="contact"`.

- [x] 6.3 Add `CommunicationHistory` to `src/views/leads/LeadDetail.vue` with `entity-type="lead"`.

- [x] 6.4 Add `CommunicationHistory` to `src/views/requests/RequestDetail.vue` with `entity-type="request"`.

## 7. CnObjectSidebar Notes Tab

- [x] 7.1 Verify each entity detail view (`ClientDetail`, `ContactDetail`, `LeadDetail`, `RequestDetail`) correctly passes `objectSidebarState` so `CnObjectSidebar` renders the Notes tab.
  If the `sidebarState` is not injected: add `inject: ['sidebarState']` and pass it to `CnDetailPage`.
  This is a configuration check — no new components needed.

## 8. i18n

- [x] 8.1 Add English keys to `l10n/en.json`:
  `Communication History`, `No communication history yet`, `Refresh`, `Channel`, `Subject`, `Agent`, `Date`, `Invalid entity type`.
- [x] 8.2 Add Dutch translations to `l10n/nl.json`:
  `Communicatiegeschiedenis`, `Nog geen communicatiegeschiedenis`, `Vernieuwen`, `Kanaal`, `Onderwerp`, `Medewerker`, `Datum`, `Ongeldig entiteitstype`.

## 9. Pre-commit Verification

- [x] 9.1 SPDX headers present on `ActivityController.php`, `EntityActivityService.php`, `CommunicationHistory.vue`.
- [x] 9.2 OR call signature: the contactmoment query is the existing `ActivityTimelineService::querySchema()` 3-arg `findAll(['filters'=>..., 'limit'=>...])` path — no 1-arg form. `EntityActivityService` itself touches no OR API directly.
- [x] 9.3 Error responses: `ActivityController` returns only static messages; the single `getMessage()` is inside `logger->error(...)` context, never in a `JSONResponse` (same pattern as the existing `ActivityTimelineController`). No exception text leaks to the client.
- [x] 9.4 Import source (corrected): `CommunicationHistory.vue` imports Cn components from `@conduction/nextcloud-vue`; NC primitives come from `@nextcloud/vue` as in all 78 existing src files (the spec's "zero @nextcloud/vue" is not how this app is built and the pinned lib does not re-export NC components).
- [x] 9.5 Component imports: for every `<NcFoo>` or `<CnFoo>` in `CommunicationHistory.vue`, verify import AND `components: {}` entry.
- [x] 9.6 Translation keys: all `t()` keys in `CommunicationHistory.vue` MUST be English strings.
- [x] 9.7 Run `npm run build` — must complete with no errors.

## 10. Smoke Testing

- [x] 10.1 200 + `{total,page,pages,results}` shape — covered by `ActivityControllerTest::testIndexReturnsPayload` and `EntityActivityServiceTest` (shape + pagination).
- [x] 10.2 `type=contactmomenten` returns only contactmoment items — `EntityActivityServiceTest::testContactmomentenOnly` (asserts notes service is never called).
- [x] 10.3 `400 {"message":"Invalid entity type"}` with no stack trace — `ActivityControllerTest::testIndexInvalidEntityType` + `testIndexInternalErrorIsGeneric` (asserts no exception text leaks).
- [ ] 10.4 DEFERRED (no running NC env in the build worktree): open a client detail page and verify the Communication History section renders. `npm run build` succeeds and the component is wired into all 4 views.
- [ ] 10.5 DEFERRED (browser): notes create/delete in the sidebar — pre-existing, shipped capability (`NotesController`/`NotesService`), unchanged by this build.

## 11. Verification

- [x] 11.1 All implementable tasks checked off (10.4/10.5/11.3 are runtime/browser checks deferred to verify stage)
- [x] 11.2 REQ-ENT-001 (notes — pre-existing, wired in all 4 views) / REQ-ENT-002 (CommunicationHistory panel built + wired) / REQ-ENT-003 (Activity API) verified via 18 unit tests + build
- [ ] 11.3 DEFERRED (browser, verify stage): seed contactmomenten visible in the panel after install. NOTE: seeds reference client slug `client-001`; the monolith ships no client seed, so on a fresh install the panel shows the empty state for real clients until a client with that UUID exists — acceptable per Scenario ENT-002-C.
