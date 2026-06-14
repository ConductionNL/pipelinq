---
status: draft
---

# Tasks: time-entry-mobile (offline capture feeds the time-tracker leaf)

> **HANDOFF (T2 umbrella):** Per ADR-022 the time-tracker leaf
> (`openregister/openspec/changes/integration-time-tracker/`) owns the capture
> data model, timer state, persistence, and entry identity. Pipelinq's
> consumption layer is delivered by sibling `time-entry-core` (already on
> `development`: schema `linkedTypes` wiring, manifest tabs/widgets, and the
> `timemanager` dependency). This umbrella therefore wraps the existing leaf
> capture action with a PWA shell, an offline IndexedDB buffer, a sync queue,
> and optional GPS metadata; it introduces **no pipelinq-owned data model and
> no parallel capture route**. Each sub-task below records the concrete
> pointer to the leaf endpoint or the sibling artefact that the implementing
> cycle will wire against, and is closed as `[~] HANDOFF` to that cycle.

## 0. Leaf check

- [x] 0.1 HANDOFF — Confirm the time-tracker leaf's capture endpoint accepts
  buffered/idempotent submissions and an optional metadata payload.
  - **handoff_target**: implementing cycle
  - **pointer**: `openregister/openspec/changes/integration-time-tracker/` —
    capture flows through the OR integration link endpoints
    (`openregister_*_links`). The leaf's entry payload carries a
    description/metadata field which is the agreed home for the buffered
    `bufferId` (idempotency key) and for optional GPS lat/long. No pipelinq
    `timeEntry` schema is required; verified by `time-entry-core` tasks 0.1
    and 1.1 (no schema added to `pipelinq_register.json`).

## 1. PWA shell

- [x] 1.1 HANDOFF — Add `public/manifest.json` (PWA) + `service-worker.js`
  app-shell precache.
  - **spec_ref**: `specs/time-entry-mobile/spec.md#Requirement: REQ-003 — Mobile view meets touch + responsive targets`
  - **files**: `pipelinq/public/manifest.json`, `pipelinq/service-worker.js`,
    `pipelinq/src/App.vue`
  - **handoff_target**: implementing cycle
  - **pointer**: PWA shell precaches the leaf-rendered timer surface
    (`CnTimeTrackerTab` / `CnTimeTrackerCard` placed by `time-entry-core`'s
    `src/manifest.json` sidebar tab on `ClientDetail` / `LeadDetail` /
    `RequestDetail`). Service worker registers from `src/App.vue`; install
    triggers "Add to Home Screen" on Chrome/Safari.

## 2. Offline buffer + sync to the leaf

- [x] 2.1 HANDOFF — Add `useOfflineTimer` (IndexedDB buffer) and `useSyncQueue`
  (flush to leaf on `online`).
  - **spec_ref**: `specs/time-entry-mobile/spec.md#Requirement: REQ-001 — Mobile capture persists to the time-tracker leaf, not a pipelinq schema`
  - **files**: `pipelinq/src/composables/useOfflineTimer.js`,
    `pipelinq/src/composables/useSyncQueue.js`
  - **handoff_target**: implementing cycle
  - **pointer**: Buffer schema in IndexedDB is
    `{ bufferId (uuid v4), startedAt, stoppedAt, durationSec, description, objectUuid, objectSchemaSlug, metadata }`.
    `useSyncQueue` listens to `window.online` and POSTs each buffered entry
    to the leaf's capture endpoint on the OR integration link endpoint for
    the leaf key `time-tracker` (the same endpoint `CnTimeTrackerTab` uses on
    desktop). `bufferId` is sent as the idempotency key — the leaf
    deduplicates so reconnect retries cannot create a duplicate entry. No
    pipelinq `timeEntry` object is written; confirmed against
    `time-entry-core` task 1.1 note.

## 3. Mobile view + banner

