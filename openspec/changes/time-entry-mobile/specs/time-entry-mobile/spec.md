---
status: draft
---

# Spec: Time Entry Mobile (offline timer + sync)

## Purpose

Define the requirements for offline-capable mobile time tracking in Pipelinq. This spec covers PWA installability, offline timer persistence, sync queue management, mobile-optimised UI, and optional GPS location capture.

**Main ADR refs**: [adr-000-data-model.md](../../../../architecture/adr-000-data-model.md), [adr-001-international-first-dutch-mapping.md](../../../../architecture/adr-001-international-first-dutch-mapping.md)
**Feature tier**: P0-must
**Demand evidence**: 18/26 competitors
**Depends on**: time-entry-core (`timeEntry` schema, `TimerView.vue`, `objectStore` time entry integration)

---

## REQ-TEM-001: PWA manifest and offline app-shell

The Pipelinq time-entry module MUST be installable as a Progressive Web App (PWA) on iOS and Android devices and MUST load the timer UI when there is no network connection.

### Scenario: Manifest causes "Add to Home Screen" prompt

- GIVEN a user opens the Pipelinq time-entry view in Chrome on Android
- WHEN the browser detects a valid `manifest.json` with `display: standalone` and at least one 192 × 192 icon
- THEN Chrome MUST offer the "Add to Home Screen" prompt
- AND installing the PWA MUST open the timer view as a standalone app without browser chrome

### Scenario: Timer UI loads offline after install

- GIVEN the user has previously opened the Pipelinq app while online
- AND the service worker has cached the app shell (JS bundles, CSS, icons)
- WHEN the user opens the app with no network connection
- THEN the timer UI MUST render within 3 seconds without a network request
- AND no "Failed to load" or blank-screen error MUST appear

### Scenario: Service worker does not intercept OpenRegister API calls with stale cache

- GIVEN the service worker is active
- WHEN the app makes a request to `/apps/pipelinq/api/`
- THEN the service worker MUST use a network-first strategy for API routes
- AND MUST NOT serve a stale cached API response when the network is available

---

## REQ-TEM-002: Offline timer — start, pause, and stop without network

The `useOfflineTimer` composable MUST allow a user to start, pause, resume, and stop a timer when the device has no network connectivity, persisting all state to IndexedDB so it survives page refresh and browser restart.

### Scenario: Timer started offline is persisted locally

- GIVEN the device has no network connection (`navigator.onLine === false`)
- WHEN the user taps "Timer starten"
- THEN `useOfflineTimer` MUST write the timer state (startedAt, client, notes, uuid) to the `offlineTimers` IndexedDB store
- AND the elapsed time counter MUST increment normally on screen
- AND NO network request MUST be made

### Scenario: Timer state survives page refresh

- GIVEN a timer was started while online or offline
- WHEN the user reloads the page or closes and reopens the PWA
- THEN `useOfflineTimer` MUST restore the running timer from IndexedDB
- AND the elapsed counter MUST continue from where it was, compensating for time elapsed during closure
- AND the UI MUST show the banner "Timer hersteld vanuit offline sessie"

### Scenario: Pause and resume offline

- GIVEN a timer is running and the device is offline
- WHEN the user taps "Timer pauzeren"
- THEN the timer MUST pause and write the paused state to IndexedDB
- WHEN the user taps "Timer hervatten"
- THEN the timer MUST resume and update IndexedDB with the resumed state
- AND elapsed time during the paused interval MUST NOT be counted

### Scenario: Stopping timer offline enqueues entry

- GIVEN a timer is running and the device is offline
- WHEN the user taps "Timer stoppen"
- THEN `useOfflineTimer` MUST move the completed entry from `offlineTimers` to the `syncQueue` IndexedDB store
- AND the entry MUST contain: uuid, description, client reference, startedAt, stoppedAt, duration (ISO 8601), billable flag, syncedFromOffline: true
- AND the sync status banner MUST show "Geen verbinding — timer loopt lokaal" updated to show pending count

---

## REQ-TEM-003: Sync queue — auto-flush on reconnect

The `useSyncQueue` composable MUST automatically flush all pending time entries to OpenRegister when the device reconnects to the network.

### Scenario: Pending entries are synced on reconnect

- GIVEN one or more completed time entries are in the `syncQueue` IndexedDB store
- WHEN the `online` browser event fires
- THEN `useSyncQueue` MUST POST each pending entry to OpenRegister via `objectStore.saveObject('timeEntry', entry)` within 10 seconds
- AND successfully synced entries MUST be removed from the `syncQueue` store
- AND the sync status banner MUST transition from "Synchroniseren…" to "Gesynchroniseerd"

### Scenario: Sync banner shows pending count during flush

- GIVEN 3 entries are in the sync queue and flushing has started
- WHEN `isSyncing` is true
- THEN `SyncStatusBanner` MUST display "Synchroniseren… 3 wachtend"
- AND the pending count MUST decrement as each entry is successfully posted

### Scenario: UUID conflict — server version wins

- GIVEN a pending local entry has the same UUID as an entry already on the server
- WHEN `objectStore.saveObject()` returns HTTP 409
- THEN `useSyncQueue` MUST discard the local entry (remove from IndexedDB)
- AND MUST NOT overwrite the server entry
- AND MUST NOT show an error to the user (silent discard is correct behaviour)

