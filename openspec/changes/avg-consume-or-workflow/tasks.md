## 1. Gate + policy pack

- [ ] 1.1 Confirm the cross-repo gate: OR changes `dsar-case-subsystem`, `dsar-case-engine`, `dsar-policy-pack-and-seams`, `dsar-integration-seams`, `dsar-case-ui` are merged + released and the OR AVG case UI + seams are live on the target instance (human sign-off; do NOT proceed otherwise) — LIVE HUMAN GATE, not codeable
- [x] 1.2 Author the NL `dsarPolicyPack` object (Dutch art-12 deadlines, art-23 denial grounds + citations, Boekhoudplicht/RvIG retention windows, intake channels, DPO/FG role mapping, letter-template refs, seam selectors) conforming to OR's released `dsarPolicyPack` schema, with safe placeholders for all ids/tokens/roles — `lib/Settings/register.d/41-avg-nl-policy-pack.json`, conforms to the REAL schema field names (deadlineDurationDays/extensionDurationDays/escalationTiers[tier,offsetDays]/templates/…)
- [x] 1.3 Wire the NL pack to import onto OR's `dsar-policy-packs` register via `ConfigurationService`/repair, validated by `ObjectService` schema validation — ships as a `components.objects` seed on the existing SettingsService→ConfigurationService::importFromApp path (InitializeSettings repair); register/schema NOT redefined (avoids union-merge corruption). Live import resolution vs OR's externally-owned register is gated on 1.1 (see DEFERRED_QUESTIONS)
- [x] 1.4 Apply-time deletion gate for `LoyaltyGdprController` + `BrpController` — EXECUTED: `LoyaltyController`/`LoyaltyReportingController`/`BrpAdminController` have NO hard dependency on either; the `loyalty-program` spec is ARCHIVED and does not actively require `LoyaltyGdprController`. Result: `LoyaltyGdprController` DELETED; `BrpController` **CARVED OUT (kept)** because it is the live `bsn-validatie-en-brp-lookup` surface with real non-AVG consumers (`BrpContactPanel.vue`, `BrpMonitor.vue`) — see DEFERRED_QUESTIONS

## 2. Seam bindings + deep-link

