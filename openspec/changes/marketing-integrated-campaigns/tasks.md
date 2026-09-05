# Tasks: marketing-integrated-campaigns

## 1. Schemas and standard audiences

- [x] 1.1 `journey`, `journeyRun` and `weeklyReview` schemas, `journeyId` on `blast`, and the five seeded standard audiences
  - **spec_ref**: `specs/marketing-integrated-campaigns/spec.md#requirement-five-standard-audiences-ship-as-segments-a-marketer-copies`
  - **files**: `lib/Settings/register.d/99-marketing-integrated-campaigns.json`, `src/config/objectTypes.js`
- [x] 1.2 Dutch and English catalogue entries for every new schema string, so the l10n ratchet holds
  - **files**: `l10n/en.json`, `l10n/nl.json`, `l10n/nl.js`

## 2. Signals from the bookkeeping and from the CRM

- [x] 2.1 `ShillinqInvoiceReader::invoicesFor()`: every invoice past the draft stage, with its lines and its lifecycle state
  - **spec_ref**: `specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them`
  - **files**: `lib/Service/ShillinqInvoiceReader.php`
- [x] 2.2 `SegmentSignalService`: the eight-field catalogue, the derivations, the per-client memo and the availability report
  - **spec_ref**: `specs/marketing-integrated-campaigns/spec.md#requirement-two-more-signals-come-from-pipelinqs-own-contracts-and-leads`
  - **files**: `lib/Service/Marketing/SegmentSignalService.php`, `tests/Unit/Service/Marketing/SegmentSignalServiceTest.php`
- [x] 2.3 `SegmentService` merges the catalogue into the validator and guards the evaluator, and `GET /api/segments/signals` publishes both
  - **spec_ref**: `specs/marketing-integrated-campaigns/spec.md#requirement-an-unresolved-signal-shrinks-the-audience`
  - **files**: `lib/Service/SegmentService.php`, `lib/Controller/SegmentController.php`, `appinfo/routes.php`, `tests/Unit/Service/SegmentServiceTest.php`
- [x] 2.4 The five seeded audiences evaluate against the demo data
  - **files**: `tests/Unit/Service/Marketing/StandardAudiencesTest.php`

## 3. Journeys on OpenRegister flows

- [x] 3.1 `JourneyFlowCompiler`: trigger, wait, switch with exits, action node, and the else exit
  - **spec_ref**: `specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler`
  - **files**: `lib/Service/Marketing/JourneyFlowCompiler.php`, `tests/Unit/Service/Marketing/JourneyFlowCompilerTest.php`
- [x] 3.2 `JourneyService`: read, write, compile, publish, and the run ledger, all duck-typed against the flow engine
  - **files**: `lib/Service/Marketing/JourneyService.php`, `tests/Unit/Service/Marketing/JourneyServiceTest.php`
- [x] 3.3 `JourneyActionNode` and `FlowNodeListener`, plus the OpenRegister interface stubs the analysers need
  - **files**: `lib/Flow/JourneyActionNode.php`, `lib/Flow/FlowNodeListener.php`, `lib/AppInfo/Application.php`, `tests/Stubs/Service/Flow/`
- [x] 3.4 `JourneyStepRunner`: the consent gate, the send through the blast ledger, the task, and the recorded refusal
  - **spec_ref**: `specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact`
  - **files**: `lib/Service/Marketing/JourneyStepRunner.php`, `tests/Unit/Service/Marketing/JourneyStepRunnerTest.php`
- [x] 3.5 `JourneyController` and its routes
  - **files**: `lib/Controller/JourneyController.php`, `appinfo/routes.php`

## 4. Suppression inside the consent gate

- [x] 4.1 `ComplianceService`: the send intent, `permitsSend()`, `isSuppressed()` and the separate suppressed list
  - **spec_ref**: `specs/marketing-integrated-campaigns/spec.md#requirement-a-promotional-send-skips-a-customer-in-dunning`
  - **files**: `lib/Service/ComplianceService.php`, `tests/Unit/Service/ComplianceServiceTest.php`
- [x] 4.2 `BlastService` drops a suppressed contact from the audience and counts it separately in the send summary
  - **files**: `lib/Service/BlastService.php`

## 5. The weekly review

- [x] 5.1 `WeeklyReviewService`: one read over four collections, the degraded list, and the ADR-088 mark on a narrative
  - **spec_ref**: `specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot`
  - **files**: `lib/Service/Marketing/WeeklyReviewService.php`, `tests/Unit/Service/Marketing/WeeklyReviewServiceTest.php`
- [x] 5.2 `WeeklyReviewController` and its routes
  - **files**: `lib/Controller/WeeklyReviewController.php`, `appinfo/routes.php`
- [x] 5.3 The agent template seed, read-only and a no-op without hermiq
  - **spec_ref**: `specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-ships-as-an-agent-template-with-no-send-tool`
  - **files**: `lib/Repair/SeedWeeklyReviewAgentTemplate.php`, `appinfo/info.xml`, `tests/Unit/Repair/SeedWeeklyReviewAgentTemplateTest.php`

## 6. Interface

- [x] 6.1 Journeys pages, the journey form, the run log section and the Weekly review card
  - **files**: `src/manifest.d/79-marketing-journeys.json`, `src/manifest.json`, `src/registry.js`, `src/views/marketing/JourneyFormView.vue`, `src/views/marketing/WeeklyReview.vue`, `src/components/marketing/JourneyRunsSection.vue`, `src/services/journeysApi.js`
- [x] 6.2 The labels a run log and a review are read in, extracted so they can be tested offline
  - **files**: `src/services/journeyLabels.js`, `tests/vitest/journeyLabels.spec.js`

## 7. End to end

- [x] 7.1 Playwright coverage for the Journeys page, the signals endpoint, the seeded audiences and the Weekly review card
  - **files**: `tests/e2e/spec-coverage/marketing-journeys.spec.ts`

## 8. Documentation

- [x] 8.1 The marketing architecture page records phase 6 as shipped, and what degrades
  - **files**: `docs/Technical/marketing-architecture.md`
