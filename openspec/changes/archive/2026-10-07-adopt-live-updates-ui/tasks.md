# Tasks — adopt-live-updates-ui

## 1. Dependency

- [x] 1.1 Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.212` (liveUpdatesPlugin default-on
      in `createObjectStore`; first-subscription transport fix). Hoist `@nextcloud/files`
      (npm dedupe) so the published package's bundled dialogs chunk resolves.

## 2. View wiring

- [x] 2.1 `PipelineBoard.vue` — collection subscriptions for every mapped object type of the
      selected pipeline (pending-scope marker + epoch counter guards; debounced
      `fetchPipelineItems()` refetch on event; release in `beforeDestroy`).
- [x] 2.2 `ProjectDetail.vue` — `useObjectSubscription(store, 'project', id)` in `setup()`.
- [x] 2.3 `ResourceDetail.vue` — `useObjectSubscription(store, 'resource', id)` in `setup()`.
- [x] 2.4 `ServiceDetail.vue` — `useObjectSubscription(store, 'service', id)` in `setup()`.

## 3. Verification

- [x] 3.1 `npm run lint` clean on touched files (pre-existing JSDoc warnings in
      `PipelineBoard.vue` fixed along the way).
- [x] 3.2 `npm run test:unit` green (45/45).
- [x] 3.3 `npm run build` green against the PUBLISHED beta.212 package.
