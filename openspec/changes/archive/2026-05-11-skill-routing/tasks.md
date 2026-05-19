# Tasks: skill-routing

## 0. Deduplication Check

- [ ] 0.1 Verify no overlap with existing `ObjectService` CRUD: `RoutingService` is a read-aggregation layer on top of `findObjects()` — it does not duplicate CRUD. Document finding in PR description.
- [ ] 0.2 Confirm `skill` and `agentProfile` schemas already exist in `lib/Settings/pipelinq_register.json` (defined by `queue-management` capability) — this change MUST NOT redefine them, only add seed objects.
- [ ] 0.3 Search `openspec/specs/` for any existing routing suggestion or skill spec. If found, reference rather than duplicate.
- [ ] 0.4 Verify `DefaultQueueService` exists in `lib/Service/DefaultQueueService.php` — `createDefaultSkills()` extends it rather than creating a parallel service.

## 1. Backend: RoutingService

- [ ] 1.1 Create `lib/Service/RoutingService.php`
  - SPDX header: `// SPDX-License-Identifier: EUPL-1.2`
  - Constructor: inject `ObjectService`, `IAppConfig`, `IUserSession`, `LoggerInterface`
  - File-level and method-level `@spec openspec/changes/skill-routing/tasks.md#task-1`
- [ ] 1.2 Implement `getSuggestedAgents(string $entityType, string $entityId): array`
  - Load entity via `ObjectService.findObject($register, $schema, $entityId)` — 3 positional args
  - Read `category` field from loaded entity
  - Call `findMatchingAgents($category)` → candidate agentProfile objects
  - Call `filterByAvailability($candidates)` → remove unavailable agents
  - Call `filterByCapacity($candidates)` → remove over-limit agents (track count for `atCapacity`)
  - For each remaining profile: call `getAgentWorkload($userId)` and attach
  - Sort by workload ascending; return `{ suggestions, atCapacity, noMatch }`
- [ ] 1.3 Implement `getAgentWorkload(string $userId): int`
  - Query requests: `ObjectService.findObjects($register, $requestSchema, ['assignee' => $userId, '_limit' => 999])` — filter PHP-side to exclude terminal statuses (completed, cancelled, closed)
  - Query leads: `ObjectService.findObjects($register, $leadSchema, ['assignee' => $userId, 'status' => 'open', '_limit' => 999])`
  - Return count(open requests) + count(open leads)
- [ ] 1.4 Implement `findMatchingAgents(string $category): array`
  - Query all active skills: `ObjectService.findObjects($register, $skillSchema, ['isActive' => true])` — 3 positional args
  - Filter to skills whose `categories` array contains `$category`
  - Collect matching skill UUIDs
  - Query all agentProfiles: `ObjectService.findObjects($register, $agentProfileSchema, [])` — 3 positional args
  - Filter PHP-side to profiles where `skills` array intersects collected UUIDs
  - Return matching agentProfile objects
- [ ] 1.5 Implement `filterByAvailability(array $profiles): array`
  - Exclude profiles with `isAvailable === false`
- [ ] 1.6 Implement `filterByCapacity(array $profiles): array`
  - For each profile call `getAgentWorkload($profile['userId'])` and compare to `$profile['maxConcurrent']`
  - Exclude over-limit profiles; track excluded count for `atCapacity` field
- [ ] 1.7 Implement `isAgentAtCapacity(array $profile, int $workload): bool`
  - Return `$workload >= ($profile['maxConcurrent'] ?? 10)`

## 2. Backend: RoutingController

- [ ] 2.1 Create `lib/Controller/RoutingController.php`
  - Extend `Controller`. Constructor: inject `RoutingService`, `IRequest`, `LoggerInterface`
  - Class annotated `#[NoAdminRequired]` (authenticated agents, not admin-only)
  - SPDX header and `@spec` PHPDoc tag on class and method
