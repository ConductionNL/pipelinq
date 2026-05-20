---
status: draft
---

# Spec: Time Approval Workflow (submit/approve/lock)

## Purpose

Define the requirements for the timesheet submit/approve/lock lifecycle in Pipelinq. This spec covers weekly timesheet submission by employees, manager approval or rejection, automatic period locking on approval, and controlled post-lock corrections with a mandatory reason. Implementation centres on two new OpenRegister schemas (`timesheetPeriod`, `timesheetEditRequest`) and a `TimesheetService` state machine.

**Main ADR refs**: [adr-000-data-model.md](../../../../architecture/adr-000-data-model.md), [adr-001-international-first-dutch-mapping.md](../../../../architecture/adr-001-international-first-dutch-mapping.md)
**Feature tier**: P0-must
**Demand evidence**: 22/26 competitors
**Depends on**: time-entry-core (`timeEntry` schema)

---

## REQ-TAW-001: Weekly timesheet submission

An employee MUST be able to submit all time entries for a calendar week as a single `timesheetPeriod` object. The period status MUST transition from `open` to `submitted` and the manager MUST receive a Nextcloud notification.

### Scenario: Employee submits a complete week

- GIVEN Jan de Boer has 9 time entries for week 2026-W20 (periodStart 2026-05-11, periodEnd 2026-05-17)
- AND the `timesheetPeriod` for that week has status `open`
- WHEN Jan clicks "Dien in" on the `TimesheetSubmitView`
- THEN the system MUST create or update the `timesheetPeriod` with status `submitted` and set `submittedAt` to the current UTC timestamp
- AND `totalHours` MUST be set to the sum of hours across all associated time entries
- AND the assigned manager (determined by user configuration) MUST receive a Nextcloud notification: "Jan de Boer heeft week 2026-W20 ingediend ter goedkeuring"

### Scenario: Employee cannot edit entries while period is submitted

- GIVEN a `timesheetPeriod` with status `submitted`
- WHEN the employee opens the weekly grid view for that period
- THEN all time entry rows MUST be rendered read-only (no edit controls visible)
- AND a `CnStatusBadge` with value `submitted` MUST be displayed in the view header

### Scenario: Week with no entries cannot be submitted

- GIVEN a calendar week with zero time entries for the current user
- WHEN the employee attempts to submit that week
- THEN the "Dien in" button MUST be disabled
- AND a validation message "Geen uren gevonden voor deze week" MUST be shown

### Scenario: Already submitted period cannot be submitted again

- GIVEN a `timesheetPeriod` with status `submitted` or `approved` or `locked`
- WHEN the API receives a submit request for the same period
- THEN the system MUST return HTTP 409 with message "Periode is al ingediend"
- AND no duplicate `timesheetPeriod` object MUST be created

---

## REQ-TAW-002: Manager approval of submitted timesheets

A manager MUST be able to approve a submitted `timesheetPeriod`. On approval the period status MUST transition to `approved` and all associated time entries MUST be locked.

### Scenario: Manager approves a submitted period

- GIVEN a `timesheetPeriod` for Jan de Boer with status `submitted`
- WHEN manager Pieter Janssen clicks "Goedkeuren" and confirms (optionally adding a comment)
- THEN the system MUST set status to `approved`, record `approvedBy` as Pieter's userId, and set `approvedAt` to the current UTC timestamp
- AND `TimesheetService.approvePeriod()` MUST call `ObjectService.lockObject()` for each time entry in the period
- AND Jan de Boer MUST receive a Nextcloud notification: "Uw urenregistratie voor week 2026-W20 is goedgekeurd"

### Scenario: Period is automatically locked after approval

- GIVEN a `timesheetPeriod` that has just been approved
- THEN the period status MUST immediately transition from `approved` to `locked`
- AND the `locked` flag on all associated `timeEntry` objects MUST be `true`
- AND the weekly grid view MUST replace edit controls with a lock icon (`mdi-lock`) for each entry

### Scenario: Manager cannot approve an already locked period

- GIVEN a `timesheetPeriod` with status `locked`
- WHEN the API receives an approve request for that period
- THEN the system MUST return HTTP 409 with message "Periode is al vergrendeld"