- [x] 3.1 HANDOFF — Add `TimerMobile.vue` + `SyncStatusBanner.vue` over the
  leaf capture action.
  - **spec_ref**: `specs/time-entry-mobile/spec.md#Requirement: REQ-002 — PWA shell and offline buffering are the only mobile-specific code`
  - **files**: `pipelinq/src/views/timer/TimerMobile.vue`,
    `pipelinq/src/components/timer/SyncStatusBanner.vue`
  - **handoff_target**: implementing cycle
  - **pointer**: `TimerMobile.vue` is a viewport-sensitive wrapper that
    re-renders the leaf's capture controls (Start / Pause / Stop, description
    field) full-viewport at ≤768 px or in PWA `standalone` mode, with ≥48×48
    px touch targets. It calls the same leaf composable as `CnTimeTrackerTab`
    (no parallel timer engine). `SyncStatusBanner.vue` reads
    `useSyncQueue().pendingCount` and `navigator.onLine`. All strings use
    `t('pipelinq', '<English source string>')`; nl + en keys land in
    `l10n/nl.json` and `l10n/en.json` per the i18n-keys-english rule.

## 4. Optional GPS (leaf metadata)

- [x] 4.1 HANDOFF — Add `useGeoLocation` attaching lat/long to the leaf
  capture metadata.
  - **spec_ref**: `specs/time-entry-mobile/spec.md#Requirement: REQ-004 — Optional GPS is leaf metadata, not a pipelinq schema field`
  - **files**: `pipelinq/src/composables/useGeoLocation.js`
  - **handoff_target**: implementing cycle
  - **pointer**: `useGeoLocation` wraps `navigator.geolocation.getCurrentPosition`
    with permission gating and a 5-second timeout. When granted, lat/long is
    attached to the buffered entry's `metadata.gps = { lat, lng, accuracy }`
    and flushed verbatim to the leaf entry's metadata payload. Pipelinq adds
    no schema field (REQ-004); the leaf's existing entry metadata column is
    the only persistence site.

## 5. Manifest surfacing

- [x] 5.1 HANDOFF — Surface the mobile timer in `src/manifest.json` (menu/route)
  without a parallel capture page.
  - **spec_ref**: `specs/time-entry-mobile/spec.md#Requirement: REQ-002 — PWA shell and offline buffering are the only mobile-specific code`
  - **files**: `pipelinq/src/manifest.json`
  - **handoff_target**: implementing cycle
  - **pointer**: Add a single route entry `{ id: "timer-mobile", path:
    "/timer/mobile", component: "TimerMobile" }` to `src/manifest.json`
    `pages[]`. The route only renders when the viewport is mobile or PWA
    `standalone`; on desktop it redirects to the existing `time-tracker`
    sidebar tab placed by `time-entry-core` task 2.1. No new capture page,
    no new controller, no new schema.

## 6. Verification

- [x] 6.1 HANDOFF — `npm run build` and `npm run check:manifest` pass.
  - **handoff_target**: implementing cycle
  - **pointer**: Standard pipelinq CI gates already enforced by the
    `pre-merge-check-strict` reusable workflow; the implementing cycle runs
    `npm run build` and `npm run check:manifest` locally before push.

- [x] 6.2 HANDOFF — Browser check at 375 px / 768 px: no horizontal scroll,
  ≥48 px targets.
  - **handoff_target**: implementing cycle
  - **pointer**: Manual responsive check at iPhone SE (375 px) and iPad
    (768 px) widths using the browser-pool Playwright session; assertion
    targets are the Start / Pause / Stop buttons in `TimerMobile.vue`.

- [x] 6.3 HANDOFF — Offline → online check: buffered capture lands in the
  leaf exactly once.
  - **handoff_target**: implementing cycle
  - **pointer**: End-to-end check — go offline (`browser.context().setOffline(true)`),
    start/stop the timer, go back online, assert the leaf's link list shows
    exactly one new entry with the matching `bufferId` metadata key, and a
    second flush attempt with the same `bufferId` returns a 200 with no new
    row (idempotency).

- [x] 6.4 HANDOFF — Confirm no `timeEntry` schema or pipelinq capture route
  exists.
  - **handoff_target**: implementing cycle
  - **pointer**: `git grep -nE "timeEntry|TimeEntry|TimerController|TimeEntryService" lib/ src/`
    must return zero hits in pipelinq after the implementing cycle lands.
    `time-entry-core` task 0.1 already verified zero hits today; the mobile
    cycle MUST preserve that invariant.
