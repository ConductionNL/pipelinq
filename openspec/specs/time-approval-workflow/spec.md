---
status: done
---

# Time Approval Workflow Specification

## Purpose

The timesheet submit → approve → reject → lock → edit-request → invoice
lifecycle is **delegated to shillinq**, not built in Pipelinq. The time-tracker
leaf (`integration-time-tracker`) explicitly excludes approval + invoicing, and
**hydra ADR-022** forbids an app building a parallel mechanism for a capability
owned elsewhere in the fleet. Approval is the gate before billing, so it lives
with billing in shillinq (see shillinq `invoice-from-time-and-expense`,
`rate-card-management`, `wbso-uren-tagging-and-export`). Pipelinq captures hours
via the leaf, links them to its objects via OpenRegister integration link
tables, and surfaces a deep-link to shillinq's billing/approval surface.

**OpenSpec changes**: [time-approval-workflow](../../changes/archive/2026-05-31-time-approval-workflow/) _(archived 2026-05-31)_

@e2e exclude delegation/architecture spec: the timesheet approve→lock→invoice lifecycle is owned by shillinq, NOT built in Pipelinq. Scenarios assert the absence of an approval subsystem, shillinq as lifecycle owner, OR-link/WIP sync, and a manifest deep-link (not an in-app approval view) — verified by source grep + manifest assertions; no Pipelinq-owned UI surface.

## ADDED Requirements

### Requirement: Approval and invoicing are owned by shillinq, not Pipelinq

Pipelinq SHALL NOT implement the timesheet submit → approve → reject → lock →
edit-request → invoice lifecycle; that lifecycle SHALL be owned by **shillinq**.
This follows the time-tracker leaf's explicit exclusion of approval + invoicing
and hydra ADR-022 (no parallel mechanism for a fleet capability owned
elsewhere).

#### Scenario: No approval subsystem ships in Pipelinq

- **GIVEN** the time-approval-workflow change is applied
- **THEN** Pipelinq SHALL define no `timesheetPeriod` or `timesheetEditRequest`
  schema
- **AND** Pipelinq SHALL define no `TimesheetService`, `TimesheetController`, or
  approval Vue views (`TimesheetSubmit`, `TimesheetApprovalInbox`,
  `TimesheetApprovalDetail`, `TimesheetEditRequestDialog`)
- **AND** Pipelinq SHALL author no approval, locking, or submission
  notifications.

#### Scenario: shillinq is declared as the lifecycle owner

- **GIVEN** hours captured against Pipelinq objects via the time-tracker leaf
- **WHEN** an approval-before-billing step is required
- **THEN** that step SHALL be performed in shillinq, which owns approval and
  invoicing
- **AND** Pipelinq SHALL depend on shillinq exposing that surface rather than
  re-implementing it.

### Requirement: Captured hours are reachable by shillinq

Time entries captured via the time-tracker leaf SHALL be reachable by shillinq
so it can drive approval and billing, without Pipelinq introducing any approval
state of its own.

#### Scenario: Hours flow to shillinq via OR links / WIP sync

- **GIVEN** a time entry captured by the leaf and linked to a Pipelinq object
  through the OR integration link table
- **WHEN** shillinq needs the hour data for approval/billing
- **THEN** shillinq SHALL read it via the OR link or the existing WIP-sync
  integration
- **AND** Pipelinq SHALL NOT hold an approval/locking status on the entry.

### Requirement: Pipelinq deep-links to shillinq's approval surface

Pipelinq SHALL NOT render an in-app approval screen; where it helps the user it
MAY instead surface a link to shillinq's approval inbox.

#### Scenario: Manifest provides a deep-link, not an in-app approval view

- **GIVEN** Pipelinq's `src/manifest.json`
- **WHEN** a user wants to review/approve hours
- **THEN** the manifest MAY expose a menu entry or detail-page action that
  deep-links to shillinq's approval inbox
- **AND** no `type` page in the manifest SHALL render a pipelinq-owned approval
  workflow.
## Requirements
### Requirement: Approval and invoicing are owned by shillinq, not Pipelinq

Pipelinq SHALL NOT implement the timesheet submit → approve → reject → lock →
edit-request → invoice lifecycle; that lifecycle SHALL be owned by **shillinq**.
This follows the time-tracker leaf's explicit exclusion of approval + invoicing
and hydra ADR-022 (no parallel mechanism for a fleet capability owned
elsewhere).

#### Scenario: No approval subsystem ships in Pipelinq

- **GIVEN** the time-approval-workflow change is applied
- **THEN** Pipelinq SHALL define no `timesheetPeriod` or `timesheetEditRequest`
  schema
- **AND** Pipelinq SHALL define no `TimesheetService`, `TimesheetController`, or
  approval Vue views (`TimesheetSubmit`, `TimesheetApprovalInbox`,
  `TimesheetApprovalDetail`, `TimesheetEditRequestDialog`)
- **AND** Pipelinq SHALL author no approval, locking, or submission
  notifications.

#### Scenario: shillinq is declared as the lifecycle owner

- **GIVEN** hours captured against Pipelinq objects via the time-tracker leaf
- **WHEN** an approval-before-billing step is required
- **THEN** that step SHALL be performed in shillinq, which owns approval and
  invoicing
- **AND** Pipelinq SHALL depend on shillinq exposing that surface rather than
  re-implementing it.

### Requirement: Captured hours are reachable by shillinq

Time entries captured via the time-tracker leaf SHALL be reachable by shillinq
so it can drive approval and billing, without Pipelinq introducing any approval
state of its own.

#### Scenario: Hours flow to shillinq via OR links / WIP sync

- **GIVEN** a time entry captured by the leaf and linked to a Pipelinq object
  through the OR integration link table
- **WHEN** shillinq needs the hour data for approval/billing
- **THEN** shillinq SHALL read it via the OR link or the existing WIP-sync
  integration
- **AND** Pipelinq SHALL NOT hold an approval/locking status on the entry.

### Requirement: Pipelinq deep-links to shillinq's approval surface

Pipelinq SHALL NOT render an in-app approval screen; where it helps the user it
MAY instead surface a link to shillinq's approval inbox.

#### Scenario: Manifest provides a deep-link, not an in-app approval view

- **GIVEN** Pipelinq's `src/manifest.json`
- **WHEN** a user wants to review/approve hours
- **THEN** the manifest MAY expose a menu entry or detail-page action that
  deep-links to shillinq's approval inbox
- **AND** no `type` page in the manifest SHALL render a pipelinq-owned approval
  workflow.

