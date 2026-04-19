<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: notifications-activity (Notifications Activity)
     This spec extends the existing `notifications-activity` capability. Do NOT define new entities or build new CRUD — reuse what `notifications-activity` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Tasks: activity-timeline

## 0. Deduplication Check

- [x] 0.1 Verify no overlap with `CnAuditTrailTab` / `AuditTrailService`: audit trail tracks OpenRegister object property changes (before/after snapshots); the activity timeline tracks CRM-level interactions (contactmomenten, tasks, emails). Different data sources and purpose — document finding in PR description.
- [x] 0.2 Verify no overlap with `ActivityService` (notifications-activity change): that service publishes to the Nextcloud Activity app (IManager); this change exposes a REST API for external query access. Different output targets — document finding.
- [x] 0.3 Verify no overlap with `ObjectService` CRUD endpoints: the timeline controller is a read-aggregation layer on top of existing ObjectService queries, not a CRUD wrapper. Worklog creation delegates to ObjectService.saveObject — document finding.
- [x] 0.4 Search `openspec/specs/` for existing timeline or worklog spec files. If found, reference rather than duplicate.

## 1. Backend: ActivityTimelineService

- [x] 1.1 Create `lib/Service/ActivityTimelineService.php`
  - Constructor: inject `ObjectService`, `IAppConfig`, `IUserSession`, `LoggerInterface`
  - SPDX header: `// SPDX-License-Identifier: EUPL-1.2`
  - File-level `@spec openspec/changes/activity-timeline/tasks.md#task-1`
- [x] 1.2 Implement `getTimeline(string $entityType, string $entityId, array $params): array`
  - Resolve filter params per schema using `resolveEntityQueryParams()`
  - Call `ObjectService.findObjects($register, $schema, $filterParams)` for each applicable schema — 3 positional args, register first
  - Normalise each result with `normalizeActivity()`
  - Merge arrays, sort by `date` descending, apply `_page`/`_limit` pagination
  - Return `{items, total, page, pages}`
- [x] 1.3 Implement `normalizeActivity(string $type, array $object): array`
  - Map fields per type to unified format: `{type, id, title, description, date, user, entityType, entityId, metadata}`
  - `contactmoment` → title=subject, description=summary, date=contactedAt, user=agent, metadata={channel, duration, outcome}
  - `task` → title=subject, description=description, date=deadline, user=assigneeUserId
  - `emailLink` → title=subject, description=sender, date=date, user=null
  - `calendarLink` → title=title, description=notes, date=startDate, user=null
- [x] 1.4 Implement `resolveEntityQueryParams(string $entityType, string $entityId): array`
  - Returns per-schema filter arrays based on entity type (see design.md mapping table)
  - `client` → contactmoment: [client=entityId], task: [clientId=entityId], emailLink/calendarLink: [linkedEntityType=client, linkedEntityId=entityId]
  - `request` → contactmoment: [request=entityId], task: [requestId=entityId], emailLink/calendarLink: [linkedEntityType=request, linkedEntityId=entityId]
  - `lead` / `contact` → emailLink/calendarLink only (linkedEntityType=entityType, linkedEntityId=entityId)
- [x] 1.5 Implement `createWorklog(string $entityType, string $entityId, array $data): array`
  - Build contactmoment array: channel='worklog', summary=$data['description'], duration=$data['duration'], contactedAt=$data['date']
  - Set `client` or `request` field from entityType/entityId
  - Set `agent` from `IUserSession->getUser()->getUID()` — NEVER from request data
  - Call `ObjectService.saveObject($register, $contactmomentSchema, $object)` — 3 positional args
- [x] 1.6 Implement `getWorklog(string $entityType, string $entityId, array $params): array`
  - Query contactmomenten with `channel=worklog` + entity filter
  - Sum all duration fields (ISO 8601 parse + accumulate) → `totalDuration` in response
  - Return `{items, total, page, pages, totalDuration}`

## 2. Backend: ActivityTimelineController

- [x] 2.1 Create `lib/Controller/ActivityTimelineController.php`
  - Extend `Controller`. Constructor: inject `ActivityTimelineService`, `IRequest`, `LoggerInterface`
  - All methods annotated `@NoAdminRequired` (authenticated users, not admin-only)
  - SPDX header and `@spec` PHPDoc tag
- [x] 2.2 Implement `getTimeline(): JSONResponse`
  - Read `entityType`, `entityId`, `from`, `to`, `types[]`, `_page`, `_limit` from `$this->request`
  - Validate `entityType` and `entityId` present — return 400 with static message if missing
  - Wrap service call in `try/catch (\Throwable $e)` — return 500 with static `{ message: "Failed to load timeline" }`, log full exception
  - Return 200 with merged activity data
- [x] 2.3 Implement `getWorklog(): JSONResponse`
  - Read `entityType`, `entityId`, `_page`, `_limit` from request
  - Validate required params — return 400 if missing
  - Return 200 with paginated worklog entries + `totalDuration`
