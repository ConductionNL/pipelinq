# Design: time-approval-workflow

## Architecture

### Data Layer

Two new OpenRegister schemas are introduced by this change. Both build on top of the `timeEntry` schema added by the `time-entry-core` dependency.

#### New Schema: `timesheetPeriod`

Represents a calendar week's worth of time entries submitted by one employee for manager approval.

| Property | Type | Required | Description |
|---|---|---|---|
| userId | string | Yes | Nextcloud user UID of the submitting employee |
| weekLabel | string | Yes | ISO week identifier, e.g. "2026-W20" |
| periodStart | string | Yes | First day of the week (ISO 8601 date, Monday) |
| periodEnd | string | Yes | Last day of the week (ISO 8601 date, Sunday) |
| status | string | Yes | Workflow state: `open` \| `submitted` \| `approved` \| `rejected` \| `locked` |
| submittedAt | string | No | ISO timestamp when the period was submitted |
| approvedBy | string | No | Nextcloud user UID of the approving manager |
| approvedAt | string | No | ISO timestamp of approval or rejection |
| approvalComment | string | No | Manager comment on approve or reject action |
| totalHours | number | No | Sum of hours across all associated time entries (computed on submit) |
| entryCount | integer | No | Number of time entries included in this period |

OpenRegister built-in fields (`status`, `locked`, `auditTrail`) provide locking and change tracking automatically.

#### New Schema: `timesheetEditRequest`

Represents a request to correct a specific locked time entry, with a mandatory reason and manager decision.

| Property | Type | Required | Description |
|---|---|---|---|
| timesheetPeriod | string | Yes | UUID reference to the parent `timesheetPeriod` |
| timeEntry | string | Yes | UUID reference to the specific time entry being corrected |
| requestedBy | string | Yes | Nextcloud user UID of the employee requesting the edit |
| editReason | string | Yes | Mandatory explanation for why the locked entry needs correction |
| status | string | Yes | Request state: `pending` \| `approved` \| `rejected` |
| reviewedBy | string | No | Nextcloud user UID of the manager who reviewed the request |
| reviewedAt | string | No | ISO timestamp of the review decision |
| reviewComment | string | No | Manager comment on approve/reject decision |

### Status State Machine

```
timesheetPeriod:
  open ──[employee submits]──► submitted
  submitted ──[manager approves]──► approved ──[auto-lock]──► locked
  submitted ──[manager rejects]──► rejected
  rejected ──[employee corrects + resubmits]──► submitted

timesheetEditRequest:
  pending ──[manager approves]──► approved
  pending ──[manager rejects]──► rejected
  approved → specific timeEntry is unlocked, edited, re-locked
```

### Reuse Analysis

This change leverages the following OpenRegister platform capabilities and MUST NOT re-implement them:

| Capability | Provided by | Usage in this change |
|---|---|---|
| Object locking | `ObjectService.lockObject()` / `unlockObject()` | Lock all time entries when period transitions to `approved`; unlock specific entry on approved edit request |
| Audit trail | OpenRegister built-in `auditTrail` field | Records every status transition on `timesheetPeriod` and `timesheetEditRequest` automatically |
| Notifications | `NotificationService` | Sends Nextcloud notifications to manager (on submit) and employee (on approve/reject) |
| CRUD REST API | OpenRegister `ObjectService` | All data operations via standard API; no custom PHP controllers for CRUD |
| Schema-driven forms | `CnFormDialog` | Edit request reason form auto-generated from schema |
| Status badge | `CnStatusBadge` | Visual display of `timesheetPeriod.status` and `timesheetEditRequest.status` |
| Timeline stages | `CnTimelineStages` | Workflow progression visualization on period detail view |
| Pinia object store | `createObjectStore` + plugins | State management for `timesheetPeriod` and `timesheetEditRequest` |

No existing OpenRegister service provides a submit/approve/lock state machine — custom `TimesheetService` is required for transition validation and lock coordination.

### Frontend

#### New Views

**`src/views/timesheet/TimesheetSubmitView.vue`**

Weekly grid view for employees. Shows the current week's time entries grouped by day. Header displays total hours and week label. Bottom action bar shows "Dien in" (Submit) button when status is `open` and all entries are valid. On submit, calls `POST /api/timesheet-periods` and transitions to `submitted` status.

