## 1. Data Model — Register Schema Updates [Enterprise]

- [x] 1.1 Add `queue` schema to `lib/Settings/pipelinq_register.json` with properties: title (required), description, categories (array), isActive (default true), maxCapacity, sortOrder, assignedAgents (array). Set `@type` to `schema:ItemList`.
- [x] 1.2 Add `skill` schema to `lib/Settings/pipelinq_register.json` with properties: title (required), description, categories (array), isActive (default true). Set `@type` to `schema:DefinedTerm`.
- [x] 1.3 Add `agentProfile` schema to `lib/Settings/pipelinq_register.json` with properties: userId (required), skills (array of UUIDs), maxConcurrent (default 10), isAvailable (default true).
- [x] 1.4 Add `queue` field (type string, format uuid, optional) to the `request` schema in `pipelinq_register.json`.
- [x] 1.5 Update the `pipelinq` register's `schemas` array to include `queue`, `skill`, and `agentProfile`.
- [x] 1.6 Update `lib/Service/SchemaMapService.php` to include queue, skill, and agentProfile schema IDs in the schema map.

## 2. Backend — Default Data via Repair Step [Enterprise]

- [x] 2.1 Add default queue creation to the repair step: create "Algemeen", "Vergunningen" (categories: ["vergunningen"]), and "Klachten" (categories: ["klachten"]) if no queues exist. Use the existing `SettingsService` / `ConfigurationService` pattern.
- [x] 2.2 Add default skill creation to the repair step: create "Algemene Dienstverlening" (categories: ["algemeen"]), "Vergunningen" (categories: ["vergunningen", "omgevingsrecht"]), "Belastingen" (categories: ["belastingen"]), "WMO / Zorg" (categories: ["wmo", "zorg"]), "Klachten" (categories: ["klachten"]) if no skills exist.

## 3. Frontend — Queue Store and Services [Enterprise]

- [x] 3.1 Create `src/store/modules/queues.js` Pinia store for queue CRUD operations via OpenRegister API (list, get, create, update, delete). Include priority-based sort utility for queue items.
- [x] 3.2 Create `src/store/modules/skills.js` Pinia store for skill CRUD operations via OpenRegister API.
- [x] 3.3 Create `src/store/modules/agentProfiles.js` Pinia store for agent profile CRUD operations, including workload calculation (count open assigned items per agent).
- [x] 3.4 Create `src/services/queueUtils.js` with queue utility functions: priority sort comparator, capacity check, routing suggestion logic (skill-category matching + workload sorting).

## 4. Frontend — Queue Views [Enterprise]

- [x] 4.1 Create `src/views/queues/QueueList.vue` — list all queues showing title, item count (depth), oldest item age, agent count, active status. Use `CnIndexPage` from `@conduction/nextcloud-vue`.
- [x] 4.2 Create `src/views/queues/QueueDetail.vue` — show queue items sorted by priority then age, with entity type badge, title, priority badge, waiting time, assignee, category. Include "Pick next" button and bulk assign. Use `CnDetailPage`.
- [x] 4.3 Add routes for `/queues` (QueueList) and `/queues/:id` (QueueDetail) to `src/router/index.js`.
- [x] 4.4 Add "Queues" navigation item to `src/navigation/MainMenu.vue` with Tray icon.

## 5. Frontend — Routing Suggestion Panel [Enterprise]

- [x] 5.1 Create `src/components/RoutingSuggestionPanel.vue` — displays suggested agents for a request based on skill-category match and current workload. Shows agent name, matching skills, workload count (e.g., "3/10 items"), and "Assign" button per agent. Handles no-match and at-capacity states.
- [x] 5.2 Integrate `RoutingSuggestionPanel` into `src/views/requests/RequestDetail.vue` — show panel when request has a category and is in a queue, or when "Suggest agent" action is triggered.

## 6. Frontend — Request Queue Integration [Enterprise]

- [x] 6.1 Update `src/views/requests/RequestDetail.vue` to show queue name (linked to queue detail) and "Change queue" dropdown when request has a queue field.
- [x] 6.2 Update `src/views/requests/RequestList.vue` to include an optional "Queue" column displaying the queue title or "--" for unqueued requests.

## 7. Frontend — My Work Queue Tab [Enterprise]

- [x] 7.1 Update `src/views/MyWork.vue` to add "My Items" / "My Queues" tab navigation. "My Items" is the existing temporal grouping (default). "My Queues" shows items from the user's assigned queues grouped by queue name with priority ordering.
- [x] 7.2 Add "Pick" action on unassigned items in the My Queues tab — assigns the item to the current user and moves it to the My Items view.

## 8. Frontend — Admin Settings Sections [Enterprise]

- [x] 8.1 Create `src/components/admin/QueueSettings.vue` — queue CRUD list with inline edit, agent assignment via user picker, category tags input, capacity input, active toggle. Wire to queues store.
- [x] 8.2 Create `src/components/admin/SkillSettings.vue` — skill CRUD list with inline edit, category tags input, active toggle. Wire to skills store.
- [x] 8.3 Create `src/components/admin/AgentProfileSettings.vue` — agent profile management: list Nextcloud users, assign/remove skills, set maxConcurrent, toggle isAvailable. Wire to agentProfiles store.
- [x] 8.4 Integrate QueueSettings, SkillSettings, and AgentProfileSettings into the existing admin settings page (after Pipelines section, before Lead Sources).

## 9. Quality and Verification [Enterprise]

- [x] 9.1 Run `composer check:strict` and fix any PHP quality issues (PHPCS, PHPMD, Psalm, PHPStan) in changed files.
- [x] 9.2 Verify schema import works by running repair step and confirming queue, skill, and agentProfile schemas are created in OpenRegister.
- [x] 9.3 Test queue CRUD operations via the frontend: create, edit, delete queues; verify items appear correctly.
- [x] 9.4 Test routing suggestions: create skills, assign to agents, add categorized request to queue, verify correct agent suggestions appear.
