---
status: draft
---

# Specs: pipelinq-project-to-shillinq-ledger

**Feature tier**: MVP
**Spec refs**: `openspec/specs/project-task-hierarchy/spec.md`, cross-app contract with shillinq
**Standards**: CloudEvents 1.0 (RFC 3339 timestamps), Schema.org (`schema:Project`), ADR-001 (international-first)

---

## REQ-PLG-001: Project Creation Ledger Event

When a new `project` is created in pipelinq, the system MUST immediately dispatch a ledger event to shillinq's ledger endpoint. The event MUST contain all required project metadata and MUST be tracked for sync status.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/pipelinq-project-to-shillinq-ledger/design.md#ProjectCreationListener`
**Files**: `pipelinq/lib/Listener/ProjectCreationListener.php`

### Scenario REQ-PLG-001-01: Ledger event dispatched on project creation

- GIVEN a user creates a new project "Implementatie ERP" with budgetAmount EUR 50,000 and status "open"
- WHEN the project object is saved to OpenRegister
- THEN `ProjectCreationListener` MUST fire
- AND a CloudEvents-formatted payload MUST be constructed with:
  - `type: "nl.conduction.pipelinq.project.created"`
  - `data.projectId`: project UUID
  - `data.projectName`: "Implementatie ERP"
  - `data.status`: "open"
  - `data.budgetAmount`: 50000
  - `data.billable`: true (or value from project)
- AND the payload MUST be dispatched to the configured `shillinq_ledger_webhook_url` via `WebhookService`

### Scenario REQ-PLG-001-02: ledgerSyncStatus set to pending before dispatch

- GIVEN a project is being created
- WHEN `ProjectCreationListener` fires
- THEN the project's `ledgerSyncStatus` MUST be set to `pending` before the webhook dispatch begins
- AND the change MUST be persisted to OpenRegister

### Scenario REQ-PLG-001-03: ledgerSyncStatus updated to synced on successful delivery

- GIVEN a project is created and `ledgerSyncStatus` is `pending`
- WHEN `WebhookService` successfully delivers the ledger event to shillinq (HTTP 200-299)
- THEN `ProjectCreationListener` MUST update the project with:
  - `ledgerSyncStatus: "synced"`
  - `ledgerSyncedAt: <current-iso-timestamp>`
- AND the change MUST be persisted to OpenRegister

### Scenario REQ-PLG-001-04: No dispatch if webhook URL not configured

- GIVEN no `shillinq_ledger_webhook_url` is set in admin settings
- WHEN a project is created
- THEN `ProjectCreationListener` MUST call `ShillinqLedgerService::shouldDispatch()`
- AND it MUST return `false`
- AND NO webhook dispatch MUST occur
- AND `ledgerSyncStatus` MAY remain `null`

### Scenario REQ-PLG-001-05: Event includes client and budget data

- GIVEN a project "Jaarrapport 2026" linked to client "De Vries & Partners" with budgetHours 200 and startDate "2026-06-01"
- WHEN the project is created
- THEN the CloudEvents payload MUST include:
  - `data.clientId`: UUID of the linked client
  - `data.budgetHours`: 200
  - `data.startDate`: "2026-06-01"
  - `data.createdBy`: Nextcloud user UID of the project creator
  - `data.createdAt`: ISO timestamp of project creation

---

## REQ-PLG-002: Project Status Change Ledger Event

When a `project` or `projectPhase` status changes, the system MUST dispatch an update event to shillinq's ledger endpoint. The event MUST capture the old and new status values.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/pipelinq-project-to-shillinq-ledger/design.md#ProjectPhaseStatusListener`
**Files**: `pipelinq/lib/Listener/ProjectPhaseStatusListener.php`

### Scenario REQ-PLG-002-01: Ledger event on project status change

- GIVEN a project "Website Herontwerp" with status "open" and `ledgerSyncStatus: "synced"`
- WHEN the project status is updated to "in_progress"
- THEN `ProjectPhaseStatusListener` MUST detect the status change
- AND a CloudEvents-formatted payload MUST be constructed with:
  - `type: "nl.conduction.pipelinq.project.status-changed"`
  - `data.oldStatus`: "open"
  - `data.newStatus`: "in_progress"
  - `data.projectId`: project UUID
  - `data.projectName`: "Website Herontwerp"
