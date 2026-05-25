---
status: draft
---

# Design: time-entry-mobile

## Architecture

### Data Layer

This change introduces **no new OpenRegister schemas**. All time entry data is written to and read from the `timeEntry` schema defined by the `time-entry-core` dependency. The mobile change adds a local persistence layer (IndexedDB) as a write-ahead buffer when the device is offline.

**Schemas used (from `time-entry-core`):**

| Schema | Usage |
|---|---|
| `timeEntry` | Read and write; created offline-first, synced to OpenRegister on reconnect |

**ADR-000 schemas referenced (for entity linking):**

| Schema | Usage |
|---|---|
| `client` | Time entries link to a billable client via `timeEntry.client` |
| `lead` | Time entries may be linked to an active lead/project via `timeEntry.lead` |
| `contact` | Timer quick-select surfaces the client's contact persons |

**Local persistence (IndexedDB):**

| Store | Key | Purpose |
|---|---|---|
| `offlineTimers` | uuid (string) | Active or paused timer state (start time, elapsed, client, notes) |
| `syncQueue` | uuid (string) | Completed time entries awaiting network sync to OpenRegister |

No new PHP migrations. No OpenRegister schema changes. IndexedDB stores are created by the frontend on first use and cleared after successful sync.

### Frontend

#### New Composables

**`src/composables/useOfflineTimer.js`**

Wraps the timer lifecycle with IndexedDB persistence. On every state change (start / pause / stop / resume), writes the current timer state to the `offlineTimers` IndexedDB store. When `stop()` is called:
- If online: calls `objectStore.saveObject('timeEntry', entry)` directly.
- If offline: moves the completed entry to the `syncQueue` store instead.

```js
const { start, pause, resume, stop, elapsed, isRunning, isPaused } = useOfflineTimer()
```

State is restored from IndexedDB on component mount, so a running timer survives page refresh and browser restart (within the same origin).

**`src/composables/useSyncQueue.js`**

Manages the sync queue: reads from the `syncQueue` IndexedDB store, listens for the browser `online` event, and flushes pending entries when connectivity returns.

```js
// Flush algorithm:
// 1. Read all entries from syncQueue IndexedDB store
// 2. For each entry, POST via objectStore.saveObject('timeEntry', entry)
// 3. On success: delete entry from syncQueue store
// 4. On 409 Conflict (UUID collision): discard local copy, keep server version
// 5. On other error: leave entry in queue, retry on next online event
```

Exports: `{ pendingCount, isSyncing, flushQueue, clearQueue }`.

**`src/composables/useGeoLocation.js`**

Wraps the browser Geolocation API. Called at timer start when the user has granted permission.

```js
const { supported, permitted, capture, location } = useGeoLocation()
// location: { latitude, longitude, accuracy, capturedAt }
```

- `supported`: `'geolocation' in navigator`
- `permitted`: set to `true` after user grants permission, `false` after denial, `null` if not yet asked
- `capture()`: calls `navigator.geolocation.getCurrentPosition()`, resolves to `{ latitude, longitude, accuracy, capturedAt }`
- Location is stored as a `locationMetadata` property on the time entry object

#### New Components and Views

**`src/views/timer/TimerMobile.vue`**

Full-viewport mobile timer rendered when `window.innerWidth ≤ 768` OR `window.matchMedia('(display-mode: standalone)').matches`. Uses `useOfflineTimer`, `useSyncQueue`, and `useGeoLocation`.

Layout (portrait):
- Top bar: app name, offline badge (`SyncStatusBanner`)
- Centre: large elapsed time display (`HH:MM:SS`, 64 px font)
- Below timer: client/project quick-select (NcSelect, shows recent clients)
- Notes field: single-line, 48 px height
- Bottom bar: Stop button (destructive, full width, 56 px), Pause/Resume toggle (secondary)

Touch targets: all interactive elements ≥ 48 × 48 px (WCAG 2.5.5).
Swipe-down gesture on the elapsed time display triggers Pause.

**`src/components/timer/SyncStatusBanner.vue`**

Slim (32 px) status bar pinned to top of `TimerMobile.vue`:
- Offline: amber background, "Geen verbinding — timer loopt lokaal"
- Syncing: blue background with spinner, "Synchroniseren… {n} wachtend"
- Synced: green background (auto-hides after 3 s), "Gesynchroniseerd"
- Error: red background, "Synchronisatiefout — probeer opnieuw"

Props: `status` (`'online'` | `'offline'` | `'syncing'` | `'synced'` | `'error'`), `pendingCount` (integer).

