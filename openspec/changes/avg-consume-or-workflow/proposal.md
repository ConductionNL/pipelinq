---
kind: mixed
depends_on: []
---

## Why

ADR-047 moves the generic AVG/DSAR *case-management workflow* into OpenRegister and reframes NL jurisdiction as **data + bindings**, not app code. Phase 1 (`dsar-case-subsystem`/`dsar-case-engine`) and Phase 2 (`dsar-policy-pack-and-seams`, `dsar-integration-seams`, `dsar-case-ui`) build the persisted case entity, the policy-pack contract, the two integration seams, and the case UI **in OpenRegister**. This change is **Phase 3, the pipelinq consumer + retirement**: pipelinq stops running its own parallel `avgVerzoek` case workflow, supplies the NL policy pack, binds its BSN/BRP/RvIG and AP-complaint providers into OR's seams, deep-links to OR's case surface, and **deletes** its local AVG surface (~5 controllers + BrpController + LoyaltyGdprController, ~15 Avg services, 6 background jobs, 3 views, avgApi.js). pipelinq becomes the pilot/reference leaf described in ADR-047's migration plan.

> **GATED — DO NOT APPLY until the OpenRegister side has landed + released.** opsx `depends_on` only references same-repo changes, so it is empty here, but this change is hard-gated on the OpenRegister changes `dsar-case-subsystem`, `dsar-case-engine`, `dsar-policy-pack-and-seams`, `dsar-integration-seams`, and `dsar-case-ui` being **implemented, merged, and released**. It supplies config for a policy-pack contract, binds providers into two registries, and deep-links to a case UI that **do not exist in OR until those changes ship**. Applying this before the OR case UI + seams exist would delete pipelinq's working AVG surface and leave citizens' data-subject requests with no landing surface. This is a **cross-repo gate**; a human MUST confirm the OR surface is live before Phase 3 is queued.

> **`kind: mixed` is intentional-pending-split.** This is authored as one documented migration head; its three capabilities (NL policy pack = config, seam bindings = code, local-surface retirement = code/deletion) are already cleanly separated. Per ADR-032 it will be split into a chain — `avg-nl-policy-pack` → `avg-seam-bindings` → `avg-retire-local-surface` (deletion strictly last) — **at queue time**, when the OR side is built and this is ready to build. It is not queued now (the cross-repo gate above is not yet satisfied).

## What Changes

- **NEW: ship the NL `dsarPolicyPack` config instance.** A Dutch-jurisdiction policy-pack object conforming to OR's `dsarPolicyPack` schema (`dsar-policy-pack-and-seams`): art-12 deadline durations + escalation-tier thresholds, the art-23 denial-grounds enum with Dutch labels + statutory citations, Boekhoudplicht/RvIG retention windows, intake channels (`handmatig`/`email`/`balie`/`post`/`webformulier`), the DPO/FG role mapping, letter-template **references**, and the two seam provider selectors (`identityVerifyProvider` → pipelinq BSN/BRP, `regulatorEscalateProvider` → pipelinq AP). Shipped as OR seed/config data, NOT PHP (ADR-031).
- **NEW: bind pipelinq providers into OR's two seams.** Register a pipelinq `IdentityVerifyProvider` (BSN/BRP/RvIG identity verification) into OR's `IdentityVerifyRegistry`, and a pipelinq `RegulatorEscalateProvider` (AP-complaint dossier/escalation) into OR's `RegulatorEscalateRegistry`, from pipelinq's `Application::register()` bootstrap, first-wins, per ADR-019.
- **CHANGED: deep-link the `AvgRequests` nav entry to OR's case surface.** Replace pipelinq's `AvgRequests` internal route (and the `AvgIntake`/`AvgRequestDetail` pages) with a single deep-link menu entry into OpenRegister's AVG case UI (`src/views/avg/AvgIndex.vue` Cases tab), per ADR-019 deep-link registry / ADR-044 menu architecture. Correct the stale `_settingsSectionNote` that claims pipelinq cannot consume OR.
- **BREAKING — REMOVED: the entire local AVG case surface.** Delete pipelinq's AVG controllers (`AvgBundleController`, `AvgDenialController`, `AvgEvidenceController`, `AvgRedactionController`, `AvgVerzoekController`, `MdmAvgWorkflowController`, `BrpController`, `LoyaltyGdprController`), the `lib/Service/Avg/*` services (including `OrGdprBridge`, whose delegation concept is superseded by direct OR case consumption), the 6 AVG/BRP background jobs, the 3 `src/views/avg/*.vue` views, `src/services/avgApi.js`, their `appinfo/routes.php` route entries, their `src/manifest.d/40-avg-verzoeken.json` menu/page entries, and their `<background-jobs>` registrations in `appinfo/info.xml`. See design.md for the exact enumerated list.
- **Data migration note:** the existing pipelinq `avgVerzoek`/BewijsItem/etc. objects already live in OpenRegister object storage (pipelinq owns no DB tables — thin-client, ADR-022). Whether any re-pointing onto OR's `dataSubjectRequest` register is needed is a migration decision captured in design.md and DEFERRED_QUESTIONS; the retirement itself removes only pipelinq code, not the stored objects.