When status is `submitted`, `approved`, or `locked`, the view renders entries read-only with a `CnStatusBadge` showing the current state. A rejection reason banner (`NcNoteCard` variant `error`) is shown when status is `rejected`.

**`src/views/timesheet/TimesheetApprovalInboxView.vue`**

Manager-only view listing all `timesheetPeriod` objects with status `submitted`, grouped by employee. Uses `CnDataTable` with columns: employee, week, total hours, submitted at, actions (Goedkeuren / Afwijzen). Row click navigates to `TimesheetApprovalDetailView`.

**`src/views/timesheet/TimesheetApprovalDetailView.vue`**

Shows a submitted period's entries in a read-only weekly grid. Header shows employee name, week label, and `CnTimelineStages` (open → submitted → approved → locked). Action bar: "Goedkeuren" button (green) and "Afwijzen" button (red). Both open a `CnFormDialog` for the comment field. On confirmation, calls `PUT /api/timesheet-periods/{id}` with new status and comment.

#### New Components

**`src/components/timesheet/TimesheetEditRequestDialog.vue`**

Dialog opened when an employee clicks Edit on a locked time entry. Contains a `<textarea>` for the mandatory `editReason` field (minimum 10 characters). Submits via `POST /api/timesheet-edit-requests`. Shows success/error feedback via `NcNoteCard`. All strings via `t()`.

**`src/components/timesheet/TimesheetApprovalBanner.vue`**

Reusable banner component shown on the timesheet submit view when a period has been rejected. Shows manager's `approvalComment` in a `NcNoteCard` (error variant) with the message "Uw urenregistratie is afgewezen: {comment}".

#### Modified Files

**`src/App.vue` / `src/router/index.js`**

Add routes and navigation entries for:
- `/timesheet` → `TimesheetSubmitView` (Mijn Uren)
- `/timesheet/approval` → `TimesheetApprovalInboxView` (Goed te keuren — managers only)
- `/timesheet/approval/:id` → `TimesheetApprovalDetailView`

**`src/components/MainMenu.vue`**

Add navigation item "Mijn Uren" (icon: `mdi-clock-check-outline`) and conditional "Goed te keuren" item visible only to users with manager role.

### Backend

#### New Controller: `TimesheetController`

`src/Controller/TimesheetController.php`

Thin controller handling state transitions. Delegates all logic to `TimesheetService`.

| Route | Method | Action |
|---|---|---|
| `GET /api/timesheet-periods` | `index()` | List periods (filtered by userId or all for managers) |
| `POST /api/timesheet-periods` | `create()` | Create a new period and submit it |
| `GET /api/timesheet-periods/{id}` | `show()` | Fetch a single period |
| `PUT /api/timesheet-periods/{id}` | `update()` | Transition status (approve/reject/lock) |
| `GET /api/timesheet-edit-requests` | `indexEditRequests()` | List edit requests for a period |
| `POST /api/timesheet-edit-requests` | `createEditRequest()` | Submit a post-lock correction request |
| `PUT /api/timesheet-edit-requests/{id}` | `updateEditRequest()` | Approve/reject correction request |

All routes registered in `appinfo/routes.php`. Specific routes BEFORE wildcard `{slug}` routes per ADR-003.

#### New Service: `TimesheetService`

`src/Service/TimesheetService.php`

Stateless service enforcing state machine rules and coordinating lock operations.

Key methods:
- `submitPeriod(string $periodId, string $userId): array` — validates all entries belong to the user and week, sets status to `submitted`, sends notification to manager via `NotificationService`
- `approvePeriod(string $periodId, string $managerId, string $comment): array` — sets status to `approved`, calls `ObjectService.lockObject()` for each associated time entry, sends notification to employee
- `rejectPeriod(string $periodId, string $managerId, string $comment): array` — sets status to `rejected` with mandatory comment, sends notification to employee
- `approveEditRequest(string $requestId, string $managerId, string $comment): array` — unlocks the specific time entry, marks edit request `approved`, triggers audit trail entry, re-locks after edit is saved
- `rejectEditRequest(string $requestId, string $managerId, string $comment): array` — marks edit request `rejected`

