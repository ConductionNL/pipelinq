# Tasks: retire-mdm-sync-queue

Order: drain first (1.1), then rewire the listener (2.x), then delete (3.x). Coordinate 3.1's `MdmHardDeleteConfirmationJob` deletion with `consume-or-dsar` (design.md §Ordering — no stubbing).

## 1. Drain Migration

- [ ] 1.1 Repair step `DrainMdmSyncQueue`
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-req-mdm-014--one-time-drain-of-in-flight-queue-rows`
  - **files**: `lib/Repair/DrainMdmSyncQueue.php`, `appinfo/info.xml` (repair registration)
  - **acceptance_criteria**:
    - Non-terminal rows dispatched once via `WebhookService::dispatchEvent` with the original targetSystem/changeType/masterEntity/payload envelope
    - Rows marked `drained` (never deleted); terminal rows skipped; idempotent re-run dispatches zero
    - Failed hand-offs stay non-terminal and are reported; drained/skipped summary logged
    - Unit tests: drain-once, idempotency, failure-stays-pending

## 2. Event-Path Dispatch

- [ ] 2.1 Rewire `ObjectsMergedSyncListener` to direct dispatch
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-req-mdm-006--downstream-sync-queue-with-retries-and-confirmation`
  - **files**: `lib/Listener/ObjectsMergedSyncListener.php`
  - **acceptance_criteria**:
    - `enqueueSync` calls replaced by lazy-resolved `WebhookService::dispatchEvent`; no `syncQueueItem` writes remain
    - OR-absent: logged no-op, no Throwable escapes; merge save never blocked by dispatch failure
    - Unit test asserts dispatch envelope + no-queue + OR-absent degradation

- [ ] 2.2 Event-driven golden-record sync (replace the poller)
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-req-mdm-011--sync-golden-record-to-openregister`
  - **files**: `lib/Service/Mdm/OpenRegisterSyncService.php`, `lib/Listener/ObjectsMergedSyncListener.php`
  - **acceptance_criteria**:
    - `syncMasterToRegister` invoked from the merge/mutation event path — verify first (against OR origin/development stewardship) whether OR already maintains these projections; if so, delete `OpenRegisterSyncService` instead and record the evidence in the task note
    - Either disposition: zero polling jobs, `masterEntityRef` semantics preserved
    - Unit coverage for the chosen path

## 3. Deletions

- [ ] 3.1 Delete queue service, jobs, controller, routes
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-req-mdm-006--downstream-sync-queue-with-retries-and-confirmation`
  - **files**: `lib/Service/Mdm/SyncQueueService.php`, `lib/BackgroundJob/MdmSyncQueueProcessorJob.php`, `lib/BackgroundJob/MdmOpenRegisterSyncJob.php`, `lib/BackgroundJob/MdmHardDeleteConfirmationJob.php`, `lib/Controller/MdmApiController.php`, `appinfo/routes.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - All five files deleted; `mdmApi#queryByNaturalKey` / `mdmApi#show` routes removed (route-reachability gate)
    - Job + DI registrations removed; `grep -r "SyncQueueService" lib/ tests/` matches nothing
    - `MdmHardDeleteConfirmationJob` deletion cross-checked against `consume-or-dsar` state: whichever change applies second leaves no dangling `Mdm\AVGWorkflowService` reference
    - Orphaned tests (SyncQueueServiceTest, job tests, MdmApi tests) deleted or rewritten against 1.1/2.x

- [ ] 3.2 Remove the `syncQueueItem` schema (gated on clean drain)
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-req-mdm-014--one-time-drain-of-in-flight-queue-rows`
  - **files**: `lib/Settings/register.d/90-master-data-management.json`, `lib/Service/SettingsService.php` (schema keys), SchemaMapService entries
  - **acceptance_criteria**:
    - `syncQueueItem` removed from the fragment only after 1.1 reports a clean drain
    - No dangling schema config keys or schema-map entries; other MDM schemas (masterEntity, sourceRecord, mergeOperation, contact, client, product) untouched

## 4. Docs & Verification

- [ ] 4.1 Update MDM feature documentation
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-req-mdm-006--downstream-sync-queue-with-retries-and-confirmation`
  - **files**: `docs/Features/` (MDM page)
  - **acceptance_criteria**:
    - Delivery-visibility story re-pointed at OR's webhook logs; downstream read guidance re-pointed at OR's object API; no page documents `/api/mdm/master*`

- [ ] 4.2 Tests + gates
  - **spec_ref**: all
  - **files**: `tests/unit/`, `tests/`
  - **acceptance_criteria**:
    - PHPUnit green; Newman covers the OR-object-API downstream read path (API assertions never in Playwright)
    - `composer check:strict` green; hydra gates pass (route-reachability, redundant-controller, stub-scan, spec-coverage)
