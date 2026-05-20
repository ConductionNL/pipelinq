---
status: draft
---

# Proposal: time-entry-mobile

## Problem

Pipelinq has no mobile time-entry experience. Market intelligence covering 18/26 sampled competitors shows that an offline-capable mobile timer is a universal expectation for professional time tracking:

1. **No offline timer** — When a consultant loses network connectivity on a job site or in transit, there is no way to track time locally. Time entries must wait until the user is back online, leading to lost or inaccurate billable hours. Competitors such as Toggl Track, Harvest, Clockify, Clio, BigTime, and Replicon all provide offline timers that auto-sync on reconnect.

2. **No mobile-optimised UI** — The existing time-entry interface (introduced by `time-entry-core`) targets desktop viewports. Touch targets are too small, forms require keyboard input, and the running-timer widget is not visible on mobile without horizontal scrolling. Competitors including Clockify, HubSpot, and Hubstaff ship full mobile-parity interfaces across iOS and Android.

3. **No sync queue** — When a user records time offline and reconnects, there is no mechanism to detect pending local entries and push them to OpenRegister. Without a sync queue, offline data is silently lost or requires manual re-entry.

4. **No GPS location capture** — Field service workers need to associate time entries with job-site locations. Clockify ("Optional location pin for jobsite tracking"), Hubstaff ("GPS tracking; auto clock-in when entering job site"), and TimeCamp ("iOS/Android; optional GPS") all provide location tagging. Without this, Pipelinq cannot serve field-based time-tracking use cases.

Without these capabilities, Pipelinq is unusable for consultants, field engineers, and mobile workers — a segment represented by 18 of the 26 competitors surveyed.

## Solution

Implement a Progressive Web App (PWA) layer on top of the `time-entry-core` time tracking module, consisting of:

1. **PWA manifest and service worker** — Register a `manifest.json` that makes the time-entry view installable as a standalone app on iOS and Android home screens. A service worker caches the app shell so the timer UI loads instantly offline.

2. **Offline timer composable (`useOfflineTimer`)** — Wraps the existing timer state with IndexedDB persistence. Timer start/pause/stop events are written locally first; if the OpenRegister API call fails due to no connectivity, the entry is placed in the sync queue rather than discarded.

3. **Sync queue and background sync (`useSyncQueue`)** — Stores pending time entries in IndexedDB. Listens for the `online` event and, on reconnect, flushes the queue by posting entries to OpenRegister via the existing `objectStore` API. Conflict detection: if an entry with the same UUID already exists server-side, the local copy is discarded and the server version is kept.

4. **Mobile-optimised timer view (`TimerMobile.vue`)** — A responsive full-viewport timer component with 48 px minimum touch targets, large digit display, swipe-to-stop gesture, client/project quick-select, and an offline status banner. Rendered automatically when viewport width ≤ 768 px or when the PWA is running in `standalone` display mode.

5. **Optional GPS location capture** — When the user grants location permission, the `useGeoLocation` composable captures latitude/longitude at timer start and attaches it to the time entry as metadata. Location capture is opt-in per user setting and can be disabled globally by an admin.

## Scope

- `public/manifest.json` — PWA manifest (name, icons, display: standalone, start_url)
- `service-worker.js` — Offline app-shell caching via Workbox precache
- `src/composables/useOfflineTimer.js` — Offline-first timer with IndexedDB persistence
- `src/composables/useSyncQueue.js` — Pending-entry queue, flush on `online` event
- `src/composables/useGeoLocation.js` — Optional GPS capture at timer start
- `src/views/timer/TimerMobile.vue` — Mobile-optimised timer view (responsive, ≤ 768 px)
- `src/components/timer/SyncStatusBanner.vue` — Offline / syncing / synced indicator banner
- Integration: `App.vue` — register service worker, inject sync queue on startup
- i18n keys for all new mobile-UI strings (Dutch + English)
- Seed data: 4 Dutch `timeEntry` objects demonstrating offline-sync scenario (via `time-entry-core` schema)

## Out of Scope

- Native iOS/Android apps (Capacitor/React Native) — PWA covers V1
- Geofencing / auto clock-in when entering a job site (Hubstaff pattern) — separate change
- Background sync via Service Worker Background Sync API (requires HTTPS + supported browser) — V2
- Bluetooth beacon proximity detection — separate change
- Barcode scanning for job codes — separate change
- Multi-account / multi-tenant offline — Enterprise tier
- Offline knowledge base or CRM data browsing

## Success Criteria

- A user can start, pause, and stop a timer on a mobile browser with no network connection; the entry is stored locally and appears in the pending-sync list
- When the device reconnects, all pending entries are automatically pushed to OpenRegister within 10 seconds without user action
- The `TimerMobile.vue` view renders correctly at 375 px (iPhone SE) and 768 px (iPad) viewports with no horizontal scrolling
- Touch targets (Start, Pause, Stop buttons) are ≥ 48 × 48 px in the mobile view
- The PWA manifest causes Chrome on Android and Safari on iOS to offer "Add to Home Screen"
- When GPS is enabled by the user, a time entry's location metadata contains latitude/longitude accurate to 100 m
- Synced entries match the `timeEntry` schema from `time-entry-core` exactly — no schema extensions required
- `npm run build` produces zero errors after all changes