- AND the payload MUST be dispatched to shillinq via `WebhookService`

### Scenario REQ-PLG-002-02: Status mapping to ledger phase names

- GIVEN a project transitioning through statuses
- WHEN `ProjectPhaseStatusListener` constructs the ledger event
- THEN the `phase` field MUST map pipelinq statuses to shillinq phase names:
  - `open` → `initial`
  - `in_progress` → `active`
  - `completed` → `closed`
  - `cancelled` → `cancelled`
- AND the old and new status values MUST be included in the event data unchanged

### Scenario REQ-PLG-002-03: ledgerSyncStatus reset to pending on status change

- GIVEN a project with `ledgerSyncStatus: "synced"` and `ledgerSyncedAt: <timestamp>`
- WHEN the project status changes
- THEN `ledgerSyncStatus` MUST be reset to `pending`
- AND the listener MUST proceed to dispatch the update event
- AND on successful delivery, `ledgerSyncStatus` MUST be updated to `synced` and `ledgerSyncedAt` refreshed

### Scenario REQ-PLG-002-04: No status change dispatch if webhook URL not configured

- GIVEN no `shillinq_ledger_webhook_url` is set
- WHEN a project status changes
- THEN `ProjectPhaseStatusListener` MUST call `ShillinqLedgerService::shouldDispatch()`
- AND it MUST return `false`
- AND NO webhook dispatch MUST occur
- AND `ledgerSyncStatus` MUST remain unchanged (or be set to null if not yet synced)

### Scenario REQ-PLG-002-05: Phase status changes trigger parent project ledger event

- GIVEN a project "Digitalisering" with phase "Voorbereiding" (status: "open")
- WHEN the phase status is updated to "completed"
- THEN `ProjectPhaseStatusListener` MUST:
  - Fetch the parent project object for "Digitalisering"
  - Construct a ledger event with the parent project context (not phase-specific)
  - Include the parent project's UUID and name in the event
  - Dispatch the event to shillinq
  - Update the parent project's `ledgerSyncStatus` based on the outcome

---

## REQ-PLG-003: Ledger Sync Status Tracking

The `project` entity MUST track sync status with `ledgerSyncStatus` and `ledgerSyncedAt` fields. The system MUST handle dispatch failures gracefully and provide admin notification.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/pipelinq-project-to-shillinq-ledger/design.md#Extended Schema`
**Files**: `pipelinq/lib/Service/ShillinqLedgerService.php`

### Scenario REQ-PLG-003-01: ledgerSyncStatus set to failed after retry exhaustion

- GIVEN a project with a pending ledger event
- WHEN `WebhookService` attempts delivery 3 times and all fail (e.g., connection timeouts, HTTP 5xx)
- THEN `WebhookService` MUST exhaust its retry queue
- AND the listener MUST receive the final failure outcome
- AND the project's `ledgerSyncStatus` MUST be updated to `failed`
- AND `ledgerSyncedAt` MUST NOT be updated (remains at previous successful sync timestamp)

### Scenario REQ-PLG-003-02: Admin notification on sync failure

- GIVEN a project with `ledgerSyncStatus: "failed"`
- WHEN the retry exhaustion occurs
- THEN `ProjectCreationListener` or `ProjectPhaseStatusListener` MUST call `NotificationService`
- AND a notification MUST be sent to Nextcloud admin user(s) with message:
  - "Shillinq ledger sync failed for project '<projectName>'. Manual retry available in project detail view."

### Scenario REQ-PLG-003-03: Manual retry from project detail view

- GIVEN a project with `ledgerSyncStatus: "failed"` visible in the project detail view
- WHEN the admin clicks the "Retry ledger sync" button in the ledger status card
- THEN a POST request MUST be sent to `/apps/pipelinq/api/ledger/retry/{projectId}`
- AND the backend listener MUST:
  - Fetch the current project
  - Reset `ledgerSyncStatus` to `pending`
  - Call `ShillinqLedgerService::dispatchProjectEvent()` (or appropriate update event if status has changed)
  - Update `ledgerSyncStatus` based on the dispatch outcome
- AND the frontend MUST refresh the ledger status card to show the new status