- [x] 2.4 Implement `createWorklog(): JSONResponse`
  - Read `entityType`, `entityId`, `duration`, `description`, `date` from request body
  - Validate required fields (`entityType`, `entityId`, `duration` required) — return 400 with static message
  - Call `ActivityTimelineService.createWorklog()` — return 201 on success
  - Catch `\Throwable` — return 500 with static message, log exception

## 3. Routes

- [x] 3.1 Add to `appinfo/routes.php`:
  ```php
  ['name' => 'ActivityTimeline#getTimeline',  'url' => '/api/timeline',  'verb' => 'GET'],
  ['name' => 'ActivityTimeline#getWorklog',   'url' => '/api/worklog',   'verb' => 'GET'],
  ['name' => 'ActivityTimeline#createWorklog','url' => '/api/worklog',   'verb' => 'POST'],
  ```
  Place BEFORE any existing wildcard `{slug}` catch-all routes.

## 4. Frontend: ActivityTimeline Component

- [x] 4.1 Create `src/components/ActivityTimeline.vue`
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - Props: `entityType` (String, required), `entityId` (String, required)
  - On mount: fetch `GET /api/timeline?entityType=&entityId=` via `axios` from `@nextcloud/axios`
  - Display items in chronological order (newest first)
- [x] 4.2 Add type filter bar with buttons: "All" | "Contactmomenten" | "Taken" | "Email" | "Agenda"
  - Updates `types[]` query parameter and re-fetches
  - Active filter button visually distinguished (NOT by colour alone — use border/weight for WCAG AA)
- [x] 4.3 Display each item: `CnIcon` (MDI) per type, title, truncated description (max 120 chars), relative date, user
  - contactmoment: `mdiPhone` / `mdiEmail` / `mdiMessage` (by channel)
  - task: `mdiCheckCircle`
  - email: `mdiEmailOutline`
  - calendar: `mdiCalendar`
- [x] 4.4 Show `CnEmptyState` when `items` is empty after fetch (not during loading)
- [x] 4.5 Add "Load more" button shown when `page < pages` — appends next page items to list
- [x] 4.6 Wrap all API calls in `try/catch` — show error feedback via `NcDialog` on failure
- [x] 4.7 All user-visible strings via `this.t('pipelinq', 'key')` — add entries to `l10n/en.json` and `l10n/nl.json`:
  - `"Activity"`, `"All"`, `"Contact moments"`, `"Tasks"`, `"Email"`, `"Calendar"`, `"No activities yet"`, `"Load more"`, `"Failed to load activities"`
- [x] 4.8 Import ALL components from `@conduction/nextcloud-vue` (NOT from `@nextcloud/vue` directly)
- [x] 4.9 Register every component used in `<template>` in `components: {}` — Vue 2 silently drops unregistered components

## 5. Frontend: Embed in Detail Pages

- [x] 5.1 In `src/views/clients/ClientDetail.vue`: add `<ActivityTimeline :entity-type="'client'" :entity-id="client.id" />` wrapped in `CnDetailCard` with title `t('pipelinq', 'Activity')`
  - Import `ActivityTimeline` from `@/components/ActivityTimeline.vue`
  - Add to `components: {}` 
- [x] 5.2 In `src/views/leads/LeadDetail.vue`: same pattern with `entity-type="'lead'"`
- [x] 5.3 In `src/views/requests/RequestDetail.vue`: same pattern with `entity-type="'request'"`
- [x] 5.4 Verify `CnDetailCard` wrapping is correct — `ActivityTimeline` is a custom component (not self-contained), so wrapping is required (ADR-017)

## 6. Verification

- [x] 6.1 Run `npm run build` — verify zero build errors
- [x] 6.2 Run `composer check:strict` — verify zero PHP errors or type errors
- [x] 6.3 Smoke test `GET /api/timeline?entityType=client&entityId={uuid}` with `curl` — verify 200 and correct response shape
- [x] 6.4 Smoke test `POST /api/worklog` with `curl` — verify 201 and contactmoment created with `channel=worklog`
- [x] 6.5 Smoke test `GET /api/worklog?entityType=request&entityId={uuid}` — verify only worklog entries returned
- [x] 6.6 Test error paths: 401 (no auth), 400 (missing entityType), 400 (missing entityId on POST), 500 (simulated service failure)
- [x] 6.7 Manual browser test: open a client detail page — verify "Activity" section renders with timeline items
- [x] 6.8 Manual browser test: click each filter button — verify timeline updates without page reload
- [x] 6.9 Run SPDX header check: `grep -rL 'SPDX-License-Identifier' lib/Service/ActivityTimeline* lib/Controller/ActivityTimeline* src/components/ActivityTimeline.vue` — MUST return zero files
- [x] 6.10 Verify ObjectService calls use 3 positional args: `grep -n 'findObjects\|saveObject\|findObject' lib/Service/ActivityTimelineService.php` — each call MUST have 3 args
- [x] 6.11 Verify no `$e->getMessage()` in responses: `grep -n 'getMessage()' lib/Controller/ActivityTimelineController.php` — MUST return zero matches
