# Tasks: time-approval-workflow

## 0. Deduplication Check

- [ ] 0.1 Verify that `time-entry-core` is merged and the `timeEntry` schema is present in `lib/Settings/pipelinq_register.json`. If absent, block this change until `time-entry-core` is complete.
  - `grep -n "timesheetPeriod\|timesheetEditRequest" lib/Settings/pipelinq_register.json` → must return no matches (fresh schema)
  - `grep -n '"timeEntry"' lib/Settings/pipelinq_register.json` → must return at least one match (dependency present)
- [ ] 0.2 Search for any existing timesheet or approval composable/service:
  - `grep -r "timesheetPeriod\|approveTimesheet\|submitTimesheet\|lockTimesheet" src/ lib/`
  - If found, extend rather than duplicate.
- [ ] 0.3 Confirm OpenRegister's built-in `ObjectService.lockObject()` and `unlockObject()` are available in the installed OpenRegister version. Do NOT implement custom locking logic.
- [ ] 0.4 Check that `NotificationService` is already used elsewhere in the app (e.g. in `time-entry-core` or another change). If so, reuse the same injection pattern.

---

## 1. OpenRegister Schemas

- [ ] 1.1 Add `timesheetPeriod` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-001`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema slug: `timesheetPeriod`
    - Required properties: `userId` (string), `weekLabel` (string), `periodStart` (string), `periodEnd` (string), `status` (string, enum: open/submitted/approved/rejected/locked)
    - Optional properties: `submittedAt`, `approvedBy`, `approvedAt`, `approvalComment` (all string), `totalHours` (number), `entryCount` (integer)
    - Schema registered in the pipelinq register
    - Re-importing with `force: false` MUST NOT create duplicates (matched by slug)

- [ ] 1.2 Add `timesheetEditRequest` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-005`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema slug: `timesheetEditRequest`
    - Required properties: `timesheetPeriod` (string, UUID ref), `timeEntry` (string, UUID ref), `requestedBy` (string), `editReason` (string, minLength 10), `status` (string, enum: pending/approved/rejected)
    - Optional properties: `reviewedBy`, `reviewedAt`, `reviewComment` (all string/null)
    - Registered in the pipelinq register alongside `timesheetPeriod`

---

## 2. Seed Data

- [ ] 2.1 Add 4 `timesheetPeriod` seed objects to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: Company ADR-001 (data-layer) — seed data requirement
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Objects: period-jdeboer-2026-w20 (submitted), period-mvdberg-2026-w19 (locked), period-nelamrani-2026-w20 (rejected), period-tverkerk-2026-w21 (open)
    - Each uses `@self` envelope with `register: "pipelinq"`, `schema: "timesheetPeriod"`, unique slug
    - All field values match the seed definitions in `design.md`
    - Re-importing MUST skip existing objects matched by slug

- [ ] 2.2 Add 2 `timesheetEditRequest` seed objects to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: Company ADR-001 (data-layer) — seed data requirement
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Objects: editreq-jdeboer-20260512 (pending), editreq-mvdberg-20260509 (approved)
    - Dutch-language `editReason` and `reviewComment` values as defined in `design.md`
    - Re-importing MUST skip existing objects matched by slug

---

## 3. Backend: `TimesheetController`

- [ ] 3.1 Create `src/Controller/TimesheetController.php`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-001`, `REQ-TAW-002`, `REQ-TAW-003`
  - **files**: `src/Controller/TimesheetController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - Routes registered (specific before wildcard `{slug}`):
      - `GET /api/timesheet-periods` → `index()`
      - `POST /api/timesheet-periods` → `create()`
      - `GET /api/timesheet-periods/{id}` → `show()`
      - `PUT /api/timesheet-periods/{id}` → `update()`
      - `GET /api/timesheet-edit-requests` → `indexEditRequests()`
      - `POST /api/timesheet-edit-requests` → `createEditRequest()`
      - `PUT /api/timesheet-edit-requests/{id}` → `updateEditRequest()`
    - Controller methods are thin (<10 lines): validate input, delegate to `TimesheetService`, return JSON response
    - File-level `@spec openspec/changes/time-approval-workflow/tasks.md#task-3.1` PHPDoc tag present
    - DI via constructor injection with `private readonly`