### Scenario REQ-PLG-003-04: Idempotency on creation event

- GIVEN a project with `ledgerSyncStatus: "synced"` that was already created and synced
- WHEN the `ObjectCreatedEvent` is fired again for the same project (e.g., due to a system event replay)
- THEN `ProjectCreationListener` MUST check if `ledgerSyncStatus` is already `synced`
- AND it MUST NOT dispatch a duplicate creation event
- AND the project MUST NOT be modified

---

## REQ-PLG-004: Ledger Sync Status Badge in Project List

The project list view MUST display a `ledgerSyncStatus` badge for each project. The badge MUST clearly indicate the sync state with color coding.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/pipelinq-project-to-shillinq-ledger/design.md#Project list view`
**Files**: `pipelinq/src/views/projects/ProjectList.vue`

### Scenario REQ-PLG-004-01: Synced badge shown in green

- GIVEN the project list view is loaded with projects
- WHEN a project has `ledgerSyncStatus: "synced"`
- THEN a green badge MUST be displayed in the ledger sync status column
- AND the badge text MUST be "Ledger gesynchroniseerd" (Dutch)
- AND the badge background color MUST be `#28a745` (green)

### Scenario REQ-PLG-004-02: Pending badge shown in yellow

- GIVEN a project with `ledgerSyncStatus: "pending"`
- WHEN the project list view renders
- THEN a yellow badge MUST be displayed
- AND the badge text MUST be "Ledger in behandeling"
- AND the background color MUST be `#ffc107` (yellow)

### Scenario REQ-PLG-004-03: Failed badge shown in red

- GIVEN a project with `ledgerSyncStatus: "failed"`
- WHEN the project list view renders
- THEN a red badge MUST be displayed
- AND the badge text MUST be "Ledger mislukt"
- AND the background color MUST be `#dc3545` (red)

### Scenario REQ-PLG-004-04: No badge for null ledgerSyncStatus

- GIVEN a project created before this change was deployed (no `ledgerSyncStatus` field)
- WHEN the project list view renders
- THEN a grey dash or empty state MUST be displayed in the ledger sync status column
- AND NO colored badge MUST appear

---

## REQ-PLG-005: Ledger Status Card in Project Detail

The project detail view MUST display a ledger status card showing the current sync state, last synced timestamp, and manual retry button.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/pipelinq-project-to-shillinq-ledger/design.md#Project detail view`
**Files**: `pipelinq/src/views/projects/ProjectDetail.vue`

### Scenario REQ-PLG-005-01: Ledger status card displays current state

- GIVEN a project detail view is open
- WHEN the admin has configured a shillinq webhook URL
- THEN a "Shillinq Ledger" card MUST be displayed above the WBS tree
- AND it MUST show:
  - Current `ledgerSyncStatus` badge (color-coded as per REQ-PLG-004)
  - Label "Status:" followed by status text
  - "Last synced:" label with `ledgerSyncedAt` timestamp (formatted as "2026-05-20 at 14:32" or locale equivalent)

### Scenario REQ-PLG-005-02: Retry button visible only when status is failed

- GIVEN the ledger status card is displayed
- WHEN `ledgerSyncStatus` is `failed`
- THEN a "Retry Sync" button MUST be visible below or next to the status badge
- AND the button MUST be enabled and clickable
- GIVEN `ledgerSyncStatus` is `synced` or `pending`
- THEN the retry button MUST NOT be displayed or MUST be disabled

### Scenario REQ-PLG-005-03: Retry button triggers manual sync

- GIVEN the ledger status card shows `failed` status with a visible retry button
- WHEN the admin clicks the "Retry Sync" button
- THEN the frontend MUST:
  - Disable the button (show loading state)
  - POST to `/apps/pipelinq/api/ledger/retry/{projectId}`
  - Wait for response (max 10 seconds timeout)
- AND the backend MUST proceed as described in REQ-PLG-003-03
- AND on success:
  - The card MUST refresh to show the new status
  - A success toast "Ledger sync retry initiated" MUST appear
- AND on error:
  - A error toast "Ledger sync retry failed: <error details>" MUST appear

### Scenario REQ-PLG-005-04: Ledger card hidden if webhook not configured