### Scenario: Approval without comment is permitted

- GIVEN a submitted `timesheetPeriod`
- WHEN the manager approves without entering a comment
- THEN the approval MUST succeed and `approvalComment` MUST be stored as `null`

---

## REQ-TAW-003: Manager rejection of submitted timesheets

A manager MUST be able to reject a submitted `timesheetPeriod` with a mandatory comment. Rejection MUST return the period to `open` status so the employee can correct entries and re-submit.

### Scenario: Manager rejects a submitted period with reason

- GIVEN a `timesheetPeriod` with status `submitted`
- WHEN manager Pieter Janssen clicks "Afwijzen" and enters "Dinsdag mist 2 uur op project Gemeente Rotterdam"
- THEN the system MUST set status to `rejected`, record `approvedBy` as Pieter's userId, `approvedAt` as now, and `approvalComment` as the entered text
- AND the employee MUST receive a Nextcloud notification: "Uw urenregistratie voor week 2026-W20 is afgewezen"

### Scenario: Rejection without comment is blocked

- GIVEN a submitted `timesheetPeriod`
- WHEN the manager submits the rejection form without entering a comment
- THEN the "Afwijzen" form MUST show validation error "Opmerking is verplicht bij afwijzing"
- AND the API call MUST NOT be made

### Scenario: Rejected period returns to open for correction

- GIVEN a `timesheetPeriod` with status `rejected`
- WHEN the employee opens the weekly grid view for that period
- THEN all time entry rows MUST be editable again (edit controls visible)
- AND a `TimesheetApprovalBanner` MUST show the rejection reason from `approvalComment`
- AND the "Dien in" button MUST be available again after the employee makes changes

### Scenario: Employee can re-submit after rejection

- GIVEN a `timesheetPeriod` with status `rejected`
- AND the employee has updated the missing time entries
- WHEN the employee clicks "Dien in" again
- THEN the period status MUST transition to `submitted`
- AND `submittedAt` MUST be updated to the new submission timestamp
- AND the manager MUST receive a new notification

---

## REQ-TAW-004: Period locking prevents direct edits

Once a `timesheetPeriod` reaches `locked` status, all associated time entries MUST be protected against direct edits. An employee attempting to edit a locked entry MUST be redirected to the edit request flow.

### Scenario: Edit button on locked entry opens edit request dialog

- GIVEN a `timesheetPeriod` with status `locked`
- AND a specific `timeEntry` that is part of that locked period
- WHEN the employee clicks the Edit button on that time entry
- THEN the `TimesheetEditRequestDialog` MUST open (NOT the normal edit form)
- AND the dialog MUST show the label "Reden voor wijziging (verplicht, min. 10 tekens)"

### Scenario: Locked entry cannot be saved via the standard API

- GIVEN a `timeEntry` whose `locked` field is `true`
- WHEN any user calls `PUT /api/time-entries/{id}` on that entry without an approved edit request
- THEN the API MUST return HTTP 423 Locked with message "Dit tijditem is vergrendeld"
- AND the entry MUST remain unchanged

### Scenario: Lock indicator shown in weekly grid

- GIVEN a period with status `locked`
- WHEN the employee opens the weekly grid for that week
- THEN each time entry row MUST show a `mdi-lock` icon instead of edit/delete buttons
- AND no edit controls MUST be visible anywhere in the locked week view

---

## REQ-TAW-005: Post-lock edit request with mandatory reason

An employee MUST be able to submit a `timesheetEditRequest` for a specific locked time entry with a mandatory reason. A manager MUST be able to approve or reject the request.

### Scenario: Employee submits an edit request for a locked entry

- GIVEN a locked `timeEntry` belonging to Jan de Boer
- WHEN Jan opens the `TimesheetEditRequestDialog` and enters "Verkeerd project opgegeven: uren zijn voor Gemeente Utrecht, niet Gemeente Amsterdam"
- THEN the system MUST create a `timesheetEditRequest` with status `pending`, `requestedBy` as Jan's userId, and the entered text as `editReason`
- AND Jan MUST see a confirmation: "Wijzigingsverzoek ingediend"
- AND the manager MUST receive a Nextcloud notification about the pending request

