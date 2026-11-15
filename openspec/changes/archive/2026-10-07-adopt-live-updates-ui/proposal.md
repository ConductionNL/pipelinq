---
kind: code
---

## Why

`@conduction/nextcloud-vue` 1.0.0-beta.212 turns the `liveUpdatesPlugin` on by default for
every `createObjectStore`-based store (lazy — fully inert until the first `subscribe()` call)
and fixes the first-subscription-stranded transport bug. OpenRegister already pushes
`or-object-{uuid}` and `or-collection-{register-slug}-{schema-slug}` events for all
OpenRegister-backed objects, so Pipelinq's store gains a working `subscribe(type, id?)` API
from the dependency bump alone. Without view-side adoption, multi-user surfaces (the pipeline
board above all) keep rendering stale data until a manual refresh.

## What Changes

- Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.212`.
- `PipelineBoard.vue`: subscribe to the collection scope of every object type mapped into the
  selected pipeline; re-scope when the pipeline changes; release on destroy. Events are
  refetch hints only — the board re-runs its existing `fetchPipelineItems()` path (debounced),
  never patching items from an event payload.
- `ProjectDetail.vue`, `ResourceDetail.vue`, `ServiceDetail.vue`: per-object
  `or-object-{uuid}` subscriptions via the library's `useObjectSubscription` composable. The
  plugin's refetch lands in the same store cache these views render from, so no extra
  bridging is needed.

## What Is Deliberately NOT Wired (library gaps, not app gaps)

- `LeadList.vue` and every declarative manifest page: these render through `CnIndexPage`
  self-fetch / `CnPageRenderer`, which use the library's default `conduction-objects` store.
  That store is not `createObjectStore`-based, has no `liveUpdatesPlugin`, and `CnIndexPage`
  exposes no `objectStore` prop — live updates for those surfaces must ship in
  `@conduction/nextcloud-vue` itself.

## Impact

- Affected specs: `realtime-updates-ui` (new)
- Affected code: `package.json`, `src/views/pipeline/PipelineBoard.vue`,
  `src/views/projects/ProjectDetail.vue`, `src/views/bookings/ResourceDetail.vue`,
  `src/views/bookings/ServiceDetail.vue`
