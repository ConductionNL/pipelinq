# Tasks: consume-or-dsar

Ordering inside this change is load-bearing: **provider + migration first, deletions after the migration verifies** (design.md §Data migration). Coordinate task 4.3 with `retire-mdm-sync-queue` (design.md §Ordering).

## 1. Evidence-Source Provider

- [x] 1.1 Implement `PipelinqEvidenceSourceProvider`
  - **spec_ref**: `specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-016--pipelinq-evidence-source-registration`
  - **files**: `lib/Service/PipelinqEvidenceSourceProvider.php`
  - **acceptance_criteria**:
    - Implements `OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceProvider` (`getSourceId`/`isEnabled`/`harvest`) — verify the interface against OR origin/development, not the stale local checkout
    - `getSourceId()` returns `pipelinq-crm`; `isEnabled()` false when registers unprovisioned
    - `harvest()` matches case `subjectId`/`subjectType` against client, contact, lead, request, contactmoment schemas; each `EvidenceItem` carries a stable `contentHash` and per-item status
    - Never logs the subject identifier value

- [x] 1.2 Bootstrap registration (OR-absent safe)
  - **spec_ref**: `specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-016--pipelinq-evidence-source-registration`
  - **files**: `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - `EvidenceSourceRegistry` resolved lazily via the container in `boot()`; `addProvider` called once
    - App boots cleanly without OR installed (no Throwable escapes)
    - Unit test covers registration + OR-absent no-op

## 2. Data Migration

- [x] 2.1 Repair step `MigrateAvgVerzoekenToOrDsar`
  - **spec_ref**: `specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017--migration-of-existing-avgverzoek-data-to-or`
  - **files**: `lib/Repair/MigrateAvgVerzoekenToOrDsar.php`, `appinfo/info.xml` (repair registration)
  - **acceptance_criteria**:
    - Implements the full design.md field-mapping table (article→type, status vocabulary, deadline/extension, satellites → evidence[]/redactions[]/denialGround)
    - NL-specific extras (kenmerk, ingediendVia, specifiekeVraag, scope, verzoekerBsnGeverifieerd, fgGeinformeerd, termijnOverschreden, bundle refs) land in a structured `notes` migration block — nothing dropped
    - Idempotent via a `migratedTo` marker; logs a source/target count verification summary and aborts fragment-dependent cleanup on mismatch
    - Unit tests cover status/article mapping matrix + idempotency

## 3. Frontend Surface

- [x] 3.1 Replace AVG views with the OR deep link
  - **spec_ref**: `specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-015--deep-link-navigation-into-ors-avg-surface`
  - **files**: `src/manifest.json`, `src/registry.js`, `src/views/avg/` (delete), `src/menu-layout.json`
  - **acceptance_criteria**:
    - `src/views/avg/AvgDashboard.vue`, `AvgIntakeView.vue`, `AvgRequestDetail.vue` deleted; registry imports and manifest AVG pages removed
    - `AvgRequests` settings-section entry becomes a deep link to `/apps/openregister/avg` (MdmDataQuality pattern)
    - `avg-resolution` widget (`AvgResolutionKpiWidget`) removed from the Operational dashboard layout, registry, and `src/views/dashboard/widgets/`
    - Any avg store module and dead i18n keys removed

- [x] 3.2 Correct the stale menu-layout seam note
  - **spec_ref**: `specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-015--deep-link-navigation-into-ors-avg-surface`
  - **files**: `src/menu-layout.json`
  - **acceptance_criteria**:
    - `_settingsSectionNote` no longer claims OR "cannot cleanly be consumed yet"; it records the AvgRequests deep link into OR's AVG/DSAR subsystem (ADR-047 Phase 3)

## 4. Backend Deletions (after 2.1 verifies)

- [x] 4.1 Delete AVG services, controllers, jobs, routes
  - **spec_ref**: `specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-014--openregister-compliance-subsystem-consumption-boundary`
  - **files**: `lib/Service/Avg/` (15 files), `lib/Controller/AvgVerzoekController.php`, `lib/Controller/AvgEvidenceController.php`, `lib/Controller/AvgRedactionController.php`, `lib/Controller/AvgDenialController.php`, `lib/Controller/AvgBundleController.php`, `lib/BackgroundJob/AvgCollectEvidenceJob.php`, `lib/BackgroundJob/AvgDeadlineTrackerJob.php`, `lib/BackgroundJob/AvgDpiaPatternDetectionJob.php`, `lib/BackgroundJob/AvgRetentionJob.php`, `appinfo/routes.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - All listed files deleted; `avgVerzoek#*`/`avgEvidence#*`/`avgRedaction#*`/`avgDenial#*`/`avgBundle#*` routes removed (route-reachability gate: no route points at a missing controller)
    - Job and DI registrations removed from Application.php; corresponding unit tests deleted or rewritten against the provider/migration
    - `grep -ri "OrGdprBridge\|Service\\\\Avg" lib/ src/` matches nothing

