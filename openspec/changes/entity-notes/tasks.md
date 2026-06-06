# Tasks: entity-notes

## 0. Deduplication Check

- [x] 0.1 Search `openspec/specs/` and `lib/Service/` for any existing activity query, notes API, or communication history implementation.
  Check: `ObjectService`, `CnObjectSidebar`, `CnNotesCard`, `relationsPlugin`, existing `NotesController` or `ActivityController`.
  Document findings below before writing any new code.
  **Expected finding:** `omnichannel-registratie` creates contactmomenten; no existing activity aggregation API or entity-level communication history panel exists. `CnObjectSidebar` notes tab is a platform capability not yet wired to entity detail views.

  **Actual findings (2026-06-07):**
  - `lib/Service/ActivityService.php` already exists but publishes to the Nextcloud `IManager` Activity Stream (lead/request created/assigned/note-added/deal-won events). Different surface and purpose than this spec's REST aggregation. To avoid a name collision the new service in this change is implemented as `EntityActivityService` (and the controller as `EntityActivityController`); URL path and behaviour remain exactly as specified.
  - `lib/Service/ActivityTimelineService.php` + `lib/Controller/ActivityTimelineController.php` already exist and expose `/api/timeline` and `/api/worklog`, aggregating contactmomenten + tasks + emailLinks + calendarLinks. This change adds a narrower, single-purpose contactmoment-only REST endpoint matching the v1 wire-format in this spec (`/api/activity/{entityType}/{entityId}` with `total/page/pages/results`).
  - `lib/Controller/NotesController.php` + `lib/Service/NotesService.php` already implement `/api/notes/{objectType}/{objectId}` CRUD over the OpenRegister `notes` field. `CnObjectSidebar` already renders the Notes tab in all four entity detail views via `sidebarProps()` (verified in `ClientDetail.vue`, `ContactDetail.vue`, `LeadDetail.vue`, `RequestDetail.vue` — each passes `register`, `schema`, `title`). Task 7.1 is therefore a verification (no code change).
  - `src/components/EntityNotes.vue` and `src/components/ActivityTimeline.vue` already exist; `ActivityTimeline` is multi-source and filter-driven. The new `CommunicationHistory.vue` is a focused, paginated, contactmoment-only panel backed by the new REST endpoint (per spec).
  - OR object API in pipelinq uses `ObjectService::findAll(['filters' => [...], 'limit' => ...])` everywhere (see `QueueService`, `ActivityTimelineService`, `ProspectDiscoveryService`). The `findObjects($register, $schema, $params)` signature mentioned in the task is not part of the real OR API (cf. [[or-objectservice-api]]). Implementation uses `findAll()` with a filters array; method name in `EntityActivityService` is kept as `getActivity()` and the spec contract is preserved.
  - Five `contactmoment-rapportage-seed-*` objects already exist in `lib/Settings/pipelinq_register.json` but no seed client/contact/lead/request objects do. The five new objects use the `contactmoment-001 ... contactmoment-005` slugs from the spec. A single seed client (`client-entity-notes-demo`) is added alongside so the communication-history panel can be exercised against a real entity on first install (smoke test 10.1).

## 1. Seed Data

- [ ] 1.1 Add 5 `contactmoment` seed objects to `lib/Settings/pipelinq_register.json` using the `@self` envelope (slugs: `contactmoment-001` through `contactmoment-005`).
  Values per design.md Seed Data section — Dutch municipality context, varied channels (telefoon, e-mail, balie, chat, brief).
- [ ] 1.2 Verify idempotency: re-importing with `force: false` MUST NOT create duplicates (matched by slug).

## 2. Backend: ActivityService

- [ ] 2.1 Create `lib/Service/ActivityService.php`.
  - Constructor: inject `ObjectService`, `IUserSession`, `LoggerInterface`. Use `private readonly`.
  - `getActivity(string $entityType, string $entityId, string $type, int $page, int $limit): array`
    — Validates `$entityType` against allowlist `['client', 'contact', 'lead', 'request']`.
    — Queries contactmomenten via `ObjectService::findObjects($register, $schema, [$entityType => $entityId, '_page' => $page, '_limit' => $limit])`.
    — Returns `['total' => ..., 'page' => ..., 'pages' => ..., 'results' => [...]]`.
  - MUST NOT call mappers directly (ADR-003-backend).
  - Add `@spec openspec/changes/entity-notes/tasks.md#task-2` PHPDoc to class and method.

## 3. Backend: ActivityController

- [ ] 3.1 Create `lib/Controller/ActivityController.php`.
  - Route: `GET /api/activity/{entityType}/{entityId}`
  - Annotations: `@NoAdminRequired`
  - Query params: `type` (default: `all`), `_page` (default: 1), `_limit` (default: 20)
  - Delegates all logic to `ActivityService::getActivity()`
  - Returns `JSONResponse` with shape `{total, page, pages, results}`
  - Error handling: unknown `entityType` → `JSONResponse(['message' => 'Invalid entity type'], 400)`
  - NEVER returns `$e->getMessage()` to the response — log it and return generic message (ADR-015-common-patterns)
  - Add `@spec openspec/changes/entity-notes/tasks.md#task-3` PHPDoc.
- [ ] 3.2 Add SPDX header `// SPDX-License-Identifier: EUPL-1.2` after `<?php` on both new PHP files.

