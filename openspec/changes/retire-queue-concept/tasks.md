# Tasks: retire-queue-concept

## 1. The Queue page

- [x] 1.1 Add the `Queue` page (`/queue`), a `type: index` over `ticket` filtered to
  `assignee: "IS NULL"` and `status_in: [new, in_progress]`, with the ticketType tab
  strip and an empty state.
- [x] 1.2 Add its menu entry and relocate it into the Customer Support group above
  My Work.
- [x] 1.3 Order the group Queue, My Work, All tickets, Tasks, Projects.

## 2. Remove the queue surfaces

- [x] 2.1 Delete the `Queues` and `QueueDetail` pages and their menu entry.
- [x] 2.2 Delete `QueueList.vue`, `QueueDetail.vue`, `QueueCreateDialog.vue`,
  `store/modules/queues.js`, `services/queueUtils.js`.
- [x] 2.3 Delete `WerkplekQueueFilter.vue` and its widget on the Customer Support
  dashboard; widen the Requests widget across the freed columns.
- [x] 2.4 Delete `QueueSettings.vue` and its section on the admin page.
- [x] 2.5 Drop the `queue` object type and the registry entries.

## 3. Remove the queue plane from the backend

- [x] 3.1 Delete `QueueService` and `QueueOverflowJob`; deregister the job.
- [x] 3.2 Rename `DefaultQueueService` to `DefaultSkillService` and drop
  `DEFAULT_QUEUES` and `createDefaultQueues()`.
- [x] 3.3 Drop `SettingsService::createDefaultQueues()` and its two callers.
- [x] 3.4 Drop the `queue_overflow.poll_interval_seconds` tunable.
- [x] 3.5 Remove the queue-count pushdown from `KccWerkplekService` and the
  `queues` / `queueCounts` keys from `/api/kcc-werkplek/state`.
- [x] 3.6 Remove `queues` / `queueCount` from `Customer360SummaryService` and the
  "Active queues" tile from the Client 360 page.
- [x] 3.7 Drop `queue` from `SchemaMapService`, `SchemaSlugMap`, the store's
  installable slugs and `DemoSeedService`.

## 4. Register

- [x] 4.1 Remove the `queue` schema and its three seeded objects.
- [x] 4.2 Remove `ticket.queue` and the seeded ticket's queue reference.
- [x] 4.3 Remove the queue section from the demo seed and the mock register.

## 5. Tests and specs

- [x] 5.1 Delete `QueueServiceTest`, `DefaultQueueServiceTest`,
  `QueueOverflowJobTest` and `tests/e2e/spec-coverage/queues.spec.ts`.
- [x] 5.2 Trim the queue half of `QueryPushdownBatch3Test`, keeping the inbox
  pushdown parity, and assert the payload carries no queue plane.
- [x] 5.3 Update `KccWerkplekControllerTest`, `Customer360SummaryServiceTest`,
  `DemoSeedServiceTest`, `SettingsServiceTest`, `SettingsServiceTunableTest`,
  `RegisterResolverServiceTest`.
- [x] 5.4 Repoint `/queues` to `/queue` in `pages.spec.ts` and `navigation.spec.ts`.
- [x] 5.5 Retire `openspec/specs/queue-management/spec.md` and update the specs that
  referenced queues.
- [x] 5.6 Rewrite `docs/Features/queue-management.md` as `skill-routing.md`.
