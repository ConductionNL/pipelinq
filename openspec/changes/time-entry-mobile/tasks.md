---
status: draft
---

# Tasks: time-entry-mobile (offline capture feeds the time-tracker leaf)

## 0. Leaf check

- [ ] 0.1 Confirm the time-tracker leaf's capture endpoint accepts buffered/idempotent submissions and an optional metadata payload.
  - **acceptance_criteria**:
    - GIVEN `openregister/openspec/changes/integration-time-tracker/`
    - THEN document the capture endpoint and whether it accepts a metadata payload (for GPS)
    - AND confirm no pipelinq `timeEntry` schema is required.

## 1. PWA shell

- [ ] 1.1 Add `public/manifest.json` (PWA) + `service-worker.js` app-shell precache.
  - **spec_ref**: `specs/time-entry-mobile/spec.md#Requirement: Mobile view meets touch + responsive targets`
  - **files**: `pipelinq/public/manifest.json`, `pipelinq/service-worker.js`, `pipelinq/src/App.vue`
  - **acceptance_criteria**:
    - GIVEN the PWA manifest
    - THEN Chrome/Safari offer "Add to Home Screen"; the service worker caches the timer shell.

## 2. Offline buffer + sync to the leaf

- [ ] 2.1 Add `useOfflineTimer` (IndexedDB buffer) and `useSyncQueue` (flush to leaf on `online`).
  - **spec_ref**: `specs/time-entry-mobile/spec.md#Requirement: Mobile capture persists to the time-tracker leaf, not a pipelinq schema`
  - **files**: `pipelinq/src/composables/useOfflineTimer.js`, `pipelinq/src/composables/useSyncQueue.js`
  - **acceptance_criteria**:
    - GIVEN offline capture
    - THEN events buffer in IndexedDB and flush to the leaf via OR integration link endpoints on reconnect
    - AND submission is idempotent via a client-generated `bufferId`
    - AND no pipelinq `timeEntry` object is written.

## 3. Mobile view + banner

- [ ] 3.1 Add `TimerMobile.vue` + `SyncStatusBanner.vue` over the leaf capture action.
  - **spec_ref**: `specs/time-entry-mobile/spec.md#Requirement: PWA shell and offline buffering are the only mobile-specific code`
  - **files**: `pipelinq/src/views/timer/TimerMobile.vue`, `pipelinq/src/components/timer/SyncStatusBanner.vue`
  - **acceptance_criteria**:
    - GIVEN a viewport ≤768 px or standalone mode
    - THEN the view renders full-viewport with ≥48×48 px touch targets and an offline banner
    - AND all strings use `t(appName, '...')` with nl + en keys.

## 4. Optional GPS (leaf metadata)

- [ ] 4.1 Add `useGeoLocation` attaching lat/long to the leaf capture metadata.
  - **spec_ref**: `specs/time-entry-mobile/spec.md#Requirement: Optional GPS is leaf metadata, not a pipelinq schema field`
  - **files**: `pipelinq/src/composables/useGeoLocation.js`
  - **acceptance_criteria**:
    - GIVEN location permission granted and GPS enabled
    - THEN lat/long attaches to the leaf capture metadata payload
    - AND no pipelinq schema field is added.

## 5. Manifest surfacing

- [ ] 5.1 Surface the mobile timer in `src/manifest.json` (menu/route) without a parallel capture page.
  - **spec_ref**: `specs/time-entry-mobile/spec.md#Requirement: PWA shell and offline buffering are the only mobile-specific code`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the manifest
    - THEN the mobile timer is reachable; it wraps leaf capture, not a pipelinq data model.

## 6. Verification

- [ ] 6.1 `npm run build` and `npm run check:manifest` pass.
- [ ] 6.2 Browser check at 375 px / 768 px: no horizontal scroll, ≥48 px targets.
- [ ] 6.3 Offline → online check: buffered capture lands in the leaf exactly once.
- [ ] 6.4 Confirm no `timeEntry` schema or pipelinq capture route exists.
