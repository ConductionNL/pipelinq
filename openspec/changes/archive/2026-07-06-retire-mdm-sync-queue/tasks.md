# Tasks: retire-mdm-sync-queue

Order: drain first (1.1), then rewire the listener (2.x), then delete (3.x). Coordinate 3.1's `MdmHardDeleteConfirmationJob` deletion with `consume-or-dsar` (design.md §Ordering — no stubbing).

## 1. Drain Migration

- [x] 1.1 Repair step `DrainMdmSyncQueue`
  - **done 2026-07-06**: `lib/Repair/DrainMdmSyncQueue.php` registered post-migration. Dispatches non-terminal (queued/sending/failed) rows once via `WebhookService::dispatchEvent` with the original targetSystem/changeType/masterEntity/payload envelope; marks each terminal (schema-valid `status: sent` + `acknowledgmentReference: drained:<ts>` — the enum predates this change and has no literal `drained` value, `sent` was never written by the retired service so the encoding is unambiguous); terminal rows skipped; failed hand-offs stay non-terminal and are reported; OR-absent / no-schema is a logged no-op. Unit tests cover drain-once, delivered-skipped, idempotent re-run, failure-stays-pending, OR-absent, empty. Live-verified on the dev instance: seeded row → "1 drained, 3 skipped (terminal)" on re-run (idempotency), rows cleaned up after.
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-req-mdm-014--one-time-drain-of-in-flight-queue-rows`
  - **files**: `lib/Repair/DrainMdmSyncQueue.php`, `appinfo/info.xml` (repair registration)
  - **acceptance_criteria**:
    - Non-terminal rows dispatched once via `WebhookService::dispatchEvent` with the original targetSystem/changeType/masterEntity/payload envelope
    - Rows marked `drained` (never deleted); terminal rows skipped; idempotent re-run dispatches zero
    - Failed hand-offs stay non-terminal and are reported; drained/skipped summary logged
    - Unit tests: drain-once, idempotency, failure-stays-pending

## 2. Event-Path Dispatch

- [x] 2.1 Rewire `ObjectsMergedSyncListener` to direct dispatch
  - **done 2026-07-06**: `enqueueSync` fan-out replaced by lazy-resolved `WebhookService::dispatchEvent` (one dispatch per downstream system with the targetSystem/changeType/masterEntity/payload envelope); no `syncQueueItem` writes remain. OR-absent → logged no-op, no Throwable escapes (each leg try/caught). Constructor rewired to `(ContainerInterface, MdmObjectRepository, OpenRegisterSyncService, LoggerInterface)` — DI autowires. Unit test asserts dispatch envelope + no-queue + OR-absent degradation. The AVG right-of-deletion soft-delete sync (`AVGWorkflowService`, which `consume-or-dsar` will delete) was also rewired off `SyncQueueService::enqueueSync` onto lazy WebhookService dispatch so this change leaves no dangling `SyncQueueService` reference.
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-req-mdm-006--downstream-sync-queue-with-retries-and-confirmation`
  - **files**: `lib/Listener/ObjectsMergedSyncListener.php`
  - **acceptance_criteria**:
    - `enqueueSync` calls replaced by lazy-resolved `WebhookService::dispatchEvent`; no `syncQueueItem` writes remain
    - OR-absent: logged no-op, no Throwable escapes; merge save never blocked by dispatch failure
    - Unit test asserts dispatch envelope + no-queue + OR-absent degradation

