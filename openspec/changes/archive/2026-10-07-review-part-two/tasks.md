# Tasks: review-part-two

## 1. Booking timeline (E6)

- [x] 1.1 Booking Timeline tab uses the library `timeline` widget with the booking's date fields and the audit trail
  - Verify: `tests/vitest/serviceSteps.spec.js` checks the type, the fields against the booking schema and `auditTrail`
- [x] 1.2 Remove the interim `BookingTimelineWidget` registry entry
- [x] 1.3 en and nl strings for the new labels
- [x] 1.4 Timeline reads `statusHistory` through the library `lists` source (status as label, reason as detail); inert until nextcloud-vue #1352 ships
  - Verify: `tests/vitest/serviceSteps.spec.js` checks the keys against the booking schema

## 2. Tasks

- [x] 2.1 Remove the TaskNew page (CnWizardDialog as a page rendered empty)
  - Verify: `tests/vitest/manifestPageComponents.spec.js` fails on the old manifest

## 3. Library 2.66.0

- [x] 3.1 Bump nextcloud-vue to 2.66.0; the product category column uses the real `productCategory` slug

## 4. Default pipelines on repair (R5, second cause)

- [x] 4.1 Add `SystemServiceAccount` (disabled `pipelinq-system`, random password, volatile active user, previous user restored)
  - Verify: `SystemServiceAccountTest`
- [x] 4.2 `DefaultPipelineService` writes as the system account when nobody is signed in
  - Verify: `DefaultPipelineServiceTest::testRepairWithoutAUserWritesAsTheSystemAccount`; live, `occ maintenance:repair` creates the pipelines

## 5. Housekeeping

- [x] 5.1 Move the roadmap feature list out of Application into `RoadmapFeatureCatalog` (phpmd class length)
  - Verify: `RoadmapFeatureCatalogTest`
- [x] 5.2 Example bookings point at example clients
  - Verify: `tests/vitest/serviceSteps.spec.js`; gate 101 0 fail