No business logic in the controller. No mapper needed — all data via `ObjectService`.

### Integration Points

| System | Integration |
|---|---|
| OpenRegister `timesheetPeriod` schema | CRUD via `ObjectService`; locking via `lockObject()` |
| OpenRegister `timesheetEditRequest` schema | CRUD via `ObjectService` |
| OpenRegister `timeEntry` (from `time-entry-core`) | Read entries for period; lock/unlock individual entries |
| Nextcloud `NotificationService` | Notify manager on submit; notify employee on approve/reject |
| OpenRegister `AuditTrailService` | Automatic change tracking on all status transitions |

## i18n

| Key | English | Dutch |
|---|---|---|
| `Mijn Uren` | `My Hours` | `Mijn Uren` |
| `Goed te keuren` | `Pending Approval` | `Goed te keuren` |
| `Week {week} indienen` | `Submit week {week}` | `Week {week} indienen` |
| `Dien in` | `Submit` | `Dien in` |
| `Goedkeuren` | `Approve` | `Goedkeuren` |
| `Afwijzen` | `Reject` | `Afwijzen` |
| `Afwijzingsreden` | `Rejection reason` | `Afwijzingsreden` |
| `Opmerking (verplicht)` | `Comment (required)` | `Opmerking (verplicht)` |
| `Uw urenregistratie is afgewezen: {comment}` | `Your timesheet was rejected: {comment}` | `Uw urenregistratie is afgewezen: {comment}` |
| `Uw urenregistratie is goedgekeurd` | `Your timesheet has been approved` | `Uw urenregistratie is goedgekeurd` |
| `Week ingediend ter goedkeuring` | `Week submitted for approval` | `Week ingediend ter goedkeuring` |
| `Periode vergrendeld` | `Period locked` | `Periode vergrendeld` |
| `Reden voor wijziging (verplicht, min. 10 tekens)` | `Reason for change (required, min. 10 characters)` | `Reden voor wijziging (verplicht, min. 10 tekens)` |
| `Wijzigingsverzoek ingediend` | `Edit request submitted` | `Wijzigingsverzoek ingediend` |
| `Geen ingediende urenstaten gevonden` | `No submitted timesheets found` | `Geen ingediende urenstaten gevonden` |

All keys follow ADR-007 sentence case with English as the key string.

## Files Changed

### New Files

| File | Purpose |
|---|---|
| `src/views/timesheet/TimesheetSubmitView.vue` | Weekly time grid + submit action for employees |
| `src/views/timesheet/TimesheetApprovalInboxView.vue` | Manager inbox — list of submitted periods |
| `src/views/timesheet/TimesheetApprovalDetailView.vue` | Approve/reject a specific period |
| `src/components/timesheet/TimesheetEditRequestDialog.vue` | Post-lock correction reason dialog |
| `src/components/timesheet/TimesheetApprovalBanner.vue` | Rejection reason banner |
| `src/Controller/TimesheetController.php` | Status transition endpoints |
| `src/Service/TimesheetService.php` | State machine + lock coordination |
| `specs/time-approval-workflow/spec.md` | Formal requirements and BDD scenarios |

### Modified Files

| File | Change |
|---|---|
| `src/App.vue` | Add timesheet routes and conditional nav entries |
| `src/router/index.js` | Register `/timesheet`, `/timesheet/approval`, `/timesheet/approval/:id` |
| `src/components/MainMenu.vue` | Add "Mijn Uren" and conditional "Goed te keuren" nav items |
| `appinfo/routes.php` | Register TimesheetController routes |
| `lib/Settings/pipelinq_register.json` | Add `timesheetPeriod` and `timesheetEditRequest` schemas + seed data |
| `l10n/en.json` | Add 15 new translation keys |
| `l10n/nl.json` | Add Dutch translations for the same 15 keys |

## Seed Data

Seed data for the two new schemas. All objects use the `@self` envelope with `register: "pipelinq"` and unique slugs. Dutch names and realistic values throughout.

### Schema: `timesheetPeriod`

**1. Ingediend — wacht op goedkeuring (Jan de Boer, week 20)**

