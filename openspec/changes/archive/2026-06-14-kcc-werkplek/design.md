# Design: kcc-werkplek

## Architecture

### Data Model

No new schemas are introduced by this change. All entities are already defined in `pipelinq_register.json` and ADR-000:

| Entity | Role in workspace |
|--------|-------------------|
| `contactmoment` | Registered during active sessions |
| `request` | Shown in inbox panel, taken from queue |
| `task` | Open follow-up tasks shown in inbox |
| `queue` | Named queues for workload routing display |
| `kennisartikel` | Inline knowledge search results |
| `agentProfile` | Agent availability and max-concurrent config |
| `skill` | Displayed alongside agent profile |

### Backend

#### KccWerkplekController (`lib/Controller/KccWerkplekController.php`)

Provides aggregated workspace state for the frontend. All endpoints require an authenticated Nextcloud session (`@NoAdminRequired`).

| Method | URL | Action |
|--------|-----|--------|
| GET | `/api/kcc-werkplek/state` | Aggregated state: assigned requests, open tasks, queue counts, agent profile |
| PUT | `/api/kcc-werkplek/availability` | Set agent availability (`isAvailable: bool`) |

Request body for `PUT /api/kcc-werkplek/availability`:
```json
{ "isAvailable": true }
```

Response for `GET /api/kcc-werkplek/state`:
```json
{
  "agentProfile": { "isAvailable": true, "maxConcurrent": 3, "skills": [] },
  "assignedRequests": [ { "id": "...", "title": "...", "priority": "high", "channel": "phone" } ],
  "openTasks": [ { "id": "...", "subject": "...", "type": "terugbelverzoek", "deadline": "..." } ],
  "queueCounts": { "queue-algemene-zaken": 12, "queue-vergunningen": 5 }
}
```

#### KccWerkplekService (`lib/Service/KccWerkplekService.php`)

- `getWorkspaceState(string $userId): array` — Calls `ObjectService::findObjects()` for requests assigned to user (status: open/in_progress), open tasks assigned to user, agentProfile for user, and queue item counts. Returns merged array.
- `setAvailability(string $userId, bool $available): array` — Finds agentProfile by userId, updates `isAvailable` via `ObjectService::saveObject()`.

### Frontend

#### Routes (added to `src/router/index.js`)

```js
{ path: '/werkplek', name: 'KccWerkplek', component: KccWerkplekPage }
```

#### Views and Components

**KccWerkplekPage.vue** (`src/views/werkplek/KccWerkplekPage.vue`)

Main workspace container. Three-panel responsive layout:
- Left (300px fixed): `WerkplekInbox` — queue and task list
- Center (flex): `WerkplekContactmomentPanel` — active interaction form
- Right (280px fixed): `WerkplekKennisSearch` — knowledge search

Header bar contains: queue selector (NcSelect), `WerkplekAgentStatus`, breadcrumb.

Uses `useListView` for inbox state and direct store calls for contactmoment creation. Fetches workspace state from `GET /api/kcc-werkplek/state` on `created()`.

**WerkplekInbox.vue** (`src/components/werkplek/WerkplekInbox.vue`)

Displays assigned requests and open tasks grouped by priority:
- Section: "Verzoeken" — CnDataTable with request rows (title, channel badge, priority badge, created date)
- Section: "Taken" — CnDataTable with task rows (subject, type badge, deadline, status)
- Clicking a row emits `select-item` event to load context into the center panel

**WerkplekContactmomentPanel.vue** (`src/components/werkplek/WerkplekContactmomentPanel.vue`)

Quick contactmoment registration form. Adapts fields based on channel selection:
- Channel selector (phone, email, counter, chat, letter, social) — uses `NcSelect`
- Client search (autocomplete via `ObjectService.findObjects()`) — pre-fills from selected inbox item
- Subject field (text)
- Summary / notes (textarea)
- Outcome selector (enum: opgelost / doorverwezen / terugbellen / niet_bereikbaar)
- `CallTimer.vue` — shown only when channel = phone; auto-fills duration on stop
- "Registreer" button → saves contactmoment via objectStore, shows NcDialog confirmation
- "Nieuwe taak" button → opens `CnFormDialog` pre-filled with client and contactmoment link

The panel does NOT use `CnFormDialog` as the root — it is a custom panel component because it requires the call timer and channel adaptation logic.

**WerkplekKennisSearch.vue** (`src/components/werkplek/WerkplekKennisSearch.vue`)

Inline knowledge base search panel:
- Search field (debounced, 300ms, min 2 chars) via `ObjectService.findObjects()` on `kennisartikel`
- Results list: title, summary snippet (150 chars), category badges
- Clicking a result expands inline to show full article body (rendered Markdown via `marked`)
- Nuttig / Niet nuttig feedback buttons → `KennisbankService.submitFeedback()`
- No navigation — all inline within the panel

**WerkplekAgentStatus.vue** (`src/components/werkplek/WerkplekAgentStatus.vue`)

Agent availability toggle:
- Toggle button: "Beschikbaar" (green) / "Niet beschikbaar" (grey)
- On toggle → `PUT /api/kcc-werkplek/availability`
- Wrapped in `try/catch` with NcDialog error feedback on failure
- Shows assigned queue names below toggle (from workspace state)

#### Navigation