- [ ] 2.2 Implement `getSuggestions(): JSONResponse`
  - Read `entityType` and `entityId` from `$this->request->getParam()`
  - Validate both present — return `new JSONResponse(['message' => 'entityType and entityId are required'], 400)` if missing
  - Validate `entityType` is one of `request`, `lead` — return `new JSONResponse(['message' => 'Invalid entityType'], 400)` otherwise
  - Wrap service call in `try/catch (\Throwable $e)` — log full exception, return `new JSONResponse(['message' => 'Operation failed'], 500)`; NEVER return `$e->getMessage()`
  - Return `new JSONResponse($result, 200)` on success
- [ ] 2.3 Per-object auth: before executing, verify requesting user is the assignee, a member of an assigned group, or an admin — throw `OCSForbiddenException` if none apply (extract to `authorizeEntity()` service method)

## 3. Backend: DefaultQueueService extension

- [ ] 3.1 Add `createDefaultSkills(): void` to `lib/Service/DefaultQueueService.php`
  - Check if any skills exist: `ObjectService.findObjects($register, $skillSchema, ['_limit' => 1])` — 3 positional args
  - If count > 0: return immediately (idempotent — no duplicates)
  - Create the following 5 skills using `ObjectService.saveObject($register, $skillSchema, $data)` — 3 positional args each:
    1. title: "Algemene Dienstverlening", categories: ["algemeen"], isActive: true
    2. title: "Vergunningen", categories: ["vergunningen", "omgevingsrecht"], isActive: true
    3. title: "Belastingen", categories: ["belastingen"], isActive: true
    4. title: "WMO / Zorg", categories: ["wmo", "zorg"], isActive: true
    5. title: "Klachten", categories: ["klachten"], isActive: true
  - `@spec openspec/changes/skill-routing/tasks.md#task-3`
- [ ] 3.2 Call `$this->createDefaultSkills()` from the repair step method after the existing `createDefaultQueues()` call

## 4. Routes

- [ ] 4.1 Add to `appinfo/routes.php`:
  ```php
  ['name' => 'Routing#getSuggestions', 'url' => '/api/routing/suggestions', 'verb' => 'GET'],
  ```
  Place BEFORE any existing wildcard `{slug}` catch-all routes.

## 5. Seed Data

- [ ] 5.1 Add 5 skill seed objects to `lib/Settings/pipelinq_register.json` under `components.objects[]`:
  - `{ "@self": { "register": "pipelinq", "schema": "skill", "slug": "skill-algemene-dienstverlening" }, "title": "Algemene Dienstverlening", "description": "Algemene vragen en eerstelijnshulp voor alle dienstverleningskanalen", "categories": ["algemeen"], "isActive": true }`
  - `{ "@self": { "register": "pipelinq", "schema": "skill", "slug": "skill-vergunningen" }, "title": "Vergunningen", "description": "Omgevingsvergunningen, kapvergunningen en overige aanvragen", "categories": ["vergunningen", "omgevingsrecht"], "isActive": true }`
  - `{ "@self": { "register": "pipelinq", "schema": "skill", "slug": "skill-belastingen" }, "title": "Belastingen", "description": "Gemeentelijke belastingen, WOZ-bezwaren en kwijtscheldingsverzoeken", "categories": ["belastingen"], "isActive": true }`
  - `{ "@self": { "register": "pipelinq", "schema": "skill", "slug": "skill-wmo-zorg" }, "title": "WMO / Zorg", "description": "WMO-aanvragen, thuiszorg, hulpmiddelen en zorgindicaties", "categories": ["wmo", "zorg"], "isActive": true }`
  - `{ "@self": { "register": "pipelinq", "schema": "skill", "slug": "skill-klachten" }, "title": "Klachten", "description": "Klachten over gemeentelijke dienstverlening en behandeling", "categories": ["klachten"], "isActive": true }`
