# Proposal: consume-or-dsar

kind: refactor/consolidation — **ADR-047 Phase 3**: retire pipelinq's parallel AVG/DSAR stack in favor of OpenRegister's shipped DSAR case subsystem. Owner decision 2026-07-05: `dataSubjectRequest` belongs in OR; no app runs its own stack for anything OR owns.

## Problem

OpenRegister (origin/development) now ships a **full DSAR case subsystem** — the thing pipelinq's AVG stack was built to approximate while "OR could not cleanly be consumed yet":

- `lib/Service/Gdpr/DataSubjectRequestService.php` (findSubjectData / assembleAccessExport / rectify / erase / restriction / objection / computeDueAt / extend / isOverdue)
- Case services: `Gdpr/Case/CaseAccessControl`, `Gdpr/Case/CaseObjectAccessor`
- Evidence: `Gdpr/Evidence/EvidenceHarvestService` + pluggable `EvidenceSourceRegistry` / `EvidenceSourceProvider` (ADR-019 seam: leaf apps register providers at bootstrap; unregistered sources contribute no evidence)
- Export: `Gdpr/Export/ExportBundleService`, `PadesSigner` / `UnsignedPadesSigner`, `SignedBundle`, `OneTimeDownloadTokenStore`
- Lifecycle: `Gdpr/Lifecycle/DenialFinaliseGuard`; Redaction: `Gdpr/Redaction/RedactionWriteService`; Retention: `Gdpr/Retention/RetentionSweepService` + `lib/BackgroundJob/DsarRetentionSweepJob.php`
- Pluggable seams: `Gdpr/Identity/IdentityVerifyRegistry` (+ Null provider) and `Gdpr/Regulator/RegulatorEscalateRegistry` (+ Null provider), policy packs via `Gdpr/Policy/DsarPolicyPackResolver` + `lib/Settings/dsar_policy_pack_register.json`
- A `lib/Settings/data_subject_request_register.json` register with a `dataSubjectRequest` schema (type/status/deadline/denial/retention/evidence/redactions fields)
- Routed endpoints: `/api/gdpr/cases` (create / transition / evidence / redactions / bundle / bundle download / dossier / verify-identity / escalate), `/api/gdpr/*` data-subject-rights verbs, `/api/avg/verwerkingen` processing log
- An AVG UI page: `UiController::avg()` served at `/apps/openregister/avg` (route `ui#avg`)

Meanwhile pipelinq still runs the **full parallel stack**:

- **15 services** in `lib/Service/Avg/` (AvgAccessService, AvgEventService, AvgNotificationService, AvgRepository, AvgRequestService, BundleService, DeadlineService, DeadlineTrackerService, DenialService, DpiaDetectionService, EvidenceCollectionService, ExtensionService, OrGdprBridge, RedactionService, RetentionService — 4,977 LOC total)
- **6 controllers**: AvgVerzoekController, AvgEvidenceController, AvgRedactionController, AvgDenialController, AvgBundleController, MdmAvgWorkflowController (+ `lib/Service/Mdm/AVGWorkflowService`), with ~30 routes under `/api/avg-verzoeken`, `/api/export-bundles`, `/api/mdm/avg-workflow`
- **4 background jobs**: AvgCollectEvidenceJob, AvgDeadlineTrackerJob, AvgDpiaPatternDetectionJob, AvgRetentionJob
- The `avgVerzoek` (+ termijnEvent, bewijsItem, exportBundle, weigering, redactieActie) schemas in `lib/Settings/register.d/40-avg-verzoeken.json`
- Frontend: `src/views/avg/` (AvgDashboard, AvgIntakeView, AvgRequestDetail), the `AvgRequests` settings-section nav entry, the `avg-resolution` KPI widget on the Operational dashboard
- `lib/Service/Avg/OrGdprBridge.php`, which **already delegates** discovery, deadline maths, export assembly, and pseudonymise-erase to OR's engine — proof the app-side stack is a duplicated shell around OR primitives

`src/menu-layout.json` (`_settingsSectionNote`, ~line 52) still carries the now-FALSE claim that AvgRequests is "kept under Settings until that OR seam lands — a prior seam found pipelinq cannot cleanly consume it yet". The seam has landed.

Running two DSAR engines is exactly what ADR-047 forbids: double deadline semantics, double retention sweeps, double export/signing paths, and a compliance audit surface twice the size it needs to be.

## Solution

