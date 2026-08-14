---
status: done
---

# avg-verzoeken-workflow Specification

## Purpose
Defines the handling of AVG (GDPR) data-subject requests — classification, 30-day deadline tracking, extensions, federated evidence collection, redaction, signed export bundles, denials, AP escalation, and retention. Every requirement is a handoff: pipelinq itself does not implement these workflows but delegates them to the canonical owners (docudesk, the AVG dossier capability, and rekenkamer-audit-pack), documenting the integration contracts those owners must satisfy.
## Requirements
### Requirement: REQ-AVG-014 — OpenRegister Compliance-Subsystem Consumption Boundary

The AVG workflow SHALL be fulfilled entirely by OpenRegister's DSAR case subsystem — the `dataSubjectRequest` register, `DataSubjectRequestService`, the case lifecycle (`/api/gdpr/cases` create/transition/evidence/redactions/bundle/dossier/verify-identity/escalate), and OR's AVG UI page. Pipelinq SHALL NOT run any app-side AVG service, controller, background job, schema, or view: `lib/Service/Avg/` (all 15 services including `OrGdprBridge`), the five `Avg*` controllers plus `MdmAvgWorkflowController` and `Mdm\AVGWorkflowService`, the four `Avg*` background jobs, the `40-avg-verzoeken.json` register fragment, and `src/views/avg/` are removed. This SUPERSEDES the Phase-2 boundary (bridge-mediated delegation): with the case engine landed, the bridge and every app-side caller it served are retired. The app SHALL still never call the administrator-gated `DsarService`.

**Feature tier**: MVP

#### Scenario: No parallel DSAR engine remains

- **GIVEN** the pipelinq codebase after this change
- **WHEN** the app is installed and its routes are enumerated
- **THEN** no `/api/avg-verzoeken`, `/api/export-bundles`, or `/api/mdm/avg-workflow` route MUST exist
- **AND** `lib/Service/Avg/` MUST NOT exist
- **AND** no `Avg*` background job MUST be registered

#### Scenario: DSAR fulfilment happens in OR

@e2e exclude the assertion is that every AVG operation goes through ANOTHER APP's endpoints (`/api/gdpr/cases/…`) or OpenRegister's own AVG UI page — a surface pipelinq neither renders nor owns, and one this spec's Purpose states pipelinq deliberately does not implement. Its pipelinq-side half — that no competing endpoint exists to take the traffic — IS asserted end to end by tests/e2e/spec-coverage/avg-verzoeken-workflow.spec.ts ("no pipelinq-side DSAR route is served"), which proves the 404 against a running server rather than by grepping the routes file.

- **GIVEN** an AVG handler processing a data-subject request
- **WHEN** they intake, extend, collect evidence for, redact, deny, or export the request
- **THEN** every operation MUST go through OR's `/api/gdpr/cases` endpoints or OR's AVG UI — never a pipelinq endpoint

### Requirement: REQ-AVG-015 — Deep-Link Navigation into OR's AVG Surface

@e2e exclude SUPERSEDED BY THE 2026-07 nav-ia-cleanup — reported, not worked around. The `AvgRequests` nav entry this requirement is about no longer exists: `src/menu-layout.json#_removalsNote` records "'AVG-verzoeken' is dropped too: it was a bare deep-link into OpenRegister's DSAR engine (ADR-047 Phase 3), which owns that surface — pipelinq still contributes evidence via PipelinqEvidenceSourceProvider", and no manifest or fragment declares an `AvgRequests` menu entry or page. So there is no entry for a user to activate. The second scenario is in any case a static read of `src/menu-layout.json`'s `_settingsSectionNote` — a checked-in string, not rendered output; that note currently describes only which entries are promoted into the gear foldout and makes no claim that the OR AVG/DSAR seam is unavailable, so the requirement holds. This requirement needs rewriting against the shipped IA.

The `AvgRequests` navigation entry SHALL become a deep link that opens OpenRegister's AVG UI page (`/apps/openregister/avg`), following the ADR-045 #D `MdmDataQuality` deep-link precedent. The stale `_settingsSectionNote` claim in `src/menu-layout.json` that OR "cannot cleanly be consumed yet" SHALL be replaced with the consumed-seam reality. No pipelinq-rendered AVG view remains.

**Feature tier**: MVP

#### Scenario: Nav entry opens OR

- **GIVEN** a user with access to OR's AVG surface
- **WHEN** they activate the AVG requests navigation entry in pipelinq
- **THEN** the browser MUST navigate to `/apps/openregister/avg`

#### Scenario: Stale seam note corrected

- **WHEN** `src/menu-layout.json` is read
- **THEN** the `_settingsSectionNote` MUST NOT claim the OR AVG/DSAR seam is unavailable

