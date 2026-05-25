# Proposal: time-approval-workflow

## Problem

Time entries in Pipelinq can be created and edited freely with no approval gate, no period-based locking, and no audit trail for post-lock corrections. Market intelligence covering 22/26 competitors shows that a submit/approve/lock cycle is a universal, expected feature in professional time-tracking software:

1. **No weekly submission** — Employees have no way to formally submit a week's time for manager review. Without submission, managers cannot distinguish "in progress" entries from "complete, ready to bill" entries, leading to premature or delayed invoicing.
2. **No manager approval** — There is no approval step that lets a manager confirm hours before a billing run. Competitors (Harvest, Tempo, Clockify, Everhour, Kantata) all gate invoicing on approved timesheets.
3. **No period locking** — After approval, time entries remain editable. Uncontrolled edits to approved periods corrupt billing data, violate audit requirements (DCAA/SOX), and undermine trust in invoiced amounts.
4. **No controlled post-lock correction** — When a locked entry genuinely needs correction (error, missing project), there is no structured way to request and document the change. Employees must ask an admin to unlock records directly, with no paper trail.

Without a submit/approve/lock cycle, Pipelinq cannot serve professional services firms, government contractors, or any organisation that requires auditable time records before issuing invoices.

## Solution

Implement a three-stage time approval workflow:

1. **Submit** — A user submits all time entries for a calendar week as a `timesheetPeriod`. The period moves from status `open` to `submitted`. All underlying time entries are marked read-only for the submitter while pending review.

2. **Approve / Reject** — A manager reviews submitted timesheets and either approves (→ `approved`) or rejects with a mandatory comment (→ `rejected`). Rejection returns entries to `open` so the employee can correct and re-submit. Approval triggers automatic period locking.

3. **Lock** — On approval, the `timesheetPeriod` transitions to `locked` and all associated time entries are locked via OpenRegister's built-in `ObjectService.lockObject()`. Locked entries cannot be edited through normal flows.

4. **Post-lock edit request** — An employee needing to correct a locked entry submits a `timesheetEditRequest` with a mandatory reason. A manager reviews and approves or rejects the request. On approval the specific time entry is unlocked, edited, and re-locked; the edit reason is appended to the audit trail.

## Scope

- New OpenRegister schema: `timesheetPeriod` (weekly submission with status lifecycle)
- New OpenRegister schema: `timesheetEditRequest` (post-lock correction request)
- Frontend: Timesheet submission view (`TimesheetSubmit.vue`) — weekly grid + submit action
- Frontend: Manager approval inbox (`TimesheetApprovalInbox.vue`) — list of submitted periods
- Frontend: Approval detail view (`TimesheetApprovalDetail.vue`) — approve/reject with comment
- Frontend: Edit request dialog (`TimesheetEditRequestDialog.vue`) — reason + manager review
- Backend: Status transition controller (`TimesheetController`) for submit/approve/reject/lock
- Backend: `TimesheetService` enforcing state machine rules
- Notifications: Nextcloud notification to manager on submission; to employee on approve/reject
- i18n keys for all workflow states and actions (Dutch + English)
- Seed data: 3 `timesheetPeriod` objects and 2 `timesheetEditRequest` objects with Dutch values

## Out of Scope

- Time entry creation UI — covered by `time-entry-core`
- Multi-stage approval (more than one approver per period) — Enterprise tier
- Delegation of approval to a deputy manager
- DCAA / SOX compliance certification — separate change
- Client-specific approval rules
- Automatic submission reminders / email notifications (Nextcloud notifications only in V1)
- Bulk approval of multiple periods in one click
- Approval on mobile app

## Success Criteria

- An employee can submit their week's time entries in a single action; the period status changes to `submitted` and the manager receives a Nextcloud notification
- A manager can view all submitted timesheets grouped by employee and week; approve or reject each with a comment
- Approving a period locks all associated time entries via `ObjectService.lockObject()`; the employee's edit controls are replaced with a lock indicator
- Rejecting a period with a mandatory comment returns entries to editable `open` state; the employee sees the rejection reason
- A locked entry's edit button opens a `TimesheetEditRequestDialog`; the request records the reason and creates a `timesheetEditRequest` object
- Approved edit requests unlock the specific entry for correction, then re-lock it; the edit reason appears in the audit trail tab
- `npm run build` produces zero errors after all changes
- All user-visible strings use `t()` and are present in both `l10n/en.json` and `l10n/nl.json`