```json
{
  "@self": { "register": "pipelinq", "schema": "timesheetPeriod", "slug": "period-jdeboer-2026-w20" },
  "userId": "jan.deboer",
  "weekLabel": "2026-W20",
  "periodStart": "2026-05-11",
  "periodEnd": "2026-05-17",
  "status": "submitted",
  "submittedAt": "2026-05-17T17:32:00Z",
  "approvedBy": null,
  "approvedAt": null,
  "approvalComment": null,
  "totalHours": 38.5,
  "entryCount": 9
}
```

**2. Goedgekeurd en vergrendeld (Maria van den Berg, week 19)**

```json
{
  "@self": { "register": "pipelinq", "schema": "timesheetPeriod", "slug": "period-mvdberg-2026-w19" },
  "userId": "maria.vandenberg",
  "weekLabel": "2026-W19",
  "periodStart": "2026-05-04",
  "periodEnd": "2026-05-10",
  "status": "locked",
  "submittedAt": "2026-05-10T16:45:00Z",
  "approvedBy": "pieter.janssen",
  "approvedAt": "2026-05-11T09:10:00Z",
  "approvalComment": "Akkoord. Alle projecten correct geregistreerd.",
  "totalHours": 40.0,
  "entryCount": 10
}
```

**3. Afgewezen — teruggestuurd (Nadia El-Amrani, week 20)**

```json
{
  "@self": { "register": "pipelinq", "schema": "timesheetPeriod", "slug": "period-nelamrani-2026-w20" },
  "userId": "nadia.elamrani",
  "weekLabel": "2026-W20",
  "periodStart": "2026-05-11",
  "periodEnd": "2026-05-17",
  "status": "rejected",
  "submittedAt": "2026-05-17T18:01:00Z",
  "approvedBy": "pieter.janssen",
  "approvedAt": "2026-05-18T08:20:00Z",
  "approvalComment": "Dinsdag mist 2 uur op project Gemeente Rotterdam. Pas aan en stuur opnieuw in.",
  "totalHours": 34.0,
  "entryCount": 8
}
```

**4. Open — nog niet ingediend (Thijs Verkerk, week 21)**

```json
{
  "@self": { "register": "pipelinq", "schema": "timesheetPeriod", "slug": "period-tverkerk-2026-w21" },
  "userId": "thijs.verkerk",
  "weekLabel": "2026-W21",
  "periodStart": "2026-05-18",
  "periodEnd": "2026-05-24",
  "status": "open",
  "submittedAt": null,
  "approvedBy": null,
  "approvedAt": null,
  "approvalComment": null,
  "totalHours": 16.0,
  "entryCount": 4
}
```

### Schema: `timesheetEditRequest`

**1. In behandeling — wacht op goedkeuring (correctie door Jan de Boer)**

```json
{
  "@self": { "register": "pipelinq", "schema": "timesheetEditRequest", "slug": "editreq-jdeboer-20260512" },
  "timesheetPeriod": "period-mvdberg-2026-w19",
  "timeEntry": "timeentry-placeholder-001",
  "requestedBy": "jan.deboer",
  "editReason": "Verkeerd project opgegeven: uren zijn voor Gemeente Utrecht, niet Gemeente Amsterdam. Correct project is PL-2024-UTR.",
  "status": "pending",
  "reviewedBy": null,
  "reviewedAt": null,
  "reviewComment": null
}
```

**2. Goedgekeurd wijzigingsverzoek (correctie door Maria van den Berg)**

```json
{
  "@self": { "register": "pipelinq", "schema": "timesheetEditRequest", "slug": "editreq-mvdberg-20260509" },
  "timesheetPeriod": "period-mvdberg-2026-w19",
  "timeEntry": "timeentry-placeholder-002",
  "requestedBy": "maria.vandenberg",
  "editReason": "Typo in omschrijving: 1,5 uur ipv 2,5 uur ingevoerd. Tijdsduur klopt wel, omschrijving gecorrigeerd.",
  "status": "approved",
  "reviewedBy": "pieter.janssen",
  "reviewedAt": "2026-05-14T11:30:00Z",
  "reviewComment": "Goedgekeurd. Audittrail bijgewerkt."
}
```