### Scenario: Transient network error — entry remains in queue

- GIVEN flushing is in progress
- WHEN a POST returns HTTP 503 or a network timeout occurs
- THEN the entry MUST remain in the `syncQueue` store
- AND `SyncStatusBanner` MUST show "Synchronisatiefout — probeer opnieuw"
- AND the flush MUST retry automatically on the next `online` event

### Scenario: Flush is called on app startup

- GIVEN the user opens the PWA after a previous offline session left entries in the sync queue
- AND the device is currently online
- WHEN `App.vue` `created()` lifecycle hook runs
- THEN `useSyncQueue.flushQueue()` MUST be called automatically
- AND pending entries MUST be synced without user action

---

## REQ-TEM-004: Mobile-optimised timer view

`TimerMobile.vue` MUST render automatically on small viewports and in standalone PWA mode, providing a touch-optimised interface with minimum 48 × 48 px touch targets.

### Scenario: Mobile view renders at 375 px

- GIVEN the user opens the timer page on a 375 px wide viewport (iPhone SE)
- THEN `TimerMobile.vue` MUST be rendered instead of the desktop `TimerView.vue`
- AND no horizontal scrollbar MUST be present
- AND the elapsed time display, Start/Pause/Stop buttons, client selector, and notes field MUST all be visible without scrolling

### Scenario: Mobile view renders in standalone display mode

- GIVEN the user has installed the PWA and opens it from the home screen
- WHEN `window.matchMedia('(display-mode: standalone)').matches` is true
- THEN `TimerMobile.vue` MUST be rendered regardless of viewport width

### Scenario: Touch targets meet WCAG 2.5.5

- GIVEN `TimerMobile.vue` is rendered
- THEN the Start button, Pause/Resume button, and Stop button MUST each have a rendered hit area of at least 48 × 48 CSS pixels
- AND all buttons MUST be keyboard-navigable and have visible focus rings

### Scenario: Swipe-down on elapsed time pauses timer

- GIVEN a timer is running on `TimerMobile.vue`
- WHEN the user swipes downward on the elapsed time display
- THEN the timer MUST pause (equivalent to tapping "Timer pauzeren")
- AND the swipe gesture MUST NOT trigger page scroll on that element

### Scenario: Client quick-select shows recent clients

- GIVEN the timer is not yet started
- WHEN the user taps the "Klant selecteren" field
- THEN an `NcSelect` dropdown MUST appear populated with the 10 most recently used clients from `objectStore.fetchCollection('client')`
- AND the user MUST be able to search by typing a client name

---

## REQ-TEM-005: GPS location capture

When the user has granted location permission and `useGeoLocation.capture()` is called at timer start, the composable MUST attach latitude, longitude, and accuracy metadata to the time entry.

### Scenario: Location captured at timer start when permitted

- GIVEN the user has previously granted location permission (`permitted === true`)
- AND "Locatie inschakelen" is toggled on in user settings
- WHEN the user taps "Timer starten"
- THEN `useGeoLocation.capture()` MUST be called before the timer state is written to IndexedDB
- AND the resulting `locationMetadata` object MUST contain latitude, longitude, accuracy, and capturedAt (ISO 8601 timestamp)
- AND the metadata MUST be stored on the time entry

### Scenario: Timer starts immediately if GPS capture times out

- GIVEN location permission is granted but the GPS fix takes more than 5 seconds
- WHEN `getCurrentPosition()` has not resolved after 5 seconds
- THEN `useGeoLocation` MUST resolve with `null` rather than blocking timer start
- AND the timer MUST start without location metadata
- AND the user MUST see "Locatie niet beschikbaar" briefly (2 s) in the status banner

### Scenario: Camera button hidden when Geolocation API is unavailable

- GIVEN the browser or context does not expose `navigator.geolocation`
- THEN `supported` MUST be `false`
- AND the "Locatie inschakelen" toggle MUST NOT be shown in `TimerMobile.vue`

### Scenario: Location capture is opt-in

- GIVEN the user has not previously toggled location on
- WHEN a timer is started
- THEN `useGeoLocation.capture()` MUST NOT be called
- AND no permission prompt MUST be triggered automatically
- AND `locationMetadata` MUST be `null` on the stored entry

---

## REQ-TEM-006: Sync status banner

`SyncStatusBanner.vue` MUST display the current connectivity and sync state clearly on `TimerMobile.vue` at all times.

### Scenario: Banner shows offline state

- GIVEN `navigator.onLine === false`
- THEN `SyncStatusBanner` MUST display the amber offline banner with text "Geen verbinding — timer loopt lokaal"
- AND the banner MUST be visible without scrolling in the mobile view

### Scenario: Banner auto-hides after successful sync

- GIVEN all pending entries have been successfully synced
- WHEN `isSyncing` transitions to `false` and `pendingCount` is 0
- THEN `SyncStatusBanner` MUST show the green "Gesynchroniseerd" state for 3 seconds
- AND MUST then hide itself automatically

### Scenario: Banner shows error state with retry option

- GIVEN a sync attempt failed with a transient error
- THEN `SyncStatusBanner` MUST display the red "Synchronisatiefout — probeer opnieuw" banner
- AND MUST provide a "Opnieuw proberen" button that calls `useSyncQueue.flushQueue()` manually
