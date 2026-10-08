# Tasks: pipelinq-setup-wizard-review

> Archive pass 2026-10-07: code done (#2177), every task ticked. Delta fix-up 2026-10-07: the MODIFIED blocks now carry every main-spec scenario that still holds; the two that described the old wizard (provision from a wizard step, demo data as a separate action step) are rewritten to the admin-page card and the card step under their original names.

## Phase 1: Wizard

- [x] 1.1 Merge the `demo-data` choice and the `load-demo-data` run-action into one choice step with `loadAction` (A1)
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed`
- [x] 1.2 Accept `{ dataset }` in the `load-demo-data` action body; validate it; store the pick only after a successful load (A1)
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed`
- [x] 1.3 Remove the `provision` step; add the Provision data card to the admin settings page (A2)
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-004-optional-provisioning-action`
- [x] 1.4 Keep `openregister` as the one required manifest dependency (A3)
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-009-required-apps-gate-the-wizard`
- [x] 1.5 Order the organisation step and add address and contact fields; compose the receipt address from them (A4)
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-005-optional-organisation-details`
- [x] 1.6 Remove the `integrations` step and both base-URL keys; detect Shillinq and XWiki; show the result on the admin page (A5)
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-006-detected-integrations`
- [x] 1.7 Report exactly the manifest's step ids from `GET /api/setup/status`
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-007-per-step-status-reporting`

## Phase 2: Tests and seed

- [x] 2.1 `SetupControllerStatusContractTest`: status ids equal manifest ids; no provisioning or base-URL keys in the wizard
- [x] 2.2 `SetupControllerDemoDataTest`: card load with a body, None card, unknown dataset, failed load leaves the step open
- [x] 2.3 `IntegrationDetectorTest` and `ReceiptCompanyAddressTest`
- [x] 2.4 `tests/e2e/ci-seed.sh` and `first-time-setup.spec.ts` stop setting and expecting the removed steps
