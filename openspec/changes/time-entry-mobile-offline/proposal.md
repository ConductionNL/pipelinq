---
kind: code
---

# Proposal: time-entry-mobile-offline

## Summary

Spec `time-entry-mobile` describes writing down hours on a phone, also without a connection, saved to OpenRegister's time-tracker leaf (ADR-022). Its own header says nothing of it is built: no mobile timer view, no PWA manifest, no offline buffer, no sync queue. This is the build plan.

One amendment to the spec: the offline buffer and sync queue are not time-specific. Any app that captures on the road (dossiq inspections, shillinq receipts) needs "keep this write until there is a connection, then send it once". So they are built once as a generic composable in nextcloud-vue, and pipelinq's mobile timer uses it. The timer page itself is manifest configuration over the time-tracker leaf's capture widget, following board PqMobiel.

## The row this covers

Source: pipelinq `openspec/parity/capabilities.json`.

- **work-time-on-phone** Write down your hours on your phone, also without a connection (specified). Board: PqMobiel.

## What changes

1. nextcloud-vue: `useOfflineQueue`, an IndexedDB-backed queue of pending writes with idempotency keys, flushed on the `online` event, with a pending count for the UI.
2. pipelinq: a mobile timer page per PqMobiel, using the leaf's capture actions through that queue; a web app manifest so the page can be added to the home screen.
3. Optional GPS on the capture as leaf metadata.

## Screens

PqMobiel (see `screens.md`).

## Out of scope

- The timer engine and the time entry model (the time-tracker leaf owns them).
- Approval of hours (`time-approval-workflow`).