- [x] 2.2 Event-driven golden-record sync (replace the poller)
  - **done 2026-07-06** — DISPOSITION: KEEP `OpenRegisterSyncService`, drive it event-first. Evidence (OR origin/development): OR's MergeService materialises the *masterEntity's own* golden record on save (`x-openregister-survivorship`), but NOT pipelinq's schema projections — the account→client slug mapping (`ENTITY_TYPE_SCHEMA`) and the `masterEntityRef`/`isMasterRecord` markers on the `contact`/`client`/`product` OR objects are pipelinq-specific. So the service is retained and `syncMasterToRegister` is now invoked from `ObjectsMergedSyncListener::handle` (a merge/reversal is exactly when the survivor golden record changed). `MdmOpenRegisterSyncJob` poller removed; zero polling jobs. Unit test `testMergeEventProjectsGoldenRecord` covers the event path.
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-req-mdm-011--sync-golden-record-to-openregister`
  - **files**: `lib/Service/Mdm/OpenRegisterSyncService.php`, `lib/Listener/ObjectsMergedSyncListener.php`
  - **acceptance_criteria**:
    - `syncMasterToRegister` invoked from the merge/mutation event path — verify first (against OR origin/development stewardship) whether OR already maintains these projections; if so, delete `OpenRegisterSyncService` instead and record the evidence in the task note
    - Either disposition: zero polling jobs, `masterEntityRef` semantics preserved
    - Unit coverage for the chosen path

## 3. Deletions

- [x] 3.1 Delete queue service, jobs, controller, routes
  - **done 2026-07-06**: deleted `SyncQueueService`, `MdmSyncQueueProcessorJob`, `MdmOpenRegisterSyncJob`, `MdmHardDeleteConfirmationJob`, `MdmApiController`, and `SyncQueueServiceTest`; removed the three MDM `<job>` registrations from info.xml; removed the `mdmApi#queryByNaturalKey` / `mdmApi#show` routes (route-reachability gate green). `grep -r SyncQueueService lib/ tests/` matches nothing but doc comments. ORDERING with consume-or-dsar: this change lands FIRST, so `MdmHardDeleteConfirmationJob` (the queue-side `AVGWorkflowService` consumer) is deleted here and `consume-or-dsar` can delete `AVGWorkflowService` freely — no dangling reference either way. Orphaned listener + AVG tests rewritten against the new dispatch path (WebhookService recording double).
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-req-mdm-006--downstream-sync-queue-with-retries-and-confirmation`
  - **files**: `lib/Service/Mdm/SyncQueueService.php`, `lib/BackgroundJob/MdmSyncQueueProcessorJob.php`, `lib/BackgroundJob/MdmOpenRegisterSyncJob.php`, `lib/BackgroundJob/MdmHardDeleteConfirmationJob.php`, `lib/Controller/MdmApiController.php`, `appinfo/routes.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - All five files deleted; `mdmApi#queryByNaturalKey` / `mdmApi#show` routes removed (route-reachability gate)
    - Job + DI registrations removed; `grep -r "SyncQueueService" lib/ tests/` matches nothing
    - `MdmHardDeleteConfirmationJob` deletion cross-checked against `consume-or-dsar` state: whichever change applies second leaves no dangling `Mdm\AVGWorkflowService` reference
    - Orphaned tests (SyncQueueServiceTest, job tests, MdmApi tests) deleted or rewritten against 1.1/2.x

- [x] 3.2 Remove the `syncQueueItem` schema (gated on clean drain)
  - **done 2026-07-06**: `syncQueueItem` removed from `register.d/90-master-data-management.json` (both the register `schemas` list and the `components.schemas` definition) and from the `SettingsLoadService` slug list. Other MDM schemas (masterEntity, sourceRecord, mergeOperation, contact, client, product) untouched. JSON re-validated. NOTE: OR register import is additive, so the live schema definition + the `syncQueueItem_schema` config key persist on already-imported instances — this is intentional and required, since the DrainMdmSyncQueue repair step (which runs on the same upgrade) needs the live schema to find and drain in-flight rows; fresh installs never provision it.
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-req-mdm-014--one-time-drain-of-in-flight-queue-rows`
  - **files**: `lib/Settings/register.d/90-master-data-management.json`, `lib/Service/SettingsService.php` (schema keys), SchemaMapService entries
  - **acceptance_criteria**:
    - `syncQueueItem` removed from the fragment only after 1.1 reports a clean drain
    - No dangling schema config keys or schema-map entries; other MDM schemas (masterEntity, sourceRecord, mergeOperation, contact, client, product) untouched

## 4. Docs & Verification

- [x] 4.1 Update MDM feature documentation
  - **done 2026-07-06**: `docs/admin/master-data-management.md` re-pointed delivery visibility at OpenRegister's webhook logs (Prerequisites, Background jobs, merge steps, Troubleshooting) with no `/api/mdm/master*` reference; `docs/api/mdm-read-api.md` rewritten as a redirect to OpenRegister's object API (the app-side read-API is removed).
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-req-mdm-006--downstream-sync-queue-with-retries-and-confirmation`
  - **files**: `docs/Features/` (MDM page)
  - **acceptance_criteria**:
    - Delivery-visibility story re-pointed at OR's webhook logs; downstream read guidance re-pointed at OR's object API; no page documents `/api/mdm/master*`

- [x] 4.2 Tests + gates
  - **done 2026-07-06**: full PHPUnit unit suite green in bare php:8.3-cli (18 MDM tests: DrainMdmSyncQueue×6, listener×7, AVGWorkflow×… ); Newman "MDM read-API retired" folder asserts the downstream read path serves no JSON read-API (SPA catch-all `dashboard#page /{path}` swallows the removed route → HTML, so a 404 is not observable; the assertion checks no golden-record JSON is served, which is the faithful check). `composer` phpcs (lib) / phpmd / psalm / phpstan clean on the diff; hydra gates 28/28 green (route-reachability, redundant-controller, stub-scan, spec-coverage all pass). Live-verified on localhost:8080: routes gone, MDM jobs deregistered, drain repair dispatches + is idempotent.
  - **spec_ref**: all
  - **files**: `tests/unit/`, `tests/`
  - **acceptance_criteria**:
    - PHPUnit green; Newman covers the OR-object-API downstream read path (API assertions never in Playwright)
    - `composer check:strict` green; hydra gates pass (route-reachability, redundant-controller, stub-scan, spec-coverage)