- [ ] 3.2 Register `TimesheetController` routes in `appinfo/routes.php`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-001`
  - **files**: `appinfo/routes.php`
  - **acceptance_criteria**:
    - All 7 routes listed in task 3.1 are registered
    - Specific routes appear BEFORE any wildcard `{slug}` catch-all routes
    - Route names follow `timesheet-periods.index`, `timesheet-periods.create`, etc. convention

---

## 4. Backend: `TimesheetService`

- [ ] 4.1 Create `src/Service/TimesheetService.php` with `submitPeriod()` method
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-001`
  - **files**: `src/Service/TimesheetService.php`
  - **acceptance_criteria**:
    - `submitPeriod(string $periodId, string $userId): array`
    - Validates current status is `open` or `rejected`; throws `\InvalidArgumentException` with "Periode is al ingediend" (HTTP 409) otherwise
    - Queries associated `timeEntry` objects, sums hours, sets `totalHours` and `entryCount`
    - Updates `timesheetPeriod` status to `submitted`, sets `submittedAt`
    - Sends manager notification via injected `NotificationService`
    - Stateless: no instance state between requests
    - `@spec` PHPDoc tag on class and method

- [ ] 4.2 Add `approvePeriod()` method to `TimesheetService`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-002`
  - **files**: `src/Service/TimesheetService.php`
  - **acceptance_criteria**:
    - `approvePeriod(string $periodId, string $managerId, string $comment): array`
    - Validates current status is `submitted`; returns 409 otherwise
    - Sets status to `approved`, records `approvedBy`, `approvedAt`, `approvalComment`
    - Calls `ObjectService.lockObject()` for every associated `timeEntry`
    - Immediately transitions period to `locked` (no separate step needed from UI)
    - Sends employee notification: "Uw urenregistratie voor week {weekLabel} is goedgekeurd"

- [ ] 4.3 Add `rejectPeriod()` method to `TimesheetService`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-003`
  - **files**: `src/Service/TimesheetService.php`
  - **acceptance_criteria**:
    - `rejectPeriod(string $periodId, string $managerId, string $comment): array`
    - Validates `$comment` is non-empty; throws `\InvalidArgumentException` with "Opmerking is verplicht bij afwijzing" otherwise
    - Validates current status is `submitted`; returns 409 otherwise
    - Sets status to `rejected`, records `approvedBy`, `approvedAt`, `approvalComment`
    - Sends employee notification: "Uw urenregistratie voor week {weekLabel} is afgewezen"

- [ ] 4.4 Add `approveEditRequest()` method to `TimesheetService`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-005`
  - **files**: `src/Service/TimesheetService.php`
  - **acceptance_criteria**:
    - `approveEditRequest(string $requestId, string $managerId, string $comment): array`
    - Sets `timesheetEditRequest` status to `approved`, records reviewer fields
    - Calls `ObjectService.unlockObject()` on the associated `timeEntry`
    - Appends `editReason` and edit request UUID to `timeEntry` audit trail note
    - Sends employee notification: "Uw wijzigingsverzoek is goedgekeurd"

- [ ] 4.5 Add `rejectEditRequest()` method to `TimesheetService`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-005`
  - **files**: `src/Service/TimesheetService.php`
  - **acceptance_criteria**:
    - `rejectEditRequest(string $requestId, string $managerId, string $comment): array`
    - Sets `timesheetEditRequest` status to `rejected`, records reviewer fields
    - Time entry MUST remain locked (no `unlockObject()` call)
    - Sends employee notification: "Uw wijzigingsverzoek is afgewezen: {reviewComment}"

---

## 5. Frontend: Pinia Stores

- [ ] 5.1 Create `timesheetPeriod` object store in `src/store/modules/`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-001`
  - **files**: `src/store/modules/timesheetPeriod.js`
  - **acceptance_criteria**:
    - Uses `createObjectStore('timesheetPeriod')` with `auditTrailsPlugin` and `relationsPlugin`
    - Registered via `objectStore.registerObjectType('timesheetPeriod', 'timesheetPeriod', 'pipelinq')` in `store/store.js`
    - Exposes standard CRUD actions: `fetchAll`, `fetchOne`, `saveObject`, `deleteObject`
    - Wraps `PUT /api/timesheet-periods/{id}` for status transitions

- [ ] 5.2 Create `timesheetEditRequest` object store in `src/store/modules/`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-005`
  - **files**: `src/store/modules/timesheetEditRequest.js`
  - **acceptance_criteria**:
    - Uses `createObjectStore('timesheetEditRequest')` with `auditTrailsPlugin`
    - Registered in `store/store.js` alongside `timesheetPeriod`
    - Exposes `fetchAll`, `fetchOne`, `saveObject`

