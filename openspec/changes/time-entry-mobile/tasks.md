---
status: draft
---

# Tasks: time-entry-mobile

## 0. Deduplication Check

- [ ] 0.1 Verify that `time-entry-core` tasks are complete and merged — `TimerView.vue`, `timeEntry` schema in `pipelinq_register.json`, and `objectStore` time-entry integration MUST exist before this change proceeds.
- [ ] 0.2 Search for any existing service worker or PWA manifest in the pipelinq app:
  - `find public/ -name "manifest.json" -o -name "service-worker.js" -o -name "sw.js"`
  - If either already exists, extend rather than replace.
- [ ] 0.3 Search for existing offline or IndexedDB composables:
  - `grep -r "IndexedDB\|indexedDB\|idb\|offlineTimer\|syncQueue" src/composables/`
  - If a composable already exists, extend it rather than create a new one.
- [ ] 0.4 Confirm that `timeEntry` schema is present in `lib/Settings/pipelinq_register.json` (added by `time-entry-core`). If absent, block this change until `time-entry-core` is merged.

---

## 1. PWA manifest and service worker

- [ ] 1.1 Create `public/manifest.json`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-001`
  - **files**: `public/manifest.json`
  - **acceptance_criteria**:
    - Contains `name`, `short_name`, `start_url` (`/apps/pipelinq/`), `display: standalone`
    - Contains at least two icon entries: 192 × 192 and 512 × 512 PNG
    - `theme_color` and `background_color` use Nextcloud CSS variable values (`#0082c9` / `#ffffff`)

- [ ] 1.2 Add `<link rel="manifest">` to the Pipelinq app template
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-001`
  - **files**: `templates/main.php` or equivalent Nextcloud app template
  - **acceptance_criteria**:
    - `<link rel="manifest" href="...manifest.json">` is present in the page `<head>`
    - Link uses `generateUrl('/apps/pipelinq/manifest.json')` to produce a correct path

- [ ] 1.3 Add Workbox plugin to the build config and generate `public/service-worker.js`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-001`
  - **files**: `webpack.config.js` or `vite.config.js`, `public/service-worker.js`
  - **acceptance_criteria**:
    - `npm run build` generates `public/service-worker.js` with precache manifest
    - Service worker uses network-first strategy for `/apps/pipelinq/api/` routes
    - Service worker uses cache-first strategy for JS/CSS bundles and icons

- [ ] 1.4 Register service worker in `App.vue` on `created()`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-001`
  - **files**: `src/App.vue`
  - **acceptance_criteria**:
    - `navigator.serviceWorker.register(generateUrl('/apps/pipelinq/service-worker.js'))` is called
    - Registration is wrapped in `if ('serviceWorker' in navigator)` guard
    - Registration errors are logged via `console.error` but do NOT block app startup

---

## 2. Composable: `useOfflineTimer`

- [ ] 2.1 Create `src/composables/useOfflineTimer.js`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-002`
  - **files**: `src/composables/useOfflineTimer.js`
  - **acceptance_criteria**:
    - Exports `{ start, pause, resume, stop, elapsed, isRunning, isPaused, pendingSync }`
    - `start(client, description, billable)` writes timer state to `offlineTimers` IndexedDB store with generated UUID
    - `pause()` writes paused state with `pausedAt` timestamp to IndexedDB
    - `resume()` resumes elapsed counter from persisted state
    - `stop()` moves completed entry to `syncQueue` store if offline; calls `objectStore.saveObject('timeEntry')` if online

- [ ] 2.2 Implement IndexedDB persistence in `useOfflineTimer.js`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-002`
  - **files**: `src/composables/useOfflineTimer.js`
  - **acceptance_criteria**:
    - On `onMounted()`: reads `offlineTimers` store; if an active/paused entry exists, restores it and shows "Timer hersteld vanuit offline sessie" notification
    - Elapsed time on restore: `Date.now() - startedAt - totalPausedMs`
    - IndexedDB schema version 1 with stores `offlineTimers` (keyPath: uuid) and `syncQueue` (keyPath: uuid)

- [ ] 2.3 Handle online/offline detection in `useOfflineTimer.js`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-002`
  - **files**: `src/composables/useOfflineTimer.js`
  - **acceptance_criteria**:
    - When `stop()` is called and `navigator.onLine === false`: entry goes to sync queue, no API call is made
    - When `stop()` is called and `navigator.onLine === true`: entry is posted directly via `objectStore.saveObject()`
    - On `onUnmounted()`: removes any `online`/`offline` event listeners added by this composable

---

## 3. Composable: `useSyncQueue`