### Scenario: Edit reason shorter than 10 characters is rejected

- GIVEN the `TimesheetEditRequestDialog` is open
- WHEN the employee enters a reason with fewer than 10 characters and clicks Submit
- THEN the form MUST show validation error "Reden voor wijziging (verplicht, min. 10 tekens)"
- AND the `timesheetEditRequest` MUST NOT be created

### Scenario: Manager approves an edit request

- GIVEN a `timesheetEditRequest` with status `pending`
- WHEN manager Pieter Janssen clicks "Goedkeuren" on the request and optionally adds "Goedgekeurd. Audittrail bijgewerkt."
- THEN the system MUST set request status to `approved`, call `ObjectService.unlockObject()` on the associated `timeEntry`
- AND after the employee saves the corrected entry, `TimesheetService` MUST call `ObjectService.lockObject()` again
- AND the `editReason` MUST be appended to the time entry's audit trail via `AuditTrailService`
- AND the employee MUST receive a notification: "Uw wijzigingsverzoek is goedgekeurd"

### Scenario: Manager rejects an edit request

- GIVEN a `timesheetEditRequest` with status `pending`
- WHEN the manager clicks "Afwijzen" and enters a reason
- THEN the system MUST set request status to `rejected` and record `reviewedBy`, `reviewedAt`, and `reviewComment`
- AND the `timeEntry` MUST remain locked
- AND the employee MUST receive a notification: "Uw wijzigingsverzoek is afgewezen: {reviewComment}"

### Scenario: Only one pending edit request per entry

- GIVEN a `timesheetEditRequest` with status `pending` already exists for a time entry
- WHEN the employee tries to submit another edit request for the same time entry
- THEN the API MUST return HTTP 409 with message "Er is al een openstaand wijzigingsverzoek voor dit tijditem"

---

## REQ-TAW-006: Manager approval inbox

A manager MUST have a dedicated view listing all submitted timesheets across all employees, grouped by week, allowing quick navigation to approve or reject each one.

### Scenario: Manager sees all submitted periods

- GIVEN three `timesheetPeriod` objects with status `submitted` from different employees
- WHEN the manager opens the `TimesheetApprovalInboxView`
- THEN all three periods MUST be listed with columns: medewerker, week, totaal uren, ingediend op
- AND periods MUST be sorted by `submittedAt` ascending (oldest first)

### Scenario: Empty inbox shown when no submissions pending

- GIVEN no `timesheetPeriod` objects have status `submitted`
- WHEN the manager opens the `TimesheetApprovalInboxView`
- THEN a `CnEmptyState` MUST be shown with message "Geen ingediende urenstaten gevonden"

### Scenario: Non-manager cannot access approval inbox

- GIVEN a user who does not have the manager role in Nextcloud groups
- WHEN the user navigates to `/timesheet/approval`
- THEN the view MUST redirect to `/timesheet` with no approval controls visible
- AND the API `GET /api/timesheet-periods?status=submitted&all=true` MUST return HTTP 403

---

## REQ-TAW-007: Audit trail on all status transitions

Every status transition on `timesheetPeriod` and `timesheetEditRequest` MUST be recorded in the OpenRegister audit trail with the acting user, timestamp, old status, and new status.

### Scenario: Audit trail records submission

- GIVEN a `timesheetPeriod` transitions from `open` to `submitted`
- THEN the audit trail MUST contain an entry with `action: "update"`, `changedBy: userId`, `before: { status: "open" }`, `after: { status: "submitted" }`

### Scenario: Audit trail records approval

- GIVEN a `timesheetPeriod` transitions from `submitted` to `locked` via approval
- THEN the audit trail MUST contain two entries: one for `approved` and one for `locked`, each with the manager's userId and UTC timestamp

### Scenario: Edit reason in time entry audit trail

- GIVEN an approved `timesheetEditRequest` for a time entry
- WHEN the employee saves the corrected entry
- THEN the time entry's audit trail MUST include the `editReason` text as a note field
- AND the note MUST reference the `timesheetEditRequest` UUID