---

## 6. Frontend: Views

- [ ] 6.1 Create `src/views/timesheet/TimesheetSubmitView.vue`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-001`, `REQ-TAW-004`
  - **files**: `src/views/timesheet/TimesheetSubmitView.vue`
  - **acceptance_criteria**:
    - Renders a weekly grid of time entries for the current user and selected week
    - Header shows week label (`weekLabel`) and total hours
    - `CnStatusBadge` with current `timesheetPeriod.status` visible in header
    - "Dien in" button visible and enabled only when status is `open` or `rejected` AND entryCount > 0
    - Clicking "Dien in" calls `PUT /api/timesheet-periods/{id}` (or `POST` to create + submit) via store action
    - When status is `submitted`, `approved`, or `locked`: entries rendered read-only, no edit controls visible
    - When status is `rejected`: `TimesheetApprovalBanner` rendered below header
    - When status is `locked`: each entry row shows `mdi-lock` icon; clicking Edit opens `TimesheetEditRequestDialog`
    - ALL strings via `t()`; no hardcoded Dutch or English text

- [ ] 6.2 Create `src/views/timesheet/TimesheetApprovalInboxView.vue`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-006`
  - **files**: `src/views/timesheet/TimesheetApprovalInboxView.vue`
  - **acceptance_criteria**:
    - Uses `CnDataTable` with columns: medewerker, week, totaal uren, ingediend op, acties
    - Fetches `timesheetPeriod` objects filtered by `status=submitted&all=true`
    - Sorted by `submittedAt` ascending
    - Row click navigates to `TimesheetApprovalDetailView` (`/timesheet/approval/:id`)
    - Empty state: `CnEmptyState` with message "Geen ingediende urenstaten gevonden" when list is empty
    - Access guarded: non-manager users redirected to `/timesheet`

- [ ] 6.3 Create `src/views/timesheet/TimesheetApprovalDetailView.vue`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-002`, `REQ-TAW-003`
  - **files**: `src/views/timesheet/TimesheetApprovalDetailView.vue`
  - **acceptance_criteria**:
    - Renders period's time entries in read-only weekly grid
    - `CnTimelineStages` showing stages: open → ingediend → goedgekeurd → vergrendeld
    - Action bar: "Goedkeuren" (green) and "Afwijzen" (red) buttons — only visible when status is `submitted`
    - "Goedkeuren" opens `CnFormDialog` with optional comment field; on confirm calls `approvePeriod`
    - "Afwijzen" opens `CnFormDialog` with required comment field; validates non-empty before calling `rejectPeriod`
    - EVERY `await store.action()` wrapped in `try/catch` with user-facing error feedback via `NcNoteCard`

---

## 7. Frontend: Components

- [ ] 7.1 Create `src/components/timesheet/TimesheetEditRequestDialog.vue`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-005`
  - **files**: `src/components/timesheet/TimesheetEditRequestDialog.vue`
  - **acceptance_criteria**:
    - Props: `timeEntryId` (String, required), `timesheetPeriodId` (String, required)
    - Contains `<NcDialog>` with a `<textarea>` bound to `editReason`
    - Client-side validation: `editReason.length >= 10`; shows "Reden voor wijziging (verplicht, min. 10 tekens)" if not met
    - On submit: `POST /api/timesheet-edit-requests` via store action; shows "Wijzigingsverzoek ingediend" on success
    - Emits `close` on success or cancel
    - NO `window.confirm()` or `window.alert()` — uses `NcDialog` per ADR-004