- [ ] 5.2 Add 4 agentProfile seed objects to `lib/Settings/pipelinq_register.json`:
  - `{ "@self": { "register": "pipelinq", "schema": "agentProfile", "slug": "agentprofile-jan-devries" }, "userId": "jan.devries", "skills": ["skill-vergunningen", "skill-wmo-zorg"], "maxConcurrent": 8, "isAvailable": true }`
  - `{ "@self": { "register": "pipelinq", "schema": "agentProfile", "slug": "agentprofile-lisa-vandenberg" }, "userId": "lisa.vandenberg", "skills": ["skill-belastingen", "skill-algemene-dienstverlening"], "maxConcurrent": 10, "isAvailable": true }`
  - `{ "@self": { "register": "pipelinq", "schema": "agentProfile", "slug": "agentprofile-pieter-bakker" }, "userId": "pieter.bakker", "skills": ["skill-klachten", "skill-algemene-dienstverlening"], "maxConcurrent": 6, "isAvailable": true }`
  - `{ "@self": { "register": "pipelinq", "schema": "agentProfile", "slug": "agentprofile-henk-dekker" }, "userId": "henk.dekker", "skills": ["skill-wmo-zorg", "skill-klachten"], "maxConcurrent": 8, "isAvailable": false }`

## 6. Frontend: SkillSettings.vue

- [ ] 6.1 Create `src/components/admin/SkillSettings.vue`
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - `CnIndexPage` with `useListView('skill', { objectStore: skillStore })`
  - Columns: title, categories (chip list), isActive (CnStatusBadge)
  - `CnActionsBar` "Add skill" → `CnFormDialog` (schema-driven create)
  - Row actions: Edit (`CnFormDialog`), Delete (`CnDeleteDialog`)
  - Import ALL components from `@conduction/nextcloud-vue` — NEVER from `@nextcloud/vue`
  - Register every used component in `components: {}`
- [ ] 6.2 All strings via `this.t('pipelinq', 'key')` — add to `l10n/en.json` and `l10n/nl.json`:
  - `"Skills"`, `"Add skill"`, `"Edit skill"`, `"Delete skill"`, `"Active"`, `"Inactive"`, `"Categories"`, `"No skills defined"`

## 7. Frontend: AgentProfileSettings.vue

- [ ] 7.1 Create `src/components/admin/AgentProfileSettings.vue`
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - `CnIndexPage` with `useListView('agent-profile', { objectStore: agentProfileStore })`
  - Columns: userId, skill count, isAvailable (CnStatusBadge), maxConcurrent
  - Row actions: Edit (`CnFormDialog`), Delete (`CnDeleteDialog`)
  - Import ALL components from `@conduction/nextcloud-vue`
  - Register every used component in `components: {}`
- [ ] 7.2 All strings via `this.t('pipelinq', 'key')` — add to `l10n/en.json` and `l10n/nl.json`:
  - `"Agent profiles"`, `"Add agent profile"`, `"Edit agent profile"`, `"Available"`, `"Unavailable"`, `"Max concurrent"`, `"Assigned skills"`, `"No agent profiles defined"`

## 8. Frontend: Admin Settings integration

- [ ] 8.1 In `src/components/admin/AdminSettings.vue` (or equivalent admin settings root component):
  - Import `SkillSettings` from `@/components/admin/SkillSettings.vue`
  - Import `AgentProfileSettings` from `@/components/admin/AgentProfileSettings.vue`
  - Add both to `components: {}`
  - Wrap each in a `CnSettingsSection` with translated title (`t('pipelinq', 'Skill routing')`)

## 9. Frontend: RoutingSuggestionPanel.vue

- [ ] 9.1 Create `src/components/RoutingSuggestionPanel.vue`
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - Props: `requestId` (String, required), `category` (String, default: `''`)
  - On mount: fetch `GET /api/routing/suggestions?entityType=request&entityId={requestId}` via `axios` from `@nextcloud/axios`
- [ ] 9.2 Display suggestion list:
  - Each row: agent name, workload indicator "N/M items", matched skill badge, "Assign" button
  - "Assign" → `PUT /api/requests/{requestId}` with `{ assignee: userId }` via store action
  - After successful assign: emit `assigned` event
