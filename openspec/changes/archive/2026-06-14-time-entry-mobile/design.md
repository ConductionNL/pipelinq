---
status: draft
---

# Design: time-entry-mobile (offline capture feeds the time-tracker leaf)

## Architecture

```
[ TimerMobile.vue ] —— start/pause/stop ——▶ [ useOfflineTimer ]
                                                   │ online?
                                  yes ─────────────┤───────────── no
                                   │                              │
                                   ▼                              ▼
                    [ time-tracker leaf capture ]        [ IndexedDB buffer ]
                    (OR integration link endpoints)               │  online event
                                   ▲                               │
                                   └──────[ useSyncQueue.flush ]◀───┘
```

The time-tracker leaf (`integration-time-tracker`) owns the capture data model,
timer semantics, totals and persistence. The mobile layer is a **thin
offline-first presentation + buffer** over the leaf's capture endpoints.

## What Pipelinq owns (mobile UX only)

- `public/manifest.json` + `service-worker.js` — installable, offline app shell.
- `useOfflineTimer` — buffers capture events in IndexedDB while offline.
- `useSyncQueue` — flushes buffered captures to the leaf on `online`; idempotent
  submission keyed on a client-generated buffer id so re-flush does not
  duplicate.
- `useGeoLocation` — optional lat/long captured at start, attached to the leaf
  capture's metadata payload.
- `TimerMobile.vue` + `SyncStatusBanner.vue` — responsive ≤768 px presentation
  with ≥48 px touch targets.

## What Pipelinq does NOT own (re-pointed away from the prior draft)

| Prior bespoke artefact | New owner |
|---|---|
| `timeEntry` schema dependency | time-tracker leaf |
| pipelinq capture route / objectStore write | leaf capture endpoint |
| GPS as a `timeEntry` schema field | leaf metadata payload |
| Seed `timeEntry` objects | n/a (leaf owns the model) |

## Idempotency

Each buffered capture carries a client-generated `bufferId`. On flush, the sync
queue submits to the leaf; if the leaf already recorded that `bufferId` (e.g.
a prior partial flush), the duplicate is dropped. This keeps offline → online
transitions safe without a pipelinq-side dedup table.

## Boundaries

- Capture model + totals: time-tracker leaf (`time-entry-core`).
- Approval + invoicing: shillinq (`time-approval-workflow`).
- Mobile offline UX: this change.

## Risks

- Medium: offline buffering and reconnect submission against the leaf endpoint
  require careful idempotency (addressed via `bufferId`). No app-owned data
  layer, so no schema-drift risk.
- The leaf's capture endpoint must accept a metadata/description payload for the
  optional GPS data; if it does not yet, GPS capture degrades to a no-op until
  the leaf adds it (tracked as a leaf follow-up, not a pipelinq schema).