- [ ] 7.2 Create `src/components/timesheet/TimesheetApprovalBanner.vue`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-003`
  - **files**: `src/components/timesheet/TimesheetApprovalBanner.vue`
  - **acceptance_criteria**:
    - Props: `comment` (String, required)
    - Renders `NcNoteCard` with type `error`
    - Content: `t('pipelinq', 'Uw urenregistratie is afgewezen: {comment}', { comment })`
    - Hidden when `comment` is empty or null (via `v-if`)

---

## 8. Navigation

- [ ] 8.1 Register timesheet routes in `src/router/index.js`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-001`, `REQ-TAW-006`
  - **files**: `src/router/index.js`
  - **acceptance_criteria**:
    - `/timesheet` → `TimesheetSubmitView` (name: `TimesheetSubmit`)
    - `/timesheet/approval` → `TimesheetApprovalInboxView` (name: `TimesheetApprovalInbox`)
    - `/timesheet/approval/:id` → `TimesheetApprovalDetailView` (name: `TimesheetApprovalDetail`, props via arrow function)
    - All routes use path format (NOT hash format) per ADR-004
    - No `/settings` route created

- [ ] 8.2 Add navigation items to `src/components/MainMenu.vue`
  - **spec_ref**: `specs/time-approval-workflow/spec.md#REQ-TAW-001`, `REQ-TAW-006`
  - **files**: `src/components/MainMenu.vue`
  - **acceptance_criteria**:
    - `NcAppNavigationItem` "Mijn Uren" with icon `mdi-clock-check-outline` linking to `{ name: 'TimesheetSubmit' }`
    - `NcAppNavigationItem` "Goed te keuren" with icon `mdi-clock-alert-outline` linking to `{ name: 'TimesheetApprovalInbox' }` — shown only when user is a manager (from settings store)
    - EVERY component used in `<template>` imported AND registered in `components: {}`

---

## 9. i18n

- [ ] 9.1 Add 15 new translation keys to `l10n/en.json`
  - **spec_ref**: Company ADR-007 (i18n)
  - **files**: `l10n/en.json`
  - **acceptance_criteria**:
    - All 15 keys from `design.md` i18n table are present
    - Keys are English sentence case per ADR-007
    - No hardcoded strings in any Vue component

- [ ] 9.2 Add Dutch translations for the same 15 keys to `l10n/nl.json`
  - **spec_ref**: Company ADR-007 (i18n)
  - **files**: `l10n/nl.json`
  - **acceptance_criteria**:
    - Dutch values match the `design.md` i18n table exactly
    - Both locale files have the same set of keys (no gaps)

---

## 10. Verification

- [ ] 10.1 Run `npm run build` in the pipelinq app directory — MUST produce zero errors
- [ ] 10.2 Submit flow test: log in as Jan de Boer; open Mijn Uren; verify week 2026-W21 shows status `open` with "Dien in" button enabled; click submit; confirm status changes to `submitted` and manager notification appears
- [ ] 10.3 Approval flow test: log in as manager Pieter Janssen; open "Goed te keuren"; find Jan de Boer's 2026-W21 submission; approve with comment; confirm status changes to `locked` and Jan receives a notification
- [ ] 10.4 Rejection flow test: log in as manager; reject Nadia El-Amrani's week 2026-W20 with comment "Dinsdag mist 2 uur"; confirm Nadia sees `TimesheetApprovalBanner` with rejection reason and can re-submit
- [ ] 10.5 Lock test: navigate to a `locked` period; confirm all entries show `mdi-lock` icon; clicking Edit opens `TimesheetEditRequestDialog` (NOT the standard edit form)
- [ ] 10.6 Edit request test: submit edit request with reason "Verkeerd project opgegeven: uren zijn voor Gemeente Utrecht, niet Amsterdam"; confirm `timesheetEditRequest` created with status `pending`
- [ ] 10.7 Edit request validation test: attempt to submit edit request with reason "Te kort" (9 chars); confirm validation error "Reden voor wijziging (verplicht, min. 10 tekens)" appears and no request is created
- [ ] 10.8 Seed data verification: navigate to Mijn Uren; confirm 4 `timesheetPeriod` seed objects are visible with correct statuses (submitted, locked, rejected, open)
- [ ] 10.9 Non-manager access test: log in as a regular employee; navigate to `/timesheet/approval`; confirm redirect to `/timesheet` with no approval controls
- [ ] 10.10 Hardcoded string check: `grep -rn '"Dien in"\|"Goedkeuren"\|"Afwijzen"\|"Mijn Uren"' src/` → all strings MUST use `t()`, not hardcoded
- [ ] 10.11 Translation key check: `grep -n "Dien in" l10n/nl.json l10n/en.json` → both files MUST contain the key
