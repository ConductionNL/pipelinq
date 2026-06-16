# Tasks: time-approval-workflow (hand approval + invoicing to shillinq)

## 0. Ownership confirmation

- [x] 0.1 Confirm the time-tracker leaf excludes approval + invoicing and that shillinq owns the billing lifecycle.
  - **acceptance_criteria**:
    - GIVEN `openregister/openspec/changes/integration-time-tracker/proposal.md` (out-of-scope: invoicing, approval workflows) and the shillinq billing changes
    - THEN document that approval + invoicing belong to shillinq, not Pipelinq.
  - **verified**: `openspec/manifest.yaml` declares `consumes: invoice-from-time-and-expense` with shillinq as the lifecycle owner; CHANGELOG [0.2.21] records the delegation.

## 1. Remove in-pipelinq approval scope

- [x] 1.1 Verify no approval subsystem ships in Pipelinq.
  - **spec_ref**: `specs/time-approval-workflow/spec.md#Requirement: Approval and invoicing are owned by shillinq, not Pipelinq`
  - **files**: `pipelinq/lib/`, `pipelinq/src/`, `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the applied change
    - THEN no `timesheetPeriod`/`timesheetEditRequest` schema, no `TimesheetService`/`TimesheetController`, no approval Vue views, and no approval notifications exist in the repo.
  - **verified**: `git grep -rni "timesheetPeriod\|timesheetEditRequest\|TimesheetService\|TimesheetController\|TimesheetSubmit\|TimesheetApproval" lib/ src/ appinfo/` returns no results. The register (`pipelinq_register.json`) lists no approval schemas. Only reference is the footer deep-link label in `src/manifest.json`.

## 2. Declare shillinq as the lifecycle owner + reachability

- [x] 2.1 Ensure captured hours are reachable by shillinq via OR links / WIP sync.
  - **spec_ref**: `specs/time-approval-workflow/spec.md#Requirement: Captured hours are reachable by shillinq`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json` (linkedTypes from time-entry-core)
  - **acceptance_criteria**:
    - GIVEN time entries captured by the leaf and linked to Pipelinq objects
    - THEN shillinq can read them via the OR link table or WIP-sync; Pipelinq holds no approval status.
  - **verified**: `client`, `lead`, and `request` schemas in `pipelinq_register.json` all carry `"time-tracker"` in `linkedTypes`; no approval/locking status property exists on any schema.

## 3. Manifest deep-link (optional)

- [x] 3.1 Add an optional deep-link to shillinq's approval inbox in `src/manifest.json`.
  - **spec_ref**: `specs/time-approval-workflow/spec.md#Requirement: Pipelinq deep-links to shillinq's approval surface`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the manifest
    - THEN it MAY expose a menu entry / detail-page action deep-linking to shillinq
    - AND no manifest page renders a pipelinq-owned approval workflow.
  - **verified**: `src/manifest.json` contains a `BillingApproval` footer menu entry with `href: /index.php/apps/shillinq/`. No `pages` entry renders a pipelinq-owned approval workflow.

## 4. Follow-up flag (not implemented here)

- [x] 4.1 Flag `pipelinq-time-to-shillinq-wip` for re-point/archive (its trigger inverts now that shillinq owns approval).
  - **acceptance_criteria**:
    - GIVEN this change does not modify the WIP-sync change (ADR-032 bounded scope)
    - THEN the maintainer is notified to re-point or archive it separately.
  - **verified**: `design.md` section "Knock-on: pipelinq-time-to-shillinq-wip" explicitly flags this for the maintainer to re-point or archive. This change does not modify that spec (ADR-032 bounded scope).

## 5. Verification

- [x] 5.1 `npm run check:manifest` passes if the manifest is touched.
  - **verified**: `src/manifest.json` is NOT modified in this PR (the `BillingApproval` deep-link was present on development). Task is trivially satisfied.
- [x] 5.2 Confirm `git grep -i timesheet` returns no pipelinq-owned approval code.
  - **verified**: `git grep -rni timesheet -- lib/ src/ appinfo/` returns only the `BillingApproval` footer label "Timesheet approval & billing" which is a deep-link to shillinq, not pipelinq-owned approval logic.
