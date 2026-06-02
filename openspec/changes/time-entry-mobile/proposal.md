---
status: draft
---

# Proposal: time-entry-mobile (offline capture feeds the time-tracker leaf)

## Why

Field consultants and mobile workers need an offline-capable timer that syncs on
reconnect. The original draft built this entirely in Pipelinq on top of an
in-app `timeEntry` schema: an offline timer composable, a sync queue, a mobile
timer view, and GPS capture, all persisting to a pipelinq-owned data model.

Per the user decision — **time-tracker leaf for capture, shillinq for billing** —
and hydra ADR-022, the capture data model, timer state and persistence are owned
by the OpenRegister **time-tracker leaf** (`integration-time-tracker`), consumed
by `time-entry-core`. Pipelinq must not introduce a parallel `timeEntry` schema
for the mobile path either.

This change is re-pointed: the mobile concerns that are *genuinely
pipelinq-specific UX* — a PWA shell, offline-first buffering, and optional GPS
metadata — remain, but they **feed the leaf's capture endpoints** rather than a
pipelinq data model. On reconnect, buffered entries are submitted to the leaf via
the OR integration link endpoints; the leaf remains the single source of truth
for hours.

## What Changes

### Keep the mobile UX layer; re-point its persistence to the leaf

1. **PWA manifest + service worker** — installable, offline-capable app shell for
   the timer UX. Unchanged in intent.
2. **Offline buffer (`useOfflineTimer`)** — buffers start/pause/stop locally in
   IndexedDB when offline. On reconnect it submits to the **leaf's capture
   endpoint** (OR integration link endpoints), not to a pipelinq `timeEntry`.
3. **Sync queue (`useSyncQueue`)** — flushes buffered captures to the leaf on the
   `online` event; conflict handling defers to the leaf's entry identity.
4. **Mobile timer view (`TimerMobile.vue`)** — responsive ≤768 px view wrapping
   the leaf's capture action with large touch targets and an offline banner. It
   is a thin mobile presentation over leaf capture, not a parallel timer engine.
5. **Optional GPS metadata** — when granted, latitude/longitude is attached to
   the leaf capture as metadata on the link (the leaf supports an entry
   description/metadata payload); Pipelinq adds no schema for it.

### Removed from scope

- Any `timeEntry` schema or schema extension in Pipelinq.
- Any pipelinq-owned capture data model or capture route — the leaf owns capture.

## Out of Scope

- Hour capture data model + timer state — owned by the time-tracker leaf
  (see `time-entry-core`).
- Approval / invoicing — shillinq (see `time-approval-workflow`).
- Native iOS/Android apps — PWA covers V1.
- Geofencing / auto clock-in, Bluetooth beacons, barcode scanning — separate
  changes.

## Impact

- **New schemas**: 0 (leaf owns the capture model).
- **New frontend files**: PWA manifest, service worker, `useOfflineTimer`,
  `useSyncQueue`, `useGeoLocation`, `TimerMobile.vue`, `SyncStatusBanner.vue` —
  all wired to the leaf's capture endpoints.
- **Modified files**: `src/App.vue` (register service worker), `src/manifest.json`
  (mobile timer surfacing).
- **Removed from prior draft**: pipelinq `timeEntry` schema dependency, seed
  `timeEntry` objects.
- **Dependency**: `time-entry-core` (leaf consumption); the time-tracker leaf's
  capture endpoint must accept buffered submissions.
- **Risk**: Medium — offline buffering + reconnect submission against the leaf
  endpoint needs careful idempotency, but no app-owned data layer.