## 4. Routes

- [ ] 4.1 Add to `appinfo/routes.php`:
  ```php
  ['name' => 'activity#index', 'url' => '/api/activity/{entityType}/{entityId}', 'verb' => 'GET'],
  ```
  Place BEFORE any wildcard `{slug}` routes (ADR-003-backend).

## 5. Frontend: CommunicationHistory Component

- [ ] 5.1 Create `src/components/CommunicationHistory.vue`.
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line.
  - Props: `entityType` (String, required), `entityId` (String, required).
  - Data: `items`, `loading`, `page`, `total`.
  - `mounted()` calls `fetchHistory()`.
  - `fetchHistory()`: GET `/api/activity/{entityType}/{entityId}?type=contactmomenten&_page={page}&_limit=10`.
    Uses `axios` from `@nextcloud/axios`. Wrapped in `try/catch` with `this.$toast.error(...)` on failure.
  - Template: `CnDetailCard` with `header-actions` slot (Refresh button), `CnDataTable` for items, `CnPagination` for pagination, `CnEmptyState` for empty state, `NcLoadingIcon` during loading.
  - Columns: channel, subject, agent, contactedAt.
  - Row click navigates to `ContactmomentDetail` route.
  - All user-visible strings via `this.t('pipelinq', 'key')` — NEVER hardcoded (ADR-007-i18n).
  - Scoped `<style scoped>` block using only `var(--color-*)` tokens (ADR-010-nl-design).
  - EVERY imported component MUST be in `components: {}` (ADR-015-common-patterns).
  - Import from `@conduction/nextcloud-vue` ONLY — NEVER `@nextcloud/vue` (ADR-004-frontend).

## 6. Detail View Integration

- [ ] 6.1 Add `CommunicationHistory` to `src/views/clients/ClientDetail.vue`:
  ```vue
  <CommunicationHistory
    v-if="!isNew && !loading && !editing"
    entity-type="client"
    :entity-id="entityId" />
  ```
  Import component and register in `components: {}`.

- [ ] 6.2 Add `CommunicationHistory` to `src/views/contacts/ContactDetail.vue` with `entity-type="contact"`.

- [ ] 6.3 Add `CommunicationHistory` to `src/views/leads/LeadDetail.vue` with `entity-type="lead"`.

- [ ] 6.4 Add `CommunicationHistory` to `src/views/requests/RequestDetail.vue` with `entity-type="request"`.

## 7. CnObjectSidebar Notes Tab

- [ ] 7.1 Verify each entity detail view (`ClientDetail`, `ContactDetail`, `LeadDetail`, `RequestDetail`) correctly passes `objectSidebarState` so `CnObjectSidebar` renders the Notes tab.
  If the `sidebarState` is not injected: add `inject: ['sidebarState']` and pass it to `CnDetailPage`.
  This is a configuration check — no new components needed.

## 8. i18n

- [ ] 8.1 Add English keys to `l10n/en.json`:
  `Communication History`, `No communication history yet`, `Refresh`, `Channel`, `Subject`, `Agent`, `Date`, `Invalid entity type`.
- [ ] 8.2 Add Dutch translations to `l10n/nl.json`:
  `Communicatiegeschiedenis`, `Nog geen communicatiegeschiedenis`, `Vernieuwen`, `Kanaal`, `Onderwerp`, `Medewerker`, `Datum`, `Ongeldig entiteitstype`.

## 9. Pre-commit Verification

- [ ] 9.1 SPDX headers: `grep -rL 'SPDX-License-Identifier' lib/Controller/ActivityController.php lib/Service/ActivityService.php src/components/CommunicationHistory.vue` → must return no files.
- [ ] 9.2 ObjectService call signature: verify `ActivityService` uses 3-arg `findObjects($register, $schema, $params)` — no 1-arg form.
- [ ] 9.3 Error responses: `grep -rn 'getMessage()' lib/Controller/ActivityController.php` → must return zero matches.
- [ ] 9.4 Import source: `grep -rn "from '@nextcloud/vue'" src/` → must be zero matches.
- [ ] 9.5 Component imports: for every `<NcFoo>` or `<CnFoo>` in `CommunicationHistory.vue`, verify import AND `components: {}` entry.
- [ ] 9.6 Translation keys: all `t()` keys in `CommunicationHistory.vue` MUST be English strings.
- [ ] 9.7 Run `npm run build` — must complete with no errors.

## 10. Smoke Testing

- [ ] 10.1 Call `GET /api/activity/client/{uuid}` with a valid client UUID — verify `200` response with `total`, `page`, `pages`, `results` fields.
- [ ] 10.2 Call `GET /api/activity/client/{uuid}?type=contactmomenten` — verify only contactmoment items returned.
- [ ] 10.3 Call `GET /api/activity/unknown/{uuid}` — verify `400` response with `{"message": "Invalid entity type"}` and no stack trace.
- [ ] 10.4 Open a client detail page in the browser — verify Communication History section renders (empty state or items).
- [ ] 10.5 Open the Notes sidebar tab on a client detail page — verify notes can be created and deleted.

## 11. Verification

- [ ] 11.1 All tasks above checked off
- [ ] 11.2 All spec scenarios (REQ-ENT-001 through REQ-ENT-003) verified manually or via browser test
- [ ] 11.3 Seed contactmomenten visible in communication history panel on a client detail page after install