Add "KCC Werkplek" as the **first** navigation item in `MainMenu.vue`, with `mdi-headset` icon.

#### Store

All entities use `createObjectStore` registered in `src/store/store.js`. No new stores needed. The workspace state (assigned requests + open tasks) is fetched via the `KccWerkplekController` endpoint rather than client-side aggregation to avoid N+1 API calls.

## Reuse Analysis

| Existing capability | Reused by |
|---------------------|-----------|
| `createObjectStore('contactmoment', ...)` | WerkplekContactmomentPanel creates contactmomenten |
| `createObjectStore('request', ...)` | WerkplekInbox reads assigned requests |
| `createObjectStore('task', ...)` | WerkplekInbox reads open tasks |
| `createObjectStore('kennisartikel', ...)` | WerkplekKennisSearch searches articles |
| `CallTimer.vue` (omnichannel-registratie) | Reused in WerkplekContactmomentPanel for phone duration |
| `KennisbankService.submitFeedback()` (kennisbank) | Reused in WerkplekKennisSearch for article feedback |
| `ObjectService.findObjects()` | All entity queries in KccWerkplekService |
| `CnFormDialog` | New task creation dialog in WerkplekContactmomentPanel |
| `CnDataTable` | Inbox request and task tables |
| `useListView` composable | Inbox state management |

No custom search endpoints, custom pagination, or custom stores are built. The aggregated state endpoint (`/api/kcc-werkplek/state`) justifies a custom controller because parallel fetching + merging of multiple entity types cannot be done with a single ObjectService call.

## Seed Data

No new schemas are introduced. This change relies on existing seed objects for `contactmoment`, `request`, `task`, `queue`, `agentProfile`, and `skill`. The following seed objects MUST be present in `lib/Settings/pipelinq_register.json` to enable browser testing of the workspace:

### queue (3 objects)

```json
{ "@self": { "register": "pipelinq", "schema": "queue", "slug": "queue-algemene-zaken" },
  "title": "Algemene Zaken",
  "description": "Diverse gemeentelijke vragen en informatieverzoeken",
  "isActive": true,
  "maxCapacity": null,
  "sortOrder": 1 }

{ "@self": { "register": "pipelinq", "schema": "queue", "slug": "queue-vergunningen" },
  "title": "Vergunningen",
  "description": "Aanvragen en meldingen omgevingsvergunning",
  "isActive": true,
  "maxCapacity": 50,
  "sortOrder": 2 }

{ "@self": { "register": "pipelinq", "schema": "queue", "slug": "queue-wmo-zorg" },
  "title": "WMO / Zorg",
  "description": "Wmo-aanvragen, hulpmiddelenvragen en zorgondersteuning",
  "isActive": true,
  "maxCapacity": 30,
  "sortOrder": 3 }
```

### agentProfile (3 objects)

```json
{ "@self": { "register": "pipelinq", "schema": "agentProfile", "slug": "agent-jan-de-vries" },
  "userId": "jan.devries",
  "maxConcurrent": 3,
  "isAvailable": true }

{ "@self": { "register": "pipelinq", "schema": "agentProfile", "slug": "agent-fatima-el-amrani" },
  "userId": "fatima.elamrani",
  "maxConcurrent": 2,
  "isAvailable": false }

{ "@self": { "register": "pipelinq", "schema": "agentProfile", "slug": "agent-pieter-bakker" },
  "userId": "pieter.bakker",
  "maxConcurrent": 4,
  "isAvailable": true }
```

### skill (3 objects)

```json
{ "@self": { "register": "pipelinq", "schema": "skill", "slug": "skill-vergunningen" },
  "title": "Vergunningen",
  "description": "Behandeling van omgevingsvergunningen en meldingen op basis van de Wabo",
  "categories": ["vergunningen", "omgeving"],
  "isActive": true }

{ "@self": { "register": "pipelinq", "schema": "skill", "slug": "skill-wmo-zorg" },
  "title": "WMO / Zorg",
  "description": "Begeleiding bij Wmo-aanvragen, hulpmiddelen en zorgvragen",
  "categories": ["wmo", "zorg", "hulpmiddelen"],
  "isActive": true }

{ "@self": { "register": "pipelinq", "schema": "skill", "slug": "skill-algemene-dienstverlening" },
  "title": "Algemene Dienstverlening",
  "description": "Brede gemeentelijke dienstverlening voor eerste lijn vragen",
  "categories": ["algemeen", "informatie"],
  "isActive": true }
```

## Files Changed

### New Files

- `lib/Controller/KccWerkplekController.php`
- `lib/Service/KccWerkplekService.php`
- `src/views/werkplek/KccWerkplekPage.vue`
- `src/components/werkplek/WerkplekInbox.vue`
- `src/components/werkplek/WerkplekContactmomentPanel.vue`
- `src/components/werkplek/WerkplekKennisSearch.vue`
- `src/components/werkplek/WerkplekAgentStatus.vue`

### Modified Files

- `lib/Settings/pipelinq_register.json` — Add seed objects for `queue`, `agentProfile`, `skill`
- `appinfo/routes.php` — Add kcc-werkplek API routes
- `src/router/index.js` — Add `/werkplek` route
- `src/navigation/MainMenu.vue` — Add KCC Werkplek as first nav item with headset icon
- `src/store/store.js` — Verify `queue`, `agentProfile`, `skill` entity types are registered
