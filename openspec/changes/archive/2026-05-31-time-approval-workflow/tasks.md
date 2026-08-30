# Tasks: time-approval-workflow (hand approval + invoicing to shillinq)

## 0. Ownership confirmation

- [x] 0.1 Confirm the time-tracker leaf excludes approval + invoicing and that shillinq owns the billing lifecycle.
  - **acceptance_criteria**:
    - GIVEN `openregister/openspec/changes/integration-time-tracker/proposal.md` (out-of-scope: invoicing, approval workflows) and the shillinq billing changes
    - THEN document that approval + invoicing belong to shillinq, not Pipelinq.
  - **DONE**: Confirmed against shillinq `openspec/specs/invoice-from-time-and-expense/spec.md` ("invoice generation from **approved** time entries"), `rate-card-management`, `wbso-uren-tagging-and-export`. Ownership recorded in `openspec/manifest.yaml` (`consumes: invoice-from-time-and-expense`, delegation note under `dependencies`). The time-tracker leaf (consumed via `integration-time-tracker`) owns capture only.

## 1. Remove in-pipelinq approval scope

- [x] 1.1 Verify no approval subsystem ships in Pipelinq.
  - **spec_ref**: `specs/time-approval-workflow/spec.md#Requirement: Approval and invoicing are owned by shillinq, not Pipelinq`
  - **files**: `pipelinq/lib/`, `pipelinq/src/`, `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the applied change
    - THEN no `timesheetPeriod`/`timesheetEditRequest` schema, no `TimesheetService`/`TimesheetController`, no approval Vue views, and no approval notifications exist in the repo.
  - **DONE**: `find lib src -iname '*timesheet*'` → none. `git grep -i timesheet` outside `openspec/changes` matches only the new delegation note + the time-entry-core spec; no schema/service/controller/view/notification. The prior bespoke draft was re-pointed before any code shipped, so there is nothing to remove — verified absent.

## 2. Declare shillinq as the lifecycle owner + reachability

- [x] 2.1 Ensure captured hours are reachable by shillinq via OR links / WIP sync.
  - **spec_ref**: `specs/time-approval-workflow/spec.md#Requirement: Captured hours are reachable by shillinq`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json` (linkedTypes from time-entry-core)
  - **acceptance_criteria**:
    - GIVEN time entries captured by the leaf and linked to Pipelinq objects
    - THEN shillinq can read them via the OR link table or WIP-sync; Pipelinq holds no approval status.
  - **DONE**: `lib/Settings/pipelinq_register.json` already declares `"linkedTypes": [..., "time-tracker"]` on client/lead/request, and `src/manifest.json` renders `CnTimeTrackerTab`/`CnTimeTrackerCard` with `linkedType: "time-tracker"`. Hours are linked to Pipelinq objects via OR integration link tables — reachable by shillinq through OR links (and the `pipelinq-time-to-shillinq-wip` WIP sync). No approval/locking status is stored Pipelinq-side. Existing glue satisfies this; no new code required.

## 3. Manifest deep-link (optional)

- [x] 3.1 Add an optional deep-link to shillinq's approval inbox in `src/manifest.json`.
  - **spec_ref**: `specs/time-approval-workflow/spec.md#Requirement: Pipelinq deep-links to shillinq's approval surface`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the manifest
    - THEN it MAY expose a menu entry / detail-page action deep-linking to shillinq
    - AND no manifest page renders a pipelinq-owned approval workflow.
  - **DONE**: Added a footer menu entry `BillingApproval` ("Timesheet approval & billing") with `href: /index.php/apps/shillinq/` (mirrors the existing `Documentation` footer `href` pattern). No new manifest `page` renders an approval workflow — the link is a cross-app deep-link only. Targets shillinq's app root (its billing/approval surface); when shillinq ships a dedicated approval route the `href` can be deepened.

## 4. Follow-up flag (not implemented here)

- [x] 4.1 Flag `pipelinq-time-to-shillinq-wip` for re-point/archive (its trigger inverts now that shillinq owns approval).
  - **acceptance_criteria**:
    - GIVEN this change does not modify the WIP-sync change (ADR-032 bounded scope)
    - THEN the maintainer is notified to re-point or archive it separately.
  - **DONE (flagged, not modified)**: `pipelinq-time-to-shillinq-wip/tasks.md` task 0.1 gates on an in-pipelinq `TimeEntryApprovedEvent` that this change deliberately does NOT create (approval now lives in shillinq). That change's trigger inverts — shillinq approves, then accrues WIP internally. Flagged in this change's `design.md` (§Knock-on) and surfaced in REMARKS_FOR_PR for the maintainer to re-point or archive separately. Not edited here (ADR-032 bounded scope).

## 5. Verification

- [x] 5.1 `npm run check:manifest` passes if the manifest is touched.
  - **N/A → satisfied**: pipelinq has no `check:manifest` npm script. Manifest validated structurally: `JSON.parse(src/manifest.json)` OK and the new menu entry uses the schema-valid `href`/`section: footer` shape already present on the `Documentation` entry. `openspec/manifest.yaml` parses as valid YAML.
- [x] 5.2 Confirm `git grep -i timesheet` returns no pipelinq-owned approval code.
  - **DONE**: only the new delegation note (`openspec/manifest.yaml`, `src/manifest.json` menu label) and the unrelated `time-entry-core` spec match — no approval code.

---

## Deferred (BLOCKED-ON-PREREQ — unshipped shillinq surface)

The core delegation (declare ownership + wire the available hand-off) is COMPLETE.
The following deeper hand-off is intentionally deferred per the change DoD because
the prerequisite does not yet exist in shillinq:

- **BLOCKED-ON-PREREQ — pipelinq→shillinq approval/handoff CloudEvent emit.**
  An automated fire-and-forget emit (like the `*-to-shillinq` WIP/ledger changes)
  requires a shillinq approval/invoice **route or event consumer** to receive it.
  Shillinq's deployed `lib/Controller/` is Dashboard/Health/Metrics/Preferences/
  Settings only; `invoice-from-time-and-expense` is **proposed, not built**
  (`@e2e exclude unbuilt UI`; depends on unbuilt `obligation-financial-administration`).
  There is no endpoint/consumer to emit to. Until shillinq ships that surface,
  the available + shipped hand-off is the manifest deep-link (Task 3.1) plus the
  OR-link reachability (Task 2.1). The CloudEvent emit is a follow-up once
  shillinq's approval surface lands. No fake emitter was added (honesty over completion).

- **Deep-link target depth.** The `BillingApproval` deep-link points at shillinq's
  app root rather than a specific `/#/timesheet-approval` route, because shillinq
  has no such Vue route yet (its `DeepLinkRegistrationListener` only registers the
  `account` schema). The `href` can be deepened once the approval route ships.