- [ ] 9.3 Show `CnEmptyState` when `suggestions` is empty after fetch (not during loading)
- [ ] 9.4 Show at-capacity notice: "N matching agent(s) at capacity" when `atCapacity > 0`
- [ ] 9.5 Wrap ALL API calls in `try/catch` — show error feedback via `NcDialog` on failure; EVERY `await store.action()` in `try/catch`
- [ ] 9.6 Strings via `this.t('pipelinq', 'key')` — add to `l10n/en.json` and `l10n/nl.json`:
  - `"Suggested agents"`, `"Assign"`, `"No agents with matching skills"`, `"matching agent(s) at capacity"`, `"items"`, `"Failed to load suggestions"`
- [ ] 9.7 Import ALL components from `@conduction/nextcloud-vue`. Register all used components in `components: {}`
- [ ] 9.8 Keyboard navigable: "Assign" buttons reachable via Tab, activatable via Enter/Space. Workload/availability indicators MUST NOT use colour as the sole differentiator (WCAG AA per ADR-010)

## 10. Frontend: Embed RoutingSuggestionPanel in RequestDetail

- [ ] 10.1 In `src/views/requests/RequestDetail.vue`:
  - Import `RoutingSuggestionPanel` from `@/components/RoutingSuggestionPanel.vue`
  - Add to `components: {}`
  - Embed `<RoutingSuggestionPanel :request-id="request.id" :category="request.category" />` inside the queue assignment section (show only when `request.queue` is set)

## 11. Store Registration

- [ ] 11.1 In `src/store/store.js`: call `objectStore.registerObjectType('skill', 'skill', 'pipelinq')` if not already registered — use kebab-case type name `'skill'`
- [ ] 11.2 In `src/store/store.js`: call `objectStore.registerObjectType('agent-profile', 'agentProfile', 'pipelinq')` — use kebab-case `'agent-profile'` NOT camelCase `'agentProfile'`
- [ ] 11.3 Verify each entity type is registered ONCE — NEVER in both `OBJECT_TYPES` and `ENTITY_STORES`

## 12. Verification

- [ ] 12.1 Run `npm run build` — verify zero build errors
- [ ] 12.2 Run `composer check:strict` — verify zero PHP errors or type errors
- [ ] 12.3 Smoke test `GET /api/routing/suggestions?entityType=request&entityId={uuid}` with valid auth — verify 200 and `{ suggestions, atCapacity, noMatch }` shape
- [ ] 12.4 Smoke test without auth — verify 401 response
- [ ] 12.5 Smoke test missing `entityType` param — verify 400 with `message` field; no stack trace in response
- [ ] 12.6 Smoke test missing `entityId` param — verify 400 with `message` field
- [ ] 12.7 Trigger repair step — verify 5 default skills are created; re-trigger — verify no duplicates
- [ ] 12.8 Manual browser test: queue a request with category "vergunningen" — verify routing panel shows agents with Vergunningen skill, sorted by workload
- [ ] 12.9 Manual browser test: set agent isAvailable=false — verify they no longer appear in suggestions
- [ ] 12.10 Run SPDX header check:
  ```bash
  grep -rL 'SPDX-License-Identifier' lib/Service/RoutingService.php lib/Controller/RoutingController.php src/components/RoutingSuggestionPanel.vue src/components/admin/SkillSettings.vue src/components/admin/AgentProfileSettings.vue
  ```
  MUST return zero files.
- [ ] 12.11 Verify ObjectService calls use 3 positional args:
  ```bash
  grep -n 'findObjects\|saveObject\|findObject' lib/Service/RoutingService.php lib/Service/DefaultQueueService.php
  ```
  Every call MUST have exactly 3 arguments.
- [ ] 12.12 Verify no `$e->getMessage()` in controller responses:
  ```bash
  grep -n 'getMessage()' lib/Controller/RoutingController.php
  ```
  MUST return zero matches.
