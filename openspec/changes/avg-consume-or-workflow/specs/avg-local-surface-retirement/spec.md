## ADDED Requirements

### Requirement: pipelinq removes its local AVG case controllers and routes (BREAKING)
pipelinq SHALL delete its local AVG case-management controllers and their `appinfo/routes.php` entries, because the AVG case workflow is now owned by OpenRegister (ADR-047). **BREAKING**.
The deleted controllers MUST be `AvgBundleController`, `AvgDenialController`, `AvgEvidenceController`, `AvgRedactionController`, `AvgVerzoekController`, `MdmAvgWorkflowController`, `BrpController`, and `LoyaltyGdprController`, and every one of their route entries in `appinfo/routes.php` MUST be removed. No pipelinq controller MUST persist an `avgVerzoek`/data-subject-request case entity or expose an AVG case API after this change (ADR-047 anti-pattern gate-23).

#### Scenario: The AVG controllers and their routes are gone
- **WHEN** pipelinq's `lib/Controller/` and `appinfo/routes.php` are inspected after this change
- **THEN** none of the eight named AVG/BRP/LoyaltyGdpr controllers MUST exist
- **AND** no route entry MUST target a removed AVG controller (no router 404 / no reflection 500)

#### Scenario: No local AVG case API remains
- **WHEN** pipelinq's routes are inspected
- **THEN** no `avgVerzoek`/`avgEvidence`/`avgDenial`/`avgBundle`/`avgRedaction`/`mdmAvgWorkflow` case endpoint MUST be exposed by pipelinq
- **AND** AVG case operations MUST go to OpenRegister's `/api/gdpr/cases/*` surface instead

@e2e An administrator inspecting pipelinq after retirement finds no local AVG case pages or endpoints and reaches the AVG surface only via the OR deep-link.

### Requirement: pipelinq removes its local AVG services (BREAKING)
pipelinq SHALL delete every service under `lib/Service/Avg/`, including `OrGdprBridge`, because the case spine (lifecycle, deadline, evidence, redaction, bundle, denial, retention) is now OR's and the bridge's delegation is superseded by direct OR case consumption. **BREAKING**.
All fifteen `lib/Service/Avg/*.php` services MUST be removed and no pipelinq service MUST re-implement deadline-tracking, evidence collection, denial, redaction, bundling, or retention for data-subject requests (ADR-047 anti-pattern). The NL jurisdiction values these services used to hold MUST now live only in the `dsarPolicyPack` data (ADR-031).

#### Scenario: The lib/Service/Avg directory is removed
- **WHEN** pipelinq's `lib/Service/` tree is inspected after this change
- **THEN** the `Avg/` service directory and all fifteen services (including `OrGdprBridge`) MUST be gone
- **AND** no remaining pipelinq service MUST implement an AVG case-workflow engine

#### Scenario: Jurisdiction values are data, not services
- **WHEN** pipelinq's code is searched for AVG deadline/denial/retention constants
- **THEN** no such hard-coded jurisdiction value MUST remain in pipelinq code
- **AND** those values MUST be resolved from the `dsarPolicyPack` data

@e2e A reviewer confirms pipelinq's `lib/Service/Avg` is removed and the ADR-022/ADR-047 anti-pattern gate passes.

### Requirement: pipelinq removes its AVG/BRP background jobs and info.xml registrations (BREAKING)
pipelinq SHALL delete its AVG/BRP background jobs and remove their `<background-jobs>` registrations from `appinfo/info.xml`, with a repair path so orphaned `oc_jobs` rows are cleaned. **BREAKING**.
The removed job classes MUST include `AvgDeadlineTrackerJob`, `AvgDpiaPatternDetectionJob`, `AvgRetentionJob`, `BrpHealthCheckJob`, `BrpMonitorJob`, `BrpRetentionJob` (plus the unregistered `AvgCollectEvidenceJob`), their six `<job>` entries in `appinfo/info.xml` MUST be removed, and a repair/upgrade step MUST unregister the removed jobs from `oc_jobs` so the scheduler does not log a missing-class error every tick.

#### Scenario: The AVG/BRP jobs and their registrations are removed
- **WHEN** pipelinq's `lib/BackgroundJob/` and `appinfo/info.xml` are inspected after this change
- **THEN** the named AVG/BRP job classes MUST be gone and their six `<job>` entries MUST be absent from `info.xml`
- **AND** the scheduler MUST NOT reference a removed AVG/BRP job class

#### Scenario: Orphaned oc_jobs rows are cleaned on upgrade
- **WHEN** pipelinq is upgraded past this change on an install that had the removed jobs scheduled
- **THEN** a repair/upgrade step MUST unregister the six removed jobs from `oc_jobs`
- **AND** the background-job scheduler MUST NOT emit a missing-class error for them

@e2e After upgrading pipelinq past this change, an administrator sees no AVG/BRP background jobs registered and no missing-class scheduler errors.

### Requirement: pipelinq removes its AVG frontend and corrects the stale menu note (BREAKING)
pipelinq SHALL delete its AVG views and `avgApi.js`, replace the AVG manifest pages with the OR deep-link entry, and correct the stale `_settingsSectionNote`. **BREAKING**.
The removed frontend MUST be `src/views/avg/AvgDashboard.vue`, `src/views/avg/AvgIntakeView.vue`, `src/views/avg/AvgRequestDetail.vue`, and `src/services/avgApi.js`; `src/manifest.d/40-avg-verzoeken.json` MUST no longer declare the internal `AvgRequests`/`AvgIntake`/`AvgRequestDetail` pages; and the `_settingsSectionNote` in `src/menu-layout.json` MUST be corrected to state that pipelinq now consumes OR's AVG/DSAR surface (removing the stale claim that pipelinq cannot cleanly consume it).

#### Scenario: The AVG views and avgApi are removed
- **WHEN** pipelinq's `src/views/avg/` and `src/services/` are inspected after this change
- **THEN** the three AVG views and `avgApi.js` MUST be gone
- **AND** `src/manifest.d/40-avg-verzoeken.json` MUST NOT declare any internal AVG page component

#### Scenario: The stale settings note is corrected
- **WHEN** `src/menu-layout.json` `_settingsSectionNote` is read after this change
- **THEN** it MUST state that pipelinq consumes OpenRegister's AVG/DSAR surface via a deep-link
- **AND** it MUST NOT claim that pipelinq cannot cleanly consume the OR AVG/DSAR seam

@e2e A reviewer confirms pipelinq's AVG views and avgApi.js are removed and the settings note reflects OR consumption rather than the stale "cannot consume" claim.
