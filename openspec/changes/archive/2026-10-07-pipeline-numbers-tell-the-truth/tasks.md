# Tasks: pipeline-numbers-tell-the-truth

## 1. Win chance (F3)

- [x] 1.1 `AnalyticsService::aggregateOpenPipeline` weights each open lead by its score (clamped 0-100)
  - Verify: `CommercialAnalyticsServiceTest::testWeightedForecastUsesQualificationScoreNotProbability` fails on the old code, passes now
- [x] 1.2 `RapportageService::getStageValues` applies the same rule
  - Verify: `RapportageServiceTest` fails on the old code with score fixtures, passes now
- [x] 1.3 Lead list, ClientDetail and ContactDetail deal lists show one "Win chance" column on `qualificationScore`
  - Verify: live, the Leads list shows "Win chance 65%" for the 250000 lead

## 2. Dashboard figures (F2)

- [x] 2.1 Revenue charts filter `status: won`; "Pipeline by stage" filters `status: open`
  - Verify: `tests/vitest/pageAppConfig.spec.js` asserts the filters on the bundled manifest
- [x] 2.2 Gauge reads `@config.currency` and `@config.pipelineTarget`; `src/utils/pageAppConfig.js` resolves the target, and seeds dashboard and detail pages
  - Verify: Vitest for set, unset and zero targets
- [x] 2.3 `pipeline_target` app setting: `Application::PIPELINE_TARGET_KEY`, `config` initial state, forecast settings API and admin field
  - Verify: `ForecastSettingsControllerTest::testPipelineTargetRoundTrips`

## 3. Every lead in a stage (F1)

- [x] 3.1 Add `lib/Service/LeadStagePlacer.php`
  - Verify: `LeadStagePlacerTest`
- [x] 3.2 Add `LeadStageCreatingListener` on `ObjectCreatingEvent` and register it
  - Verify: `LeadStageCreatingListenerTest` drives the event class; live, a lead created through the API with only a pipeline is stored in the first open stage
- [x] 3.3 `BackfillLeadRelations` places stored stage-less leads
  - Verify: `BackfillLeadRelationsTest::testPlacesAStagelessLeadInTheFirstOpenStage` fails on the old code, passes now
- [x] 3.4 Lead form falls back to the first lead pipeline and sends `stageOrder` and `stageEnteredAt`

## 4. Menu icon (F5)

- [x] 4.1 Pipeline and Pipelines menu items and the Pipeline module card use `ViewColumnOutline`

## 5. Text

- [x] 5.1 English and Dutch strings for the new labels, `npm run l10n:build`
  - Verify: `npm run test:l10n` exit 0