- [x] 2.1 Implement + register a pipelinq `IdentityVerifyProvider` (BSN/BRP/RvIG, id `pipelinq-bsn-brp`, three-state result) into OR's `IdentityVerifyRegistry` from `lib/AppInfo/Application.php` (first-wins, ADR-019) — `lib/Service/Gdpr/PipelinqBsnIdentityVerifyProvider.php`, reuses live `BsnValidationService` (11-proef) + `HaalCentraalClient` (BRP); service registered in `register()`, added to OR's registry in `boot()` (OR-absent-safe, matching OR's "boot hook" contract)
- [x] 2.2 Implement + register a pipelinq `RegulatorEscalateProvider` (AP-complaint, id `pipelinq-ap-complaint`, reference+status result) into OR's `RegulatorEscalateRegistry` from `lib/AppInfo/Application.php` (first-wins, ADR-019) — `lib/Service/Gdpr/PipelinqApRegulatorEscalateProvider.php`
- [x] 2.3 Replace the `AvgRequests` internal route in `src/manifest.d/40-avg-verzoeken.json` with a deep-link menu entry into OR's AVG case surface (Cases tab); keep it in the settings foldout (ADR-019/044) — now an `href` into `/index.php/apps/openregister/#/avg` (OR hash-routed AVG surface); internal pages removed; registry.js imports/registrations removed

## 3. Retire the local AVG surface (BREAKING)

- [x] 3.1 Delete the AVG controllers: `AvgBundleController`, `AvgDenialController`, `AvgEvidenceController`, `AvgRedactionController`, `AvgVerzoekController`, `MdmAvgWorkflowController`, `LoyaltyGdprController` deleted (7). **`BrpController` CARVED OUT (kept)** per the 1.4 gate (live non-AVG consumers)
- [x] 3.2 Delete `lib/Service/Avg/*.php` — 14 deleted. **`OrGdprBridge` CARVED OUT (kept)**: `lib/Service/DataDeletionService.php` (booking right-to-be-forgotten, non-AVG) depends on it — see DEFERRED_QUESTIONS
- [x] 3.3 Delete the AVG background jobs: `AvgDeadlineTrackerJob`, `AvgDpiaPatternDetectionJob`, `AvgRetentionJob`, `AvgCollectEvidenceJob` deleted (4). **`BrpHealthCheckJob`/`BrpMonitorJob`/`BrpRetentionJob` CARVED OUT (kept)** with the retained BRP surface
- [x] 3.4 Delete the AVG frontend: the 3 `src/views/avg/*.vue`, `src/services/avgApi.js`, plus (to avoid dangling imports) `src/components/avg/*` (7), `src/utils/avg/*` (2), `src/modals/AvgRedactionDialog.vue`; registry.js imports/registrations removed
- [x] 3.5 Remove all AVG-surface route entries from `appinfo/routes.php` — 27 removed (`avgVerzoek#*`/`avgEvidence#*`/`avgDenial#*`/`avgBundle#*`/`avgRedaction#*`/`mdmAvgWorkflow#*`/`loyaltyGdpr#*`). **`brp#*` (6) KEPT** with the carved-out BrpController
- [x] 3.6 Remove the AVG `<job>` entries from `appinfo/info.xml` (3: `AvgDeadlineTrackerJob`/`AvgDpiaPatternDetectionJob`/`AvgRetentionJob`; BRP job entries kept), bump `<version>` 0.5.32→0.5.33, add `lib/Repair/RemoveRetiredAvgJobs.php` that unregisters the 4 retired job classes from `oc_jobs`
- [x] 3.7 Correct the `_settingsSectionNote` in `src/menu-layout.json` — now states pipelinq consumes OR's AVG/DSAR surface via the deep-link (stale "cannot cleanly consume" claim removed)

## 4. Verify

- [x] 4.1 Run the retirement/anti-pattern verification and confirm no local AVG case controller, service, job, view, route, or manifest page remains — dangling-reference re-grep = ZERO; Hydra gate-14 (route-reachability), gate-17 (redundant-controller), gate-23 (or-abstraction-anti-patterns) all PASS; `composer check:strict` constituents (lint/phpcs/phpmd/psalm/phpstan/phpunit 1423 tests) all green; webpack build green
- [ ] 4.2 Verify seam providers register and the deep-link resolves against a live OR AVG surface, and confirm no orphaned `oc_jobs` rows / missing-class scheduler errors after upgrade — LIVE-gated on 1.1 (requires a gated install with OR's AVG surface + seams live); not verifiable in the no-OR CI env

## Acceptance criteria (plain-text)

- The gate is confirmed before any deletion lands; nothing is applied before the OR surface is live.
- The NL `dsarPolicyPack` validates against OR's schema and supplies every jurisdiction value (deadlines, denial grounds, retention, intake, roles, template refs, seam selectors) with safe placeholders only.
- Both seam providers register first-wins from pipelinq's bootstrap and are resolvable via the pack selectors; OR fails closed on an unknown provider id.
- The AVG nav entry deep-links into OR's case surface; no internal AVG route/page remains.
- Every enumerated controller, service, job, view, and `avgApi.js` is deleted; their routes and 6 `<background-jobs>` entries are removed; orphaned `oc_jobs` rows are cleaned on upgrade.
- The stale `_settingsSectionNote` is corrected.

## Quality gates (plain-text)

- ADR-047 anti-pattern gate (gate-23) passes: no app-local DSAR case entity/lifecycle/engine and no hardcoded jurisdiction in code.
- ADR-031: jurisdiction lives in policy-pack data, not pipelinq services.
- ADR-019: seam providers + deep-link registered the canonical way; route-reachability gate green (no route targets a deleted controller).
- `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) + ESLint pass; no forbidden debug patterns; SPDX headers on any new PHP.
- All ADDED scenarios carry an `@e2e` reference or a reason-bearing `@e2e exclude` (gate-19).
