## 1. Gate + policy pack

- [ ] 1.1 Confirm the cross-repo gate: OR changes `dsar-case-subsystem`, `dsar-case-engine`, `dsar-policy-pack-and-seams`, `dsar-integration-seams`, `dsar-case-ui` are merged + released and the OR AVG case UI + seams are live on the target instance (human sign-off; do NOT proceed otherwise)
- [ ] 1.2 Author the NL `dsarPolicyPack` object (Dutch art-12 deadlines, art-23 denial grounds + citations, Boekhoudplicht/RvIG retention windows, intake channels, DPO/FG role mapping, letter-template refs, seam selectors) conforming to OR's released `dsarPolicyPack` schema, with safe placeholders for all ids/tokens/roles
- [ ] 1.3 Wire the NL pack to import onto OR's `dsar-policy-packs` register via `ConfigurationService`/repair, validated by `ObjectService` schema validation
- [ ] 1.4 Apply-time deletion gate (human sign-off) for `LoyaltyGdprController` + `BrpController`: verify `LoyaltyController`/`LoyaltyReportingController`/`BrpAdminController` have NO hard dependency on them AND that the `loyalty-program` `@spec` no longer requires `LoyaltyGdprController`, BEFORE deleting them in 3.1/3.5; if a dependency exists, carve those two out into a separate follow-up change and do NOT delete here

## 2. Seam bindings + deep-link

- [ ] 2.1 Implement + register a pipelinq `IdentityVerifyProvider` (BSN/BRP/RvIG, id `pipelinq-bsn-brp`, three-state result) into OR's `IdentityVerifyRegistry` from `lib/AppInfo/Application.php` (first-wins, ADR-019)
- [ ] 2.2 Implement + register a pipelinq `RegulatorEscalateProvider` (AP-complaint, id `pipelinq-ap-complaint`, reference+status result) into OR's `RegulatorEscalateRegistry` from `lib/AppInfo/Application.php` (first-wins, ADR-019)
- [ ] 2.3 Replace the `AvgRequests` internal route in `src/manifest.d/40-avg-verzoeken.json` with a deep-link menu entry into OR's AVG case surface (Cases tab); keep it in the settings foldout (ADR-019/044)

## 3. Retire the local AVG surface (BREAKING)

- [ ] 3.1 Delete the AVG controllers: `AvgBundleController`, `AvgDenialController`, `AvgEvidenceController`, `AvgRedactionController`, `AvgVerzoekController`, `MdmAvgWorkflowController` — and `BrpController` + `LoyaltyGdprController` ONLY after the 1.4 gate passes
- [ ] 3.2 Delete all `lib/Service/Avg/*.php` (15 services, including `OrGdprBridge`)
- [ ] 3.3 Delete the AVG/BRP background jobs: `AvgDeadlineTrackerJob`, `AvgDpiaPatternDetectionJob`, `AvgRetentionJob`, `AvgCollectEvidenceJob`, `BrpHealthCheckJob`, `BrpMonitorJob`, `BrpRetentionJob`
- [ ] 3.4 Delete the AVG frontend: `src/views/avg/AvgDashboard.vue`, `src/views/avg/AvgIntakeView.vue`, `src/views/avg/AvgRequestDetail.vue`, `src/services/avgApi.js`
- [ ] 3.5 Remove all AVG-surface route entries from `appinfo/routes.php` (the `avgVerzoek#*`/`avgEvidence#*`/`avgDenial#*`/`avgBundle#*`/`avgRedaction#*`/`mdmAvgWorkflow#*`/`brp#*`/`loyaltyGdpr#*` entries)
- [ ] 3.6 Remove the 6 AVG/BRP `<job>` entries from `appinfo/info.xml` `<background-jobs>` and add a repair/upgrade step that unregisters the removed jobs from `oc_jobs`
- [ ] 3.7 Correct the `_settingsSectionNote` in `src/menu-layout.json` to state pipelinq now consumes OR's AVG/DSAR surface via a deep-link (remove the stale "cannot cleanly consume" claim)

## 4. Verify

- [ ] 4.1 Run the retirement/anti-pattern verification and confirm no local AVG case controller, service, job, view, route, or manifest page remains
- [ ] 4.2 Verify seam providers register and the deep-link resolves against a live OR AVG surface, and confirm no orphaned `oc_jobs` rows / missing-class scheduler errors after upgrade

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
