# master-data-management (delta — mdm-consume-or-surface-frontend)

This delta removes pipelinq's app-hosted MDM steward UI (ADR-045 #D) and replaces it with a single
deep-link into OpenRegister's Data-Quality surface. It depends on `mdm-consume-or-surface`, which
declares the `x-openregister-survivorship` / `x-openregister-merge` annotations that make OR host
the surface. Backend service/controller/job and `match*`/`trustConfiguration` schema removal is the
sibling link `mdm-consume-or-surface-backend`.

## ADDED Requirements

### Requirement: REQ-MDM-013 — MDM Steward UI Deep-Linked to OpenRegister

The system MUST NOT host its own Master Data Management steward views. The app-local MDM views
(`MdmMasterEntityListView`, `MdmDuplicateCandidatesDashboard`, `MdmSyncQueueAdmin`), in-body
sections (`MdmDataQualitySection`, `MdmGoldenRecordSection`) and modals
(`MdmConflictResolutionModal`, `MdmMergeWizardModal`) MUST be removed, together with their
`src/registry.js` imports + registrations and their `manifest.d` pages and nav entries. In their
place the app MUST expose exactly ONE navigation entry that deep-links to OpenRegister's
Data-Quality surface (`/index.php/apps/openregister/#/quality`), where the steward selects the
pipelinq register and `masterEntity` schema in OpenRegister's own register/schema selector. No
app-local MDM dashboard, list, merge wizard or conflict-resolution modal may remain.

**Feature tier**: MVP
**Handoff**: Consumes OpenRegister `mdm-quality-api`, `mdm-survivorship`, `mdm-merge`, `duplicate-detection`, `mdm-conflict-resolution-ui` (steward views hosted by OR). Backend deletion is retired to the sibling backend link.

#### Scenario: App-local MDM views are removed

- WHEN the pipelinq `src/` tree is inspected after this change
- THEN none of `MdmMasterEntityListView`, `MdmDuplicateCandidatesDashboard`, `MdmSyncQueueAdmin`, `MdmDataQualitySection`, `MdmGoldenRecordSection`, `MdmConflictResolutionModal` or `MdmMergeWizardModal` MUST exist as a file or be imported / registered in `src/registry.js`
- AND the production build MUST resolve with no unresolved import from those deletions

`@e2e exclude` structural deletion — verified by a repo-wide grep for the seven component names + the `/mdm/` routes returning nothing under `src/`, and by a passing `npm run build`.

#### Scenario: A single deep-link nav entry replaces the three MDM entries

- WHEN `src/manifest.d/90-master-data-management.json` is inspected
- THEN it MUST declare no app-hosted MDM page and exactly one `href` nav entry labelled "Data quality"
- AND that entry's `href` MUST target OpenRegister's Data-Quality surface (`/index.php/apps/openregister/#/quality`), not an app-local route

`@e2e exclude` structural manifest assertion — verified by parsing the manifest fragment (one `href` menu entry, empty `pages`) in the build/lint step; the live steward surface it links to lives in OpenRegister's own e2e suite.

#### Scenario: Steward scopes the OR surface to pipelinq/masterEntity

- GIVEN the "Data quality" nav entry opens OpenRegister's Data-Quality index
- WHEN the steward selects the pipelinq register and the `masterEntity` schema in OpenRegister's in-page register/schema selector
- THEN OpenRegister's Data-Quality view MUST show the pipelinq `masterEntity` quality distribution and lowest-quality objects (query params are not required, because OpenRegister scopes via the selector, not the URL)

`@e2e exclude` cross-app surface owned by OpenRegister — the register/schema selection + quality rendering are covered by OpenRegister's `mdm-frontend` e2e suite; pipelinq only contributes the deep-link entry point.
