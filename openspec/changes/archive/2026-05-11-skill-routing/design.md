# Design: skill-routing

## Architecture

### Data Model

No new OpenRegister schemas are required. This change is an extension of the `queue-management` capability and reuses existing schemas defined in ADR-000:

#### skill (existing schema)

| Property | Type | Required | Description |
|---|---|---|---|
| `title` | string | Yes | Skill name (e.g., "Vergunningen", "WMO / Zorg") |
| `description` | string | No | Description of the skill area |
| `categories` | array of string | No | Category tags matched against request.category for routing |
| `isActive` | boolean | No | Whether this skill is active for routing suggestions |

Schema.org mapping: `@type: schema:DefinedTerm`.

#### agentProfile (existing schema)

| Property | Type | Required | Description |
|---|---|---|---|
| `userId` | string | Yes | Nextcloud user UID |
| `skills` | array of string (UUIDs) | No | UUID references to assigned Skill objects |
| `maxConcurrent` | integer | No | Maximum open items for routing (default: 10) |
| `isAvailable` | boolean | No | Whether the agent is available for routing suggestions |

---

### Reuse Analysis

The following existing OpenRegister services and components are leveraged — no custom implementations needed for these concerns:

| Existing service / component | Usage in this change |
|---|---|
| `ObjectService.findObjects($register, $schema, $params)` | Query skills, agentProfiles, requests, and leads for matching and workload calculation. 3-arg positional signature throughout. |
| `ObjectService.findObject($register, $schema, $id)` | Load a specific request or lead to read its `category` field before suggestion lookup. |
| `ObjectService.saveObject($register, $schema, $object)` | Create default skills in repair step; update agentProfile on skill assignment. |
| `IAppConfig` | Read register/schema slugs for skill, agentProfile, request, lead schemas — same pattern as existing SettingsService. |
| `createObjectStore('skill')` | Pinia store for skill CRUD in admin UI — no custom store needed. |
| `createObjectStore('agent-profile')` | Pinia store for agent profile management in admin UI. |
| `CnFormDialog` | Auto-generated create/edit forms for skill and agentProfile (schema-driven). |
| `CnIndexPage` + `CnDataTable` | Skill list and agent profile list in admin settings. |
| `CnDeleteDialog` | Confirmation dialog for skill/profile deletion. |
| `CnEmptyState` | Empty state in `RoutingSuggestionPanel` when no matching agents found. |

Explicitly NOT duplicated:
- **Queue assignment logic** — existing `request.queue` membership is unchanged; this change adds advisory suggestions on top of it.
- **DefaultQueueService::createDefaultQueues()** — extended with a new `createDefaultSkills()` method, not replaced or duplicated.
- **ObjectService CRUD endpoints** — `RoutingController` is a read-aggregation endpoint; no CRUD is added.

---

### Backend

#### RoutingService (`lib/Service/RoutingService.php`)

| Method | Signature | Description |
|---|---|---|
| `getSuggestedAgents` | `(string $entityType, string $entityId): array` | Main entry point: load entity, match skills, filter and sort agents |
| `getAgentWorkload` | `(string $userId): int` | Count open requests + open leads assigned to user |
| `findMatchingAgents` | `(string $category): array` | Return agentProfile objects whose skills cover the given category |
| `filterByAvailability` | `(array $profiles): array` | Exclude profiles with `isAvailable === false` |
| `filterByCapacity` | `(array $profiles): array` | Exclude profiles where workload >= maxConcurrent |
| `isAgentAtCapacity` | `(array $profile, int $workload): bool` | True if workload >= maxConcurrent |

**`getSuggestedAgents` logic:**
1. Load entity via `ObjectService.findObject($register, $schema, $entityId)` — 3 positional args
2. Read `category` field from entity
3. `findMatchingAgents($category)` → candidate agentProfile objects
4. `filterByAvailability($candidates)` → remove unavailable
5. `filterByCapacity($candidates)` → remove over-limit (track count for `atCapacity`)
6. For each remaining profile: call `getAgentWorkload($userId)` and attach
7. Sort by workload ascending; return `{ suggestions, atCapacity, noMatch }`

**`getAgentWorkload` logic:**
- Open requests: `ObjectService.findObjects($register, $requestSchema, ['assignee' => $userId, '_limit' => 999])` — filter PHP-side excluding terminal statuses (completed, cancelled, closed)
- Open leads: `ObjectService.findObjects($register, $leadSchema, ['assignee' => $userId, 'status' => 'open', '_limit' => 999])`
- Return count(open requests) + count(open leads)

Error handling: all `catch (\Throwable $e)` blocks log full exception via `$this->logger->error()` and rethrow or return safe default. Controllers return static message strings — never `$e->getMessage()`.

#### RoutingController (`lib/Controller/RoutingController.php`)

| Method | URL | Auth | Action |
|---|---|---|---|
| GET | `/api/routing/suggestions` | `#[NoAdminRequired]` | Ranked agent shortlist for a queued item |

Query parameters:
- `entityType` (required) — `request` or `lead`
- `entityId` (required) — UUID

Response shape:
```json
{
  "suggestions": [
    {
      "userId": "jan.devries",
      "displayName": "Jan de Vries",
      "workload": 3,
      "maxConcurrent": 8,
      "matchedSkill": "Vergunningen",
      "categories": ["vergunningen", "omgevingsrecht"]
    }
  ],
  "atCapacity": 1,
  "noMatch": false
}
```

Validation: missing `entityType` or `entityId` → 400 with static message. Invalid `entityType` → 400. Service failure → 500 with `{ message: "Operation failed" }` + logger. Never return `$e->getMessage()`.

#### DefaultQueueService extension (`lib/Service/DefaultQueueService.php`)

