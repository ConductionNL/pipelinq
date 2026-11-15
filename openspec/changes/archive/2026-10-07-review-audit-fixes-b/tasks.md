# Tasks: review-audit-fixes-b

## 1. Customer 360

- [x] 1.1 `Customer360Controller` reads the client with `find()` by name, RBAC and multitenancy on; no privileged-group check; NotAuthorizedException is 403, missing is 404, other failures 500
  - Verify: `Customer360ControllerTest` (11 tests; on the old code the payload, access log, 500 path and named call tests fail, and the 403 test cannot construct the old controller)

## 2. Leads on a deleted pipeline (F1)

- [x] 2.1 Demo reseed re-links existing demo objects' `pipeline`
  - Verify: `DemoSeedServiceTest::testReseedRelinksDemoLeadsOnADeletedPipeline` fails on the old code
- [x] 2.2 `OrphanedLeadPlacer::forOrphanedLead()`, `OrphanedLeadRepairService`, repair step `RelinkOrphanedLeads` in info.xml
  - Verify: `LeadStagePlacerTest` (orphan cases), `OrphanedLeadRepairServiceTest` (new code paths; absent on the old code)
- [x] 2.3 Board notice for leads on no board, and the opening rule
  - Verify: `tests/vitest/pipelineOrphans.spec.js` (the opening test fails on the old code)

## 3. Detail pages (E2, booking, line items)

- [x] 3.1 Manifest layout and widget fixes on ContactDetail, LeadDetail, BookingDetail
  - Verify: `tests/vitest/detailPageWidgets.spec.js` (7 of 10 fail on the old manifest; the 3 overlap checks pass on both)
- [x] 3.2 `leadProduct` named after its product; `qualificationScore` read-only and described as calculated
  - Verify: register fragment merges; `check-schema-l10n` 0 uncovered
- [x] 3.3 Composition steps named from the editor catalogue; failed reads not cached
  - Verify: `tests/vitest/serviceStepProductNames.spec.js` (2 of 2 fail on the old code)

## 4. Lead source

- [x] 4.1 `tender` in `InitializeSettings::DEFAULT_LEAD_SOURCES`
  - Verify: `LeadSourceDefaultsTest` fails on the old code

## 5. Update notifications (E1)

- [x] 5.1 Fragment `98-update-notifications.json`: `updated` rules on client, lead, ticket and crmTask to their owner and assignee fields
  - Verify: `UpdateNotificationRulesTest` (2 of 3 fail on the old register, the third has no rules to check); `check_notification_dialect.py` clean on the fragment