## Capabilities

### New Capabilities
- `avg-nl-policy-pack`: the NL-jurisdiction `dsarPolicyPack` config instance pipelinq ships to parameterise OR's case workflow (deadlines, denial grounds, retention windows, intake channels, DPO mapping, template refs, seam selectors) — data conforming to OR's Phase-2 contract, no pipelinq PHP policy code.
- `avg-or-seam-bindings`: pipelinq's registration of its BSN/BRP/RvIG identity-verify provider and its AP-complaint regulator-escalate provider into OpenRegister's two integration-seam registries (ADR-019), plus the deep-link from pipelinq's nav into OR's AVG case surface.
- `avg-local-surface-retirement`: the **BREAKING** removal of pipelinq's local AVG case-management surface (controllers, services, jobs, views, avgApi.js, routes, manifest + info.xml registrations), superseded by consuming OR.

### Modified Capabilities
<!-- No existing pipelinq spec capability changes its REQUIREMENTS here; the AVG surface is being retired, not respecified in place. The retirement is expressed as the new `avg-local-surface-retirement` capability above. -->

## Impact

- **Deleted code (BREAKING):** `lib/Controller/Avg*.php` (5), `MdmAvgWorkflowController.php`, `BrpController.php`, `LoyaltyGdprController.php`; `lib/Service/Avg/*.php` (15, incl. `OrGdprBridge`); `lib/BackgroundJob/{AvgDeadlineTrackerJob,AvgDpiaPatternDetectionJob,AvgRetentionJob,BrpHealthCheckJob,BrpMonitorJob,BrpRetentionJob}.php`; `src/views/avg/*.vue` (3); `src/services/avgApi.js`.
- **Config surface:** `appinfo/routes.php` (AVG/BRP/Loyalty-GDPR route entries removed), `appinfo/info.xml` (6 `<background-jobs>` entries removed), `src/manifest.d/40-avg-verzoeken.json` (replaced by a deep-link entry), `src/menu-layout.json` (`AvgRequests` becomes a deep-link; `_settingsSectionNote` corrected).
- **New OR-facing data + bindings:** an NL `dsarPolicyPack` config object; two provider classes registered from `lib/AppInfo/Application.php`.
- **Dependencies (cross-repo, not opsx `depends_on`):** OpenRegister `dsar-case-subsystem`, `dsar-case-engine`, `dsar-policy-pack-and-seams`, `dsar-integration-seams`, `dsar-case-ui` — all must be released first.
- **Downstream:** other fleet apps (procest, zaakafhandelapp, opencatalogi) get a tracked "consume-OR-avg-workflow" issue once pipelinq proves the pattern. Sister app Procest's request-to-case bridge is unaffected (it does not consume the AVG surface).