#### Modified Files

**`src/App.vue`**

On `created()`: register service worker (`navigator.serviceWorker.register('/apps/pipelinq/service-worker.js')`) and call `useSyncQueue().flushQueue()` to drain any entries stored during a previous offline session.

**`src/views/timer/TimerView.vue`** _(from time-entry-core)_

Conditionally renders `TimerMobile.vue` when viewport is ≤ 768 px or display-mode is standalone. Desktop view unchanged.

### Backend

No new PHP controllers or services. All time entry persistence uses the existing OpenRegister REST API via `objectStore`. The PWA service worker and manifest are static files served from `public/`.

**Service worker (`public/service-worker.js`):**
- Generated by Workbox CLI as part of `npm run build`
- Precaches: app shell JS/CSS bundles, icons, manifest
- Network-first strategy for API calls (`/apps/pipelinq/api/`)
- Cache-first strategy for app shell assets

### Integration Points

| System | Integration |
|---|---|
| OpenRegister `timeEntry` schema (time-entry-core) | Write via `objectStore.saveObject()`; conflict check via 409 response code |
| IndexedDB (browser) | Local timer state and sync queue storage |
| Browser `online` / `offline` events | Trigger sync flush on reconnect |
| Navigator Geolocation API | Optional GPS capture at timer start |
| Service Worker / Workbox | Offline app-shell caching |
| `client` schema (ADR-000) | Quick-select client for billable timer |

## Reuse Analysis

Per company ADR-001 (data-layer), the following OpenRegister and `@conduction/nextcloud-vue` capabilities are reused:

| Capability | Source |
|---|---|
| `objectStore.saveObject()` | OpenRegister via `createObjectStore` — no custom API controller |
| `objectStore.fetchCollection('client')` | OpenRegister — client quick-select data |
| `NcSelect` | `@conduction/nextcloud-vue` — client/project picker |
| `NcLoadingIcon` | `@conduction/nextcloud-vue` — sync spinner |
| `createObjectStore` + `lifecyclePlugin` | `@conduction/nextcloud-vue` — store setup |
| `useListView` | `@conduction/nextcloud-vue` — desktop list state (unchanged) |

No new custom CRUD controllers, search endpoints, or audit controllers are introduced. All business logic is confined to the three new composables.

## i18n

All keys follow ADR-007 sentence case with English as the key string.

| Key | English | Dutch |
|---|---|---|
| `No connection — timer running locally` | `No connection — timer running locally` | `Geen verbinding — timer loopt lokaal` |
| `Synchronising… {n} pending` | `Synchronising… {n} pending` | `Synchroniseren… {n} wachtend` |
| `Synchronised` | `Synchronised` | `Gesynchroniseerd` |
| `Sync error — try again` | `Sync error — try again` | `Synchronisatiefout — probeer opnieuw` |
| `Start timer` | `Start timer` | `Timer starten` |
| `Pause timer` | `Pause timer` | `Timer pauzeren` |
| `Resume timer` | `Resume timer` | `Timer hervatten` |
| `Stop timer` | `Stop timer` | `Timer stoppen` |
| `Add notes` | `Add notes` | `Notities toevoegen` |
| `Select client` | `Select client` | `Klant selecteren` |
| `Location captured` | `Location captured` | `Locatie vastgelegd` |
| `Location unavailable` | `Location unavailable` | `Locatie niet beschikbaar` |
| `Enable location` | `Enable location` | `Locatie inschakelen` |
| `Pending entries: {n}` | `Pending entries: {n}` | `Wachtende vermeldingen: {n}` |
| `Timer restored from offline session` | `Timer restored from offline session` | `Timer hersteld vanuit offline sessie` |

## Files Changed

### New Files

| File | Purpose |
|---|---|
| `public/manifest.json` | PWA manifest (name, icons, display: standalone) |
| `public/service-worker.js` | Workbox service worker (generated by build) |
| `src/composables/useOfflineTimer.js` | Offline-first timer with IndexedDB persistence |
| `src/composables/useSyncQueue.js` | Sync queue: flush pending entries on reconnect |
| `src/composables/useGeoLocation.js` | Optional GPS capture at timer start |
| `src/views/timer/TimerMobile.vue` | Mobile-optimised timer view (≤ 768 px / standalone) |
| `src/components/timer/SyncStatusBanner.vue` | Offline / syncing / synced status banner |
| `l10n/en.json` additions | 15 new translation keys |
| `l10n/nl.json` additions | Dutch translations for same 15 keys |

