# Tasks: time-entry-mobile-offline

## 1. nextcloud-vue (shared)
- [ ] 1.1 `useOfflineQueue(name)`: enqueue a write (method, url, body) with an idempotency key, persist in IndexedDB, flush in order on `online` and on page load, drop an entry only after a 2xx or a 409 duplicate, expose `pendingCount`; unit tests with fake-indexeddb (V1)
- [ ] 1.2 Check that OpenRegister honours an idempotency key (header or object id chosen client-side) on the time-tracker leaf's capture endpoint; if not, build it there (REQ-001 idempotent submission) (V1)

## 2. pipelinq mobile timer
- [ ] 2.1 A `type: "custom"` or manifest page `MobileTimer` per PqMobiel that mounts the time-tracker leaf's capture widget with Start, Pause and Stop at least 48x48 px, the client or lead to book on, and the offline line with `pendingCount` (REQ-002, REQ-003) (V1)
- [ ] 2.2 Route the leaf's capture writes through `useOfflineQueue('pipelinq-time')`; no pipelinq `timeEntry` schema (REQ-001) (V1)
- [ ] 2.3 `img/manifest.webmanifest` (name, icons, `display: standalone`, `start_url` the timer page) linked from the page template so the page can be added to the home screen (REQ-003) (V1)
- [ ] 2.4 Optional GPS: when the user enables it in their pipelinq user settings modal and grants permission, attach latitude and longitude to the capture's metadata (REQ-004) (V1)
- [ ] 2.5 l10n nl and en (V1)

## 3. Verification
- [ ] 3.1 Playwright at 375 px: no horizontal scroll, buttons at least 48 px; offline (context.setOffline) start and stop, pending line shows 1, back online, the leaf holds one entry, a second flush creates no duplicate (V1)
- [ ] 3.2 Remove the "Not implemented" header and the `@e2e exclude` lines from `openspec/specs/time-entry-mobile/spec.md` when archiving; flip `work-time-on-phone` to built (V1)