Add `createDefaultSkills(): void`:
- Check for existing skills: `ObjectService.findObjects($register, $skillSchema, ['_limit' => 1])`
- If count > 0: return immediately (idempotent)
- Create 5 skills via `ObjectService.saveObject($register, $skillSchema, [...])` — 3 positional args each
- Called from repair step after `createDefaultQueues()`

---

### Frontend

#### SkillSettings.vue (`src/components/admin/SkillSettings.vue`)

Admin section for skill definition management in `AdminSettings.vue`.

- `CnIndexPage` with `useListView('skill', { objectStore: skillStore })`
- Columns: title, categories (chip list), isActive badge
- `CnActionsBar` "Add skill" → `CnFormDialog` (schema-driven)
- Row actions: Edit (`CnFormDialog`), Delete (`CnDeleteDialog`)
- All imports from `@conduction/nextcloud-vue`; all components registered in `components: {}`

#### AgentProfileSettings.vue (`src/components/admin/AgentProfileSettings.vue`)

Admin section for agent skill profile management in `AdminSettings.vue`.

- `CnIndexPage` with `useListView('agent-profile', { objectStore: agentProfileStore })`
- Columns: userId, skill count, isAvailable badge, maxConcurrent
- Row actions: Edit (`CnFormDialog`), Delete (`CnDeleteDialog`)
- `CnFormDialog` for skill assignment includes skill UUID multi-select using the skill store

#### RoutingSuggestionPanel.vue (`src/components/RoutingSuggestionPanel.vue`)

Embedded in `RequestDetail.vue` within the queue assignment section.

Props: `requestId` (String, required), `category` (String, default: `''`).

Layout:
- "Suggested agents" heading
- Ordered list: agent name, workload indicator "N/M items", matched skill badge, "Assign" button per row
- "Assign" → sets `request.assignee` via store action, emits `assigned`, closes panel
- Empty state: `CnEmptyState` with "No agents with matching skills" message
- At-capacity notice: "N matching agent(s) at capacity" shown when `atCapacity > 0`

All API calls via `axios` from `@nextcloud/axios`, wrapped in `try/catch` with `NcDialog` error feedback. All strings via `this.t('pipelinq', 'key')`. Color is never the sole differentiator (WCAG AA per ADR-010).

---

### Seed Data

Seed objects included in `lib/Settings/pipelinq_register.json` under `components.objects[]` with `@self` envelope. Idempotent via slug matching.

#### skill — 5 seed objects

```json
{
  "@self": { "register": "pipelinq", "schema": "skill", "slug": "skill-algemene-dienstverlening" },
  "title": "Algemene Dienstverlening",
  "description": "Algemene vragen en eerstelijnshulp voor alle dienstverleningskanalen",
  "categories": ["algemeen"],
  "isActive": true
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "skill", "slug": "skill-vergunningen" },
  "title": "Vergunningen",
  "description": "Omgevingsvergunningen, kapvergunningen en overige aanvragen",
  "categories": ["vergunningen", "omgevingsrecht"],
  "isActive": true
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "skill", "slug": "skill-belastingen" },
  "title": "Belastingen",
  "description": "Gemeentelijke belastingen, WOZ-bezwaren en kwijtscheldingsverzoeken",
  "categories": ["belastingen"],
  "isActive": true
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "skill", "slug": "skill-wmo-zorg" },
  "title": "WMO / Zorg",
  "description": "WMO-aanvragen, thuiszorg, hulpmiddelen en zorgindicaties",
  "categories": ["wmo", "zorg"],
  "isActive": true
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "skill", "slug": "skill-klachten" },
  "title": "Klachten",
  "description": "Klachten over gemeentelijke dienstverlening en behandeling",
  "categories": ["klachten"],
  "isActive": true
}
```

#### agentProfile — 4 seed objects

```json
{
  "@self": { "register": "pipelinq", "schema": "agentProfile", "slug": "agentprofile-jan-devries" },
  "userId": "jan.devries",
  "skills": ["skill-vergunningen", "skill-wmo-zorg"],
  "maxConcurrent": 8,
  "isAvailable": true
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "agentProfile", "slug": "agentprofile-lisa-vandenberg" },
  "userId": "lisa.vandenberg",
  "skills": ["skill-belastingen", "skill-algemene-dienstverlening"],
  "maxConcurrent": 10,
  "isAvailable": true
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "agentProfile", "slug": "agentprofile-pieter-bakker" },
  "userId": "pieter.bakker",
  "skills": ["skill-klachten", "skill-algemene-dienstverlening"],
  "maxConcurrent": 6,
  "isAvailable": true
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "agentProfile", "slug": "agentprofile-henk-dekker" },
  "userId": "henk.dekker",
  "skills": ["skill-wmo-zorg", "skill-klachten"],
  "maxConcurrent": 8,
  "isAvailable": false
}
```

---

### Files Changed

#### New Files
- `lib/Service/RoutingService.php`
- `lib/Controller/RoutingController.php`
- `src/components/admin/SkillSettings.vue`
- `src/components/admin/AgentProfileSettings.vue`
- `src/components/RoutingSuggestionPanel.vue`

#### Modified Files
- `lib/Service/DefaultQueueService.php` — Add `createDefaultSkills()` method and repair step call
- `lib/Settings/pipelinq_register.json` — Add seed objects for skill and agentProfile schemas
- `appinfo/routes.php` — Add routing suggestion API route
- `src/views/requests/RequestDetail.vue` — Embed `RoutingSuggestionPanel` in queue section
- `src/components/admin/AdminSettings.vue` — Add SkillSettings and AgentProfileSettings sections
- `src/store/store.js` — Register skill and agent-profile stores via `createObjectStore`
- `l10n/en.json` — Add translation keys for routing UI strings
- `l10n/nl.json` — Add Dutch translations for routing UI strings