### Modified Files

| File | Change |
|---|---|
| `src/App.vue` | Register service worker on `created()`; call `useSyncQueue().flushQueue()` |
| `src/views/timer/TimerView.vue` | Render `TimerMobile.vue` when viewport ≤ 768 px or display-mode standalone |
| `webpack.config.js` / `vite.config.js` | Add Workbox plugin for service worker generation |
| `appinfo/info.xml` | Add `<navigations>` entry and PWA link rel |

## Seed Data

Per company ADR-001 (data-layer), seed data is provided for the entities referenced by this change. The `timeEntry` schema belongs to `time-entry-core`; the objects below illustrate the offline-sync scenario with realistic Dutch values. Client objects from ADR-000 are included as the billable entities time entries link to.

### `client` seed objects (ADR-000 — register: pipelinq)

```json
{
  "@self": { "register": "pipelinq", "schema": "client", "slug": "client-bakkerij-de-graaf" },
  "name": "Bakkerij De Graaf BV",
  "type": "organization",
  "email": "info@bakkerijdegraaf.nl",
  "phone": "+31 573 234 567",
  "address": "Molenstraat 14, 7161 AK Neede",
  "industry": "Voedingsmiddelen",
  "notes": "Vaste klant; maandelijkse consultancy-uren"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "client", "slug": "client-installatiebedrijf-vos" },
  "name": "Installatiebedrijf Vos & Zonen",
  "type": "organization",
  "email": "planning@vos-installatie.nl",
  "phone": "+31 38 453 8821",
  "address": "Industrieweg 7, 8013 PM Zwolle",
  "industry": "Technische installatie",
  "notes": "Projectbasis; urentracking vereist per werkbon"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "client", "slug": "client-advocatenkantoor-linden" },
  "name": "Advocatenkantoor Van der Linden",
  "type": "organization",
  "email": "secretariaat@vanderlinden-advocaten.nl",
  "phone": "+31 20 623 4410",
  "address": "Keizersgracht 512, 1017 EJ Amsterdam",
  "industry": "Juridische diensten",
  "notes": "Uren worden wekelijks gefactureerd via Exact"
}
```

### `timeEntry` seed objects (via `time-entry-core` — register: pipelinq)

These objects demonstrate the offline-sync scenario: entries created offline on a mobile device, later synced to OpenRegister.

```json
{
  "@self": { "register": "pipelinq", "schema": "timeEntry", "slug": "timeentry-vos-werkbon-42" },
  "description": "Inspectie cv-installatie keuken — werkbon #42",
  "client": "client-installatiebedrijf-vos",
  "startedAt": "2026-05-19T08:15:00+02:00",
  "stoppedAt": "2026-05-19T10:45:00+02:00",
  "duration": "PT2H30M",
  "billable": true,
  "syncedFromOffline": true,
  "locationMetadata": { "latitude": 52.5069, "longitude": 6.0917, "accuracy": 18, "capturedAt": "2026-05-19T08:15:04+02:00" }
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "timeEntry", "slug": "timeentry-degraaf-adviesgesprek" },
  "description": "Adviesgesprek assortimentsplanning Q3",
  "client": "client-bakkerij-de-graaf",
  "startedAt": "2026-05-20T13:00:00+02:00",
  "stoppedAt": "2026-05-20T14:30:00+02:00",
  "duration": "PT1H30M",
  "billable": true,
  "syncedFromOffline": false,
  "locationMetadata": null
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "timeEntry", "slug": "timeentry-linden-contractreview" },
  "description": "Review huurcontract herziening 2026",
  "client": "client-advocatenkantoor-linden",
  "startedAt": "2026-05-20T09:30:00+02:00",
  "stoppedAt": "2026-05-20T11:00:00+02:00",
  "duration": "PT1H30M",
  "billable": true,
  "syncedFromOffline": true,
  "locationMetadata": { "latitude": 52.3735, "longitude": 4.8896, "accuracy": 25, "capturedAt": "2026-05-20T09:30:02+02:00" }
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "timeEntry", "slug": "timeentry-intern-ontwikkeling" },
  "description": "Interne opleiding: Nextcloud-beheer",
  "client": null,
  "startedAt": "2026-05-15T14:00:00+02:00",
  "stoppedAt": "2026-05-15T16:00:00+02:00",
  "duration": "PT2H",
  "billable": false,
  "syncedFromOffline": false,
  "locationMetadata": null
}
```