1. **Delete the parallel stack** — all 15 `lib/Service/Avg/` services, the 6 AVG controllers + their routes, the 4 AVG background jobs, the `40-avg-verzoeken.json` register fragment, `src/views/avg/`, and the related registry/manifest wiring. The design.md carries a per-service **pipelinq → OR mapping table** so every deletion is auditable.
2. **Keep a thin app-side surface only** — the `AvgRequests` nav entry becomes a **deep link** to OR's AVG UI at `/apps/openregister/avg` (same pattern as the existing `MdmDataQuality` deep link introduced by ADR-045 #D). No embedded pipelinq AVG views remain.
3. **Register pipelinq as an evidence source** — a `PipelinqEvidenceSourceProvider` implementing OR's `EvidenceSourceProvider` interface (`getSourceId` / `isEnabled` / `harvest`), registered at bootstrap into `EvidenceSourceRegistry`, so client / contact / lead / request / contactmoment objects are discovered during DSAR fulfilment. This is the ADR-019 seam: without registration pipelinq data is invisible to OR's harvest.
4. **Migrate existing data** — a repair step converts existing `avgVerzoek` objects (and their termijnEvent/bewijsItem/exportBundle/weigering/redactieActie satellites) into OR `dataSubjectRequest` cases with a field-mapping table (design.md). Lossless where the OR schema has a field; NL-specific extras are preserved in a structured migration block in `notes`.
5. **Fix the stale claim** — update the `_settingsSectionNote` in `src/menu-layout.json`.
6. **OR-side gaps become OR requirements, not app code** — the two capabilities pipelinq has that OR genuinely lacks (DPIA *pattern detection* — OR's schema has only a `dpiaRequired` flag — and proactive deadline-escalation notifications a la `AvgDeadlineTrackerJob`) are recorded as OR-side delta requirements in design.md §"OR-side gaps". They are NOT a reason to keep pipelinq services.

## Scope

- Backend deletions: `lib/Service/Avg/**` (15 files), `lib/Controller/Avg{Verzoek,Evidence,Redaction,Denial,Bundle}Controller.php`, `lib/Controller/MdmAvgWorkflowController.php`, `lib/Service/Mdm/AVGWorkflowService.php`, `lib/BackgroundJob/Avg*.php` (4 jobs), route entries, DI registrations, related unit tests
- Schema deletion: `lib/Settings/register.d/40-avg-verzoeken.json` (after migration)
- New backend: `lib/Service/PipelinqEvidenceSourceProvider.php` + bootstrap registration; `lib/Repair/MigrateAvgVerzoekenToOrDsar.php`
- Frontend: delete `src/views/avg/**`, registry imports, manifest AVG pages, the `avg-resolution` Operational-dashboard widget; re-point the `AvgRequests` settings-section entry to a deep link
- Docs/nav: `src/menu-layout.json` note, feature docs touching AVG

**Depends on:** OpenRegister origin/development DSAR case engine (`dsar-case-engine` + `dsar-integration-seams` changes, landed 2026-07-05), ADR-047 (AVG/DSAR → OpenRegister), ADR-019 (registry seams), ADR-045 (no parallel subsystems).

## Out of Scope

- Any change to OR itself — the two identified gaps are handed to OR as delta requirements (design.md §OR-side gaps), authored in the openregister repo
- The MDM sync-queue retirement (`retire-mdm-sync-queue`, parallel change; only `MdmHardDeleteConfirmationJob`'s AVGWorkflowService coupling is coordinated between the two — see design.md §Ordering)
- Request→Case semantic handoff (`semantic-handoff-emit`, parallel change)
- OR's `verwerkingsactiviteiten` (Art-30 register) — pipelinq never had a parallel implementation of it

## Success Criteria

- `lib/Service/Avg/` no longer exists; `grep -r "Avg" lib/Controller/` matches nothing; zero routes under `/api/avg-verzoeken`
- The AvgRequests nav entry opens OR's `/apps/openregister/avg` page
- Creating a DSAR case in OR and running evidence collection harvests pipelinq client/contact/lead/request/contactmoment objects tagged with source id `pipelinq-crm`
- Every pre-existing `avgVerzoek` object has a migrated `dataSubjectRequest` counterpart in OR's register with equivalent type/status/deadline/handler/retention values; the migration is idempotent
- `src/menu-layout.json` no longer claims OR "cannot cleanly be consumed yet"
- `composer check:strict` green; no dead DI wiring or orphaned tests remain