- [ ] 3.1 Create `src/composables/useSyncQueue.js`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-003`
  - **files**: `src/composables/useSyncQueue.js`
  - **acceptance_criteria**:
    - Exports `{ pendingCount, isSyncing, flushQueue, clearQueue }`
    - `pendingCount` (reactive ref) reflects the current count of entries in the `syncQueue` IndexedDB store
    - `isSyncing` (reactive ref) is `true` while a flush is in progress

- [ ] 3.2 Implement flush logic in `useSyncQueue.js`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-003`
  - **files**: `src/composables/useSyncQueue.js`
  - **acceptance_criteria**:
    - `flushQueue()`: reads all entries from `syncQueue` IndexedDB store, iterates sequentially
    - For each entry: calls `objectStore.saveObject('timeEntry', entry)` wrapped in `try/catch`
    - HTTP 409: silently discard entry from IndexedDB (server wins)
    - HTTP 2xx: remove entry from IndexedDB; decrement `pendingCount`
    - Other error (network timeout, 5xx): leave entry in queue, set sync status to `'error'`

- [ ] 3.3 Attach `online` event listener in `useSyncQueue.js`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-003`
  - **files**: `src/composables/useSyncQueue.js`
  - **acceptance_criteria**:
    - `window.addEventListener('online', flushQueue)` is registered in composable setup
    - Listener is removed in `onUnmounted()`
    - Duplicate flush calls are debounced (do not start a new flush if one is already in progress)

- [ ] 3.4 Call `flushQueue()` on app startup in `App.vue`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-003`
  - **files**: `src/App.vue`
  - **acceptance_criteria**:
    - `useSyncQueue().flushQueue()` is called in `created()` after service worker registration
    - Only executes when `navigator.onLine === true`

---

## 4. Composable: `useGeoLocation`

- [ ] 4.1 Create `src/composables/useGeoLocation.js`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-005`
  - **files**: `src/composables/useGeoLocation.js`
  - **acceptance_criteria**:
    - Exports `{ supported, permitted, capture, location }`
    - `supported`: `ref('geolocation' in navigator)`
    - `permitted`: reactive ref, `null` (not asked) / `true` (granted) / `false` (denied)
    - `capture()`: calls `getCurrentPosition()` with 5 s timeout; resolves to `{ latitude, longitude, accuracy, capturedAt }` or `null` on timeout/error

- [ ] 4.2 Handle GPS timeout gracefully in `useGeoLocation.js`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-005`
  - **files**: `src/composables/useGeoLocation.js`
  - **acceptance_criteria**:
    - `getCurrentPosition()` timeout option set to 5000 ms
    - On timeout or `PERMISSION_DENIED`: `capture()` resolves to `null` without throwing
    - Sets `permitted` to `false` on denial; does NOT re-prompt automatically

---

## 5. Component: `SyncStatusBanner.vue`

- [ ] 5.1 Create `src/components/timer/SyncStatusBanner.vue`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-006`
  - **files**: `src/components/timer/SyncStatusBanner.vue`
  - **acceptance_criteria**:
    - Props: `status` (String: `'online'` | `'offline'` | `'syncing'` | `'synced'` | `'error'`), `pendingCount` (Number, default 0)
    - `offline`: amber background, text "Geen verbinding — timer loopt lokaal" (via `t()`)
    - `syncing`: blue background + NcLoadingIcon, text "Synchroniseren… {n} wachtend" (via `t()`)
    - `synced`: green background, text "Gesynchroniseerd"; auto-emits `@hide` after 3 s
    - `error`: red background, text "Synchronisatiefout — probeer opnieuw" + "Opnieuw proberen" button emitting `@retry`
    - Height: 32 px; NO hardcoded colors — uses `var(--color-warning)`, `var(--color-success)`, `var(--color-error)`, `var(--color-primary-element)`

- [ ] 5.2 Add WCAG compliance to `SyncStatusBanner.vue`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-006`
  - **files**: `src/components/timer/SyncStatusBanner.vue`
  - **acceptance_criteria**:
    - Role `role="status"` and `aria-live="polite"` on the banner element so screen readers announce changes
    - Color is NOT the sole method of conveying status — each state has distinct icon AND text
    - "Opnieuw proberen" button has `aria-label="Opnieuw proberen"` and is keyboard-focusable

---

## 6. View: `TimerMobile.vue`