- GIVEN the admin has NOT configured `shillinq_ledger_webhook_url` in settings
- WHEN a project detail view is opened
- THEN the ledger status card MUST NOT be displayed
- AND no reference to ledger sync MUST appear on the page

---

## REQ-PLG-006: Admin Settings for Webhook Configuration

The pipelinq admin settings panel MUST include a field to configure the shillinq ledger webhook URL. The value MUST be persisted via OpenRegister's `IAppConfig`.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/pipelinq-project-to-shillinq-ledger/design.md#Admin.php`
**Files**: `pipelinq/lib/Settings/Admin.php`, `pipelinq/src/components/AdminSettings.vue`

### Scenario REQ-PLG-006-01: Webhook URL field in admin settings

- GIVEN the pipelinq admin settings page is open
- WHEN the page loads
- THEN a "Shillinq Integration" or "Integraties" section MUST be visible
- AND within that section, a text input labeled "Shillinq Ledger Webhook URL" MUST appear
- AND the input MUST show the currently configured URL (if any)

### Scenario REQ-PLG-006-02: Webhook URL validation

- GIVEN the webhook URL field is focused
- WHEN the admin enters an invalid URL (e.g., "not a url", "http://")
- THEN the field MUST show a validation error: "Please enter a valid HTTPS URL"
- AND the save button MUST be disabled

### Scenario REQ-PLG-006-03: Webhook URL persisted to IAppConfig

- GIVEN a valid HTTPS URL "https://shillinq.example.com/api/ledger" is entered in the webhook URL field
- WHEN the admin clicks "Save Settings"
- THEN the value MUST be persisted via `IAppConfig::setValueString('pipelinq', 'shillinq_ledger_webhook_url', '<url>')`
- AND a success message "Settings saved" MUST appear
- AND on page reload, the URL field MUST repopulate with the saved value

### Scenario REQ-PLG-006-04: Empty webhook URL disables ledger sync

- GIVEN `shillinq_ledger_webhook_url` is currently set to a valid URL
- WHEN the admin clears the field and saves
- THEN the value MUST be persisted as an empty string
- AND `ShillinqLedgerService::shouldDispatch()` MUST return `false`
- AND NO ledger events MUST be dispatched for new projects or status changes
- AND existing `ledgerSyncStatus` values on projects MUST NOT be modified

---

## REQ-PLG-007: Translation Keys

All user-visible strings MUST be localized via the translation system. Dutch (nl) and English (en) translations MUST be provided.

**Feature tier**: MVP
**Files**: `l10n/en.json`, `l10n/nl.json`

### Scenario REQ-PLG-007-01: Ledger status badge translation keys

- GIVEN the project list or detail view renders ledger status badges
- THEN the following translation keys MUST exist in both `en.json` and `nl.json`:
  - `ledger_synced`: "Ledger synchronized" / "Ledger gesynchroniseerd"
  - `ledger_pending`: "Ledger pending" / "Ledger in behandeling"
  - `ledger_failed`: "Ledger sync failed" / "Ledger mislukt"
  - `ledger_status`: "Status" / "Status"
  - `ledger_last_synced`: "Last synced" / "Laatst gesynchroniseerd"
  - `ledger_retry_button`: "Retry Sync" / "Synchronisatie opnieuw proberen"

### Scenario REQ-PLG-007-02: Admin settings translation keys

- GIVEN the admin settings page loads
- THEN the following keys MUST exist in both locales:
  - `shillinq_integration`: "Shillinq Integration" / "Shillinq Integratie"
  - `shillinq_ledger_webhook_url`: "Shillinq Ledger Webhook URL" / "Shillinq Ledger Webhook URL"
  - `shillinq_webhook_url_invalid`: "Please enter a valid HTTPS URL" / "Voer een geldige HTTPS-URL in"

### Scenario REQ-PLG-007-03: Notification message translation

- GIVEN a ledger sync fails permanently
- WHEN the admin notification is generated
- THEN the message MUST use the key `ledger_sync_failed_notification`:
  - EN: "Shillinq ledger sync failed for project '{projectName}'. Manual retry available in project detail view."
  - NL: "Shillinq-ledgersynchronisatie mislukt voor project '{projectName}'. Handmatige synchronisatie beschikbaar in projectdetailweergave."