### Requirement: REQ-AVG-016 — Pipelinq Evidence-Source Registration

@e2e exclude registration is an in-process bootstrap call — `Application::register()` handing a `PipelinqEvidenceSourceProvider` to OpenRegister's `EvidenceSourceRegistry` — and harvesting is invoked BY OpenRegister from inside its own DSAR case flow (`POST /api/gdpr/cases/{id}/evidence`), producing evidence items on an OpenRegister case that no pipelinq screen renders. Pipelinq exposes no route, view or DOM for any of it, so a browser has nothing to observe on this side of the seam; the third scenario is additionally a fault-injection case (OpenRegister absent) that the CI instance cannot create, since .github/workflows/code-quality.yml installs openregister via `additional-apps` and tests/e2e/ci-seed.sh aborts the run without it. Asserted by tests/Unit/Service/PipelinqEvidenceSourceProviderTest.php (testSourceId, testEnabledWhenProvisioned, testHarvestMatchesSubjectAcrossSchemas, testHarvestEmptySubjectReturnsNothing, testDisabledWhenUnprovisioned, testHarvestUnprovisionedReturnsNothing).

Pipelinq SHALL register an `EvidenceSourceProvider` (source id `pipelinq-crm`) with OpenRegister's `EvidenceSourceRegistry` at bootstrap, so DSAR evidence harvesting discovers pipelinq-held personal data — client, contact, lead, request, and contactmoment objects matching the case subject. Each harvested item SHALL carry a stable `contentHash` (idempotent re-harvest) and a per-item status. Registration SHALL degrade to a no-op when OpenRegister is absent, and the provider SHALL report `isEnabled() === false` when pipelinq's registers are not provisioned.

**Feature tier**: MVP

#### Scenario: DSAR harvest finds pipelinq data

- **GIVEN** a DSAR case in OR for a subject who exists as a pipelinq contact with linked contactmomenten
- **WHEN** evidence collection runs (`POST /api/gdpr/cases/{id}/evidence`)
- **THEN** the harvested evidence MUST include the pipelinq objects, each tagged with source id `pipelinq-crm`

#### Scenario: Idempotent re-harvest

- **GIVEN** a case already harvested once
- **WHEN** evidence collection runs again with unchanged pipelinq data
- **THEN** no duplicate evidence items MUST be added (contentHash dedupe)

#### Scenario: OR absent

- **GIVEN** a deployment without OpenRegister
- **WHEN** pipelinq boots
- **THEN** the app MUST load without error and register nothing

### Requirement: REQ-AVG-017 — Migration of Existing avgVerzoek Data to OR

@e2e exclude a one-shot `IRepairStep` that runs during `occ upgrade`, over `avgVerzoek` objects whose schema the same change removed — the `40-avg-verzoeken.json` register fragment is gone, so a CI instance has no source objects to migrate and no way to create any, and the repair step is not invocable from a browser at all. The assertions are field-by-field mappings onto an OpenRegister `dataSubjectRequest` case (article→type, NL status vocabulary→OR lifecycle, extras preserved in a notes block) plus a no-duplicates re-run — stored shape on another app's schema, with no rendered form. Asserted by tests/Unit/Repair/MigrateAvgVerzoekenToOrDsarTest.php (testMapsFullVerzoek, testArticleAndStatusMatrix, testErasureIsNotFiledAsAccess, testRedactionsResolveThroughBewijsItems, testIdempotentRerun, testRerunDoesNotDuplicateWhenSourceWasNeverMarked, testUnprovisionedNoOp).

A repair step SHALL migrate every existing `avgVerzoek` object (with its termijnEvent, bewijsItem, exportBundle, weigering, and redactieActie satellites) into an OR `dataSubjectRequest` case per the design.md field-mapping table (article→type, NL status vocabulary→OR lifecycle, deadline/extension/handler/outcome/retention fields mapped one-to-one; NL-specific extras preserved in a structured `notes` migration block). The migration SHALL be idempotent, SHALL verify source/target counts before the `40-avg-verzoeken.json` fragment is removed, and SHALL NOT silently drop any field.

**Feature tier**: MVP

#### Scenario: Lossless migration

- **GIVEN** an `avgVerzoek` with status `weigering-opgesteld`, artikel `art-17-wissing`, and a weigering satellite
- **WHEN** the repair step runs
- **THEN** a `dataSubjectRequest` MUST exist with `type = erasure`, `status = denial-drafted`, a mapped `denialGround`, and the kenmerk/ingediendVia extras in its notes block

#### Scenario: Idempotent re-run

- **GIVEN** a completed migration
- **WHEN** the repair step runs again
- **THEN** no duplicate cases MUST be created

