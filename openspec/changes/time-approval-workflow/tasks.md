# Tasks: time-approval-workflow (hand approval + invoicing to shillinq)

## 0. Ownership confirmation

- [ ] 0.1 Confirm the time-tracker leaf excludes approval + invoicing and that shillinq owns the billing lifecycle.
  - **acceptance_criteria**:
    - GIVEN `openregister/openspec/changes/integration-time-tracker/proposal.md` (out-of-scope: invoicing, approval workflows) and the shillinq billing changes
    - THEN document that approval + invoicing belong to shillinq, not Pipelinq.

## 1. Remove in-pipelinq approval scope

- [ ] 1.1 Verify no approval subsystem ships in Pipelinq.
  - **spec_ref**: `specs/time-approval-workflow/spec.md#Requirement: Approval and invoicing are owned by shillinq, not Pipelinq`
  - **files**: `pipelinq/lib/`, `pipelinq/src/`, `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the applied change
    - THEN no `timesheetPeriod`/`timesheetEditRequest` schema, no `TimesheetService`/`TimesheetController`, no approval Vue views, and no approval notifications exist in the repo.

## 2. Declare shillinq as the lifecycle owner + reachability

- [ ] 2.1 Ensure captured hours are reachable by shillinq via OR links / WIP sync.
  - **spec_ref**: `specs/time-approval-workflow/spec.md#Requirement: Captured hours are reachable by shillinq`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json` (linkedTypes from time-entry-core)
  - **acceptance_criteria**:
    - GIVEN time entries captured by the leaf and linked to Pipelinq objects
    - THEN shillinq can read them via the OR link table or WIP-sync; Pipelinq holds no approval status.

## 3. Manifest deep-link (optional)

- [ ] 3.1 Add an optional deep-link to shillinq's approval inbox in `src/manifest.json`.
  - **spec_ref**: `specs/time-approval-workflow/spec.md#Requirement: Pipelinq deep-links to shillinq's approval surface`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the manifest
    - THEN it MAY expose a menu entry / detail-page action deep-linking to shillinq
    - AND no manifest page renders a pipelinq-owned approval workflow.

## 4. Follow-up flag (not implemented here)

- [ ] 4.1 Flag `pipelinq-time-to-shillinq-wip` for re-point/archive (its trigger inverts now that shillinq owns approval).
  - **acceptance_criteria**:
    - GIVEN this change does not modify the WIP-sync change (ADR-032 bounded scope)
    - THEN the maintainer is notified to re-point or archive it separately.

## 5. Verification

- [ ] 5.1 `npm run check:manifest` passes if the manifest is touched.
- [ ] 5.2 Confirm `git grep -i timesheet` returns no pipelinq-owned approval code.