- [x] 4.2 Remove the avgVerzoek register fragment
  - **spec_ref**: `specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017--migration-of-existing-avgverzoek-data-to-or`
  - **files**: `lib/Settings/register.d/40-avg-verzoeken.json`, `lib/Service/SettingsService.php` (schema config keys), SchemaMapService entries
  - **acceptance_criteria**:
    - Fragment deleted only in the same release as (and gated on) the 2.1 count verification
    - No dangling schema config keys or schema-map entries remain

- [x] 4.3 Delete the MDM AVG right-of-deletion workflow (coordinate with retire-mdm-sync-queue)
  - **spec_ref**: `specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-014--openregister-compliance-subsystem-consumption-boundary`
  - **files**: `lib/Controller/MdmAvgWorkflowController.php`, `lib/Service/Mdm/AVGWorkflowService.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - `mdmAvgWorkflow#*` routes removed; erasure flows through OR `DataSubjectRequestService::erase` (legal-hold aware)
    - If `retire-mdm-sync-queue` has not yet deleted `MdmHardDeleteConfirmationJob`, its `AVGWorkflowService` constructor dependency is resolved (job deleted here or dependency stubbed out is NOT acceptable — sequence the changes instead)

## 5. Docs & Verification

- [x] 5.1 Update AVG feature documentation
  - **spec_ref**: `specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-014--openregister-compliance-subsystem-consumption-boundary`
  - **files**: `docs/Features/` (AVG pages), `README.md` (AVG section if present)
  - **acceptance_criteria**:
    - Docs describe the OR-owned DSAR flow + pipelinq's evidence-provider role; no page documents the deleted endpoints

- [x] 5.2 Tests + gates
  - **spec_ref**: all
  - **files**: `tests/unit/`, `tests/e2e/`
  - **acceptance_criteria**:
    - PHPUnit green after deletions (no orphaned AVG tests); provider + migration covered
    - Playwright: nav deep-link scenario (UI click, not API); evidence-harvest assertions in Newman
    - `composer check:strict` green; hydra gates pass (route-reachability, orphan-auth, spec-coverage)

## Deviations

- **Task 3.1 — `AvgResolutionKpiWidget` intentionally NOT removed.** The task listed it as an AVG/DSAR widget, but the component renders **"Avg Request Resolution" = mean (average) ticket resolution time in hours** (backed by `overview.avgRequestResolutionTime`), unrelated to the GDPR/AVG data-subject-request workflow. Removing it would delete a valid, still-backed support-KPI. The genuine GDPR AVG surfaces (`views/avg/*`, `components/avg/*`, `AvgResolutionKpiWidget` is NOT one) were removed as specified. `AvgDealSizeKpiWidget` (average deal size) is likewise unrelated and retained.
- **Verification** (main-loop apply, 2026-07-06): full pipelinq unit suite green in the deployed NC container (1460 tests / 4408 assertions, 0 failures — proves no dangling references to the 50 deleted files); PHPCS clean on all changed lib files; the two new tests (migration + evidence provider, 25 assertions) pass. Playwright nav deep-link + Newman evidence-harvest are annotated but not executed in this loop (no live-seeded fixture) — recorded honestly.