- [ ] 6.1 Create `src/views/timer/TimerMobile.vue`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-004`
  - **files**: `src/views/timer/TimerMobile.vue`
  - **acceptance_criteria**:
    - Uses `useOfflineTimer`, `useSyncQueue`, `useGeoLocation`
    - Renders `SyncStatusBanner` at top, wired to `useSyncQueue.isSyncing` / `pendingCount`
    - Elapsed time displayed as `HH:MM:SS` in a large monospaced font (min 48 px)
    - Start, Pause/Resume, Stop buttons each ≥ 48 × 48 px CSS
    - `NcSelect` for client quick-select (fetches recent clients from `objectStore`)
    - Single-line notes `<input>` with `t('pipelinq', 'Add notes')` placeholder
    - Location toggle shown only when `useGeoLocation.supported` is true

- [ ] 6.2 Implement swipe-down gesture for pause in `TimerMobile.vue`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-004`
  - **files**: `src/views/timer/TimerMobile.vue`
  - **acceptance_criteria**:
    - `touchstart` / `touchmove` / `touchend` listeners on the elapsed time `<div>`
    - Swipe-down (deltaY > 40 px) while timer is running calls `useOfflineTimer.pause()`
    - `@touchmove.prevent` to prevent page scroll during gesture recognition on that element only
    - Gesture is ignored when timer is stopped or already paused

- [ ] 6.3 Integrate `TimerMobile.vue` into `TimerView.vue` (responsive rendering)
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-004`
  - **files**: `src/views/timer/TimerView.vue`
  - **acceptance_criteria**:
    - Computed `isMobileView`: `window.innerWidth <= 768 || window.matchMedia('(display-mode: standalone)').matches`
    - `v-if="isMobileView"`: renders `<TimerMobile>` with correct props
    - `v-else`: renders original desktop timer layout (unchanged)
    - `isMobileView` re-evaluates on `window.resize` event (debounced 100 ms)

---

## 7. Seed Data

- [ ] 7.1 Add 3 Dutch `client` seed objects and 4 `timeEntry` seed objects to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: Company ADR-001 (data-layer) — seed data requirement
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Clients: `client-bakkerij-de-graaf`, `client-installatiebedrijf-vos`, `client-advocatenkantoor-linden`
    - Time entries: `timeentry-vos-werkbon-42`, `timeentry-degraaf-adviesgesprek`, `timeentry-linden-contractreview`, `timeentry-intern-ontwikkeling`
    - Each uses `@self` envelope with correct `register`, `schema`, and unique `slug`
    - Offline-synced entries have `syncedFromOffline: true` and `locationMetadata` with valid lat/lon
    - Re-importing MUST skip objects matched by slug (`force: false`)

---

## 8. i18n

- [ ] 8.1 Add 15 new translation keys to `l10n/en.json`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-006`
  - **files**: `l10n/en.json`
  - **acceptance_criteria**:
    - All 15 keys from `design.md` i18n table are present
    - Keys are English sentence case per ADR-007

- [ ] 8.2 Add Dutch translations for the same 15 keys to `l10n/nl.json`
  - **spec_ref**: `specs/time-entry-mobile/spec.md#REQ-TEM-006`
  - **files**: `l10n/nl.json`
  - **acceptance_criteria**:
    - Dutch values match the `design.md` i18n table exactly
    - Both locale files have the same set of keys (no gaps per ADR-007)

---

## 9. Verification

- [ ] 9.1 Run `npm run build` — MUST produce zero errors; `public/service-worker.js` MUST be generated
- [ ] 9.2 PWA install test: open app in Chrome on Android (or Chrome desktop with mobile emulation); confirm "Add to Home Screen" prompt appears
- [ ] 9.3 Offline timer test: disable network; start a timer; stop the timer; confirm entry appears in sync queue (check IndexedDB `syncQueue` store via DevTools Application tab)
- [ ] 9.4 Auto-sync test: with 1 entry in sync queue, re-enable network; confirm `SyncStatusBanner` shows "Synchroniseren…" then "Gesynchroniseerd"; confirm entry appears in OpenRegister time-entry list
- [ ] 9.5 Page-refresh restore test: start a timer; reload the page; confirm timer resumes with correct elapsed time and shows "Timer hersteld vanuit offline sessie"
- [ ] 9.6 Mobile layout test: open timer page in Chrome DevTools at 375 × 667 px (iPhone SE); confirm no horizontal scroll, all buttons visible, touch targets ≥ 48 px
- [ ] 9.7 GPS test: on a device with GPS; enable "Locatie inschakelen"; start a timer; stop it; confirm synced `timeEntry` object in OpenRegister has `locationMetadata` with `latitude`, `longitude`, `accuracy`, `capturedAt`
- [ ] 9.8 GPS disabled test: leave location toggle off; start and stop a timer; confirm `locationMetadata` is `null` and no permission prompt appears
- [ ] 9.9 Seed data verification: confirm 3 client seed objects and 4 time-entry seed objects are loadable via `importFromApp()` without errors or duplicates
- [ ] 9.10 Run hardcoded string check: `grep -rn "Geen verbinding\|Synchroniseren\|Timer starten" src/components/ src/views/ src/composables/` — all strings MUST use `t()`, not hardcoded
- [ ] 9.11 Run translation key completeness check: `diff <(jq -S keys l10n/en.json) <(jq -S keys l10n/nl.json)` — output MUST be empty (no gaps between locales)
