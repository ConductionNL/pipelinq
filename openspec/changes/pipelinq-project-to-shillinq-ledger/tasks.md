# Tasks: pipelinq-project-to-shillinq-ledger

## 1. Backend: Project Creation Listener (REQ-PLG-001)

- [ ] 1.1 Create `lib/Listener/ProjectCreationListener.php`
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-001`
  - **files**: `lib/Listener/ProjectCreationListener.php`
  - **acceptance_criteria**:
    - GIVEN a new `project` object is created via OpenRegister
    - THEN `ProjectCreationListener` (implementing `OCP\EventDispatcher\IEventListener`) MUST be triggered
    - AND the listener receives the `ObjectCreatedEvent` with the new project data
    - AND `ShillinqLedgerService::shouldDispatch()` is called to check if webhook is configured

- [ ] 1.2 Implement project sync status initialization
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-001-02`
  - **files**: `lib/Listener/ProjectCreationListener.php`
  - **acceptance_criteria**:
    - GIVEN `shouldDispatch()` returns `true`
    - THEN the listener MUST set `project.ledgerSyncStatus = "pending"` via `ObjectService::saveObject()`
    - AND the change MUST be persisted before the webhook dispatch begins

- [ ] 1.3 Dispatch project creation event to shillinq
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-001-01`
  - **files**: `lib/Listener/ProjectCreationListener.php`, `lib/Service/ShillinqLedgerService.php`
  - **acceptance_criteria**:
    - GIVEN ledgerSyncStatus is `pending`
    - WHEN `ShillinqLedgerService::dispatchProjectEvent($project, 'created')` is called
    - THEN a CloudEvents-formatted payload MUST be constructed
    - AND it MUST include: projectId, projectName, clientId, status, billable, budgetAmount, budgetHours, startDate, endDate, createdBy, createdAt
    - AND the payload MUST be dispatched via `WebhookService::dispatchEvent($webhookUrl, $payload)`

- [ ] 1.4 Handle successful webhook delivery
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-001-03`
  - **files**: `lib/Listener/ProjectCreationListener.php`
  - **acceptance_criteria**:
    - GIVEN `WebhookService::dispatchEvent()` returns `true`
    - THEN the listener MUST update the project with:
      - `ledgerSyncStatus = "synced"`
      - `ledgerSyncedAt = <current-iso-timestamp>`
    - AND the changes MUST be persisted to OpenRegister

- [ ] 1.5 Handle webhook delivery failure
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-003-01`
  - **files**: `lib/Listener/ProjectCreationListener.php`
  - **acceptance_criteria**:
    - GIVEN `WebhookService::dispatchEvent()` returns `false` after retry exhaustion
    - THEN the listener MUST set `ledgerSyncStatus = "failed"`
    - AND it MUST call `NotificationService` to notify admin users

- [ ] 1.6 Implement idempotency check
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-003-04`
  - **files**: `lib/Listener/ProjectCreationListener.php`
  - **acceptance_criteria**:
    - GIVEN a project with `ledgerSyncStatus = "synced"`
    - WHEN `ObjectCreatedEvent` fires again for the same project
    - THEN the listener MUST check `ledgerSyncStatus` before dispatching
    - AND it MUST skip the dispatch if status is already `synced`

- [ ] 1.7 Register listener in Application.php
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-001`
  - **files**: `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the pipelinq app is initialized
    - WHEN the Application class is instantiated
    - THEN `ProjectCreationListener` MUST be registered for `ObjectCreatedEvent`
    - AND it MUST be filtered to only fire for `project` schema objects

---

## 2. Backend: Project Phase Status Listener (REQ-PLG-002)

- [ ] 2.1 Create `lib/Listener/ProjectPhaseStatusListener.php`
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-002`
  - **files**: `lib/Listener/ProjectPhaseStatusListener.php`
  - **acceptance_criteria**:
    - GIVEN a `project` or `projectPhase` object is updated
    - WHEN `ObjectUpdatedEvent` is dispatched by OpenRegister
    - THEN `ProjectPhaseStatusListener` MUST be triggered
    - AND it MUST receive the old and new object data

- [ ] 2.2 Detect status changes in project and phase updates
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-002-01`
  - **files**: `lib/Listener/ProjectPhaseStatusListener.php`
  - **acceptance_criteria**:
    - GIVEN `oldProject.status` is "open" and `newProject.status` is "in_progress"
    - WHEN the listener compares old and new objects
    - THEN it MUST detect the status change
    - AND proceed to dispatch an update event (not just return early)

- [ ] 2.3 Dispatch phase change event to shillinq
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-002-01`
  - **files**: `lib/Listener/ProjectPhaseStatusListener.php`, `lib/Service/ShillinqLedgerService.php`
  - **acceptance_criteria**:
    - GIVEN a project status change is detected
    - WHEN `ShillinqLedgerService::dispatchPhaseChangeEvent($project, $oldStatus, $newStatus)` is called
    - THEN a CloudEvents payload MUST be constructed with:
      - `type: "nl.conduction.pipelinq.project.status-changed"`
      - `data.oldStatus`, `data.newStatus`, `data.projectId`, `data.projectName`
    - AND the payload MUST be dispatched via `WebhookService`

- [ ] 2.4 Implement status-to-phase-name mapping
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-002-02`
  - **files**: `lib/Service/ShillinqLedgerService.php`
  - **acceptance_criteria**:
    - GIVEN the mapping:
      - `open` → `initial`
      - `in_progress` → `active`
      - `completed` → `closed`
      - `cancelled` → `cancelled`
    - WHEN the ledger event is constructed
    - THEN the `phase` field MUST contain the mapped value
    - AND the `oldStatus` and `newStatus` fields MUST contain the original pipelinq values unchanged

- [ ] 2.5 Reset ledgerSyncStatus on status change
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-002-03`
  - **files**: `lib/Listener/ProjectPhaseStatusListener.php`
  - **acceptance_criteria**:
    - GIVEN a project with `ledgerSyncStatus: "synced"`
    - WHEN the project status changes
    - THEN `ledgerSyncStatus` MUST be reset to `pending`
    - AND the update event MUST be dispatched
    - AND on success, `ledgerSyncStatus` MUST be updated to `synced`

- [ ] 2.6 Handle phase parent project updates
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-002-05`
  - **files**: `lib/Listener/ProjectPhaseStatusListener.php`
  - **acceptance_criteria**:
    - GIVEN a `projectPhase` status change is detected
    - WHEN the listener processes the event
    - THEN it MUST fetch the parent `project` object via the phase's `project` reference
    - AND dispatch the ledger event in the parent project's context (not phase-specific)
    - AND update the parent project's `ledgerSyncStatus`

- [ ] 2.7 Register listener in Application.php
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-002`
  - **files**: `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the pipelinq app is initialized
    - WHEN Application class is instantiated
    - THEN `ProjectPhaseStatusListener` MUST be registered for `ObjectUpdatedEvent`
    - AND it MUST be filtered to fire for `project` and `projectPhase` schema objects

---

## 3. Backend: Ledger Service (REQ-PLG-003)

- [ ] 3.1 Create `lib/Service/ShillinqLedgerService.php`
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-003`
  - **files**: `lib/Service/ShillinqLedgerService.php`
  - **acceptance_criteria**:
    - GIVEN a new service class is created
    - THEN it MUST have `__construct(private IAppConfig $appConfig, private WebhookService $webhookService)`
    - AND it MUST implement methods: `shouldDispatch()`, `dispatchProjectEvent()`, `dispatchPhaseChangeEvent()`

- [ ] 3.2 Implement shouldDispatch() method
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-001-04`
  - **files**: `lib/Service/ShillinqLedgerService.php`
  - **acceptance_criteria**:
    - GIVEN `shouldDispatch()` is called
    - WHEN `shillinq_ledger_webhook_url` is not configured (null or empty)
    - THEN it MUST return `false`
    - WHEN the URL is set to a valid HTTPS URL
    - THEN it MUST return `true`
    - WHEN the URL is set to an invalid format (e.g., "http://", "not-a-url")
    - THEN it MUST return `false`

- [ ] 3.3 Build and dispatch project creation event payload
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-001-01`
  - **files**: `lib/Service/ShillinqLedgerService.php`
  - **acceptance_criteria**:
    - GIVEN `dispatchProjectEvent($project, 'created')` is called
    - THEN a CloudEvents 1.0 payload MUST be constructed:
      - `specversion: "1.0"`
      - `type: "nl.conduction.pipelinq.project.created"`
      - `source: "/apps/pipelinq/projects"`
      - `id: <project.uuid>`
      - `time: <project.createdAt>`
    - AND the `data` object MUST contain all required fields (see design.md)

- [ ] 3.4 Build and dispatch phase change event payload
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-002-01`
  - **files**: `lib/Service/ShillinqLedgerService.php`
  - **acceptance_criteria**:
    - GIVEN `dispatchPhaseChangeEvent($project, 'open', 'in_progress')` is called
    - THEN a CloudEvents payload MUST be constructed:
      - `type: "nl.conduction.pipelinq.project.status-changed"`
      - `data.oldStatus: "open"`
      - `data.newStatus: "in_progress"`
    - AND the `phase` field MUST map to "active" (via status mapping)
    - AND `data` MUST include projectId, projectName, clientId, billable, budgetAmount

- [ ] 3.5 Handle webhook dispatch outcomes
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-003-01`
  - **files**: `lib/Service/ShillinqLedgerService.php`
  - **acceptance_criteria**:
    - GIVEN `WebhookService::dispatchEvent()` is called
    - WHEN it returns `true` (successful delivery)
    - THEN `dispatchProjectEvent()` MUST return `true`
    - WHEN it returns `false` (failed after retries)
    - THEN `dispatchProjectEvent()` MUST return `false`
    - AND the caller can act on the outcome (update ledgerSyncStatus, notify admin)

---

## 4. Backend: Admin Notification (REQ-PLG-003)

- [ ] 4.1 Send admin notification on ledger sync failure
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-003-02`
  - **files**: `lib/Listener/ProjectCreationListener.php`, `lib/Listener/ProjectPhaseStatusListener.php`
  - **acceptance_criteria**:
    - GIVEN ledger dispatch fails (returns `false`)
    - WHEN the listener processes the failure
    - THEN `NotificationService::notify()` MUST be called for admin user(s)
    - AND the message MUST include:
      - Project name
      - Type of event (creation or status change)
      - Link or reference to the project detail view for manual retry

---

## 5. Backend: Admin Settings (REQ-PLG-006)

- [ ] 5.1 Extend `lib/Settings/Admin.php` with webhook URL setting
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-006`
  - **files**: `lib/Settings/Admin.php`
  - **acceptance_criteria**:
    - GIVEN the admin settings endpoint is called
    - WHEN the response is built
    - THEN a `shillinq_ledger_webhook_url` field MUST be included
    - AND it MUST contain the current value from `IAppConfig` (or empty string if not set)

- [ ] 5.2 Create admin settings API endpoint for webhook configuration
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-006-03`
  - **files**: `lib/Controller/AdminSettingsController.php` (or extend existing)
  - **acceptance_criteria**:
    - GIVEN an authenticated admin user
    - WHEN they POST to `/apps/pipelinq/api/admin/settings` with `{ shillinq_ledger_webhook_url: "https://..." }`
    - THEN the value MUST be persisted via `IAppConfig::setValueString('pipelinq', 'shillinq_ledger_webhook_url', '<url>')`
    - AND the response MUST return the updated settings

---

## 6. Frontend: Project Schema Extension

- [ ] 6.1 Add ledgerSyncStatus and ledgerSyncedAt to project schema
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-003`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the pipelinq register schema is loaded
    - THEN the `project` schema MUST include:
      - `ledgerSyncStatus`: string (enum: pending, synced, failed)
      - `ledgerSyncedAt`: string (ISO 8601 timestamp)
    - AND both fields MUST be optional (required: false)

- [ ] 6.2 Update project store to handle ledger sync fields
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-003`
  - **files**: `src/stores/projectStore.js`
  - **acceptance_criteria**:
    - GIVEN a project object is loaded from OpenRegister
    - THEN the store MUST preserve `ledgerSyncStatus` and `ledgerSyncedAt` fields
    - AND they MUST be accessible in Vue components via the project object

---

## 7. Frontend: Project List Badge (REQ-PLG-004)

- [ ] 7.1 Add ledger sync status column to ProjectList.vue
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-004`
  - **files**: `src/views/projects/ProjectList.vue`
  - **acceptance_criteria**:
    - GIVEN the project list view renders
    - THEN a new column "Ledger Status" MUST appear after the Status column
    - AND it MUST display a badge per project showing the sync state

- [ ] 7.2 Render color-coded badges based on ledgerSyncStatus
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-004`
  - **files**: `src/views/projects/ProjectList.vue`
  - **acceptance_criteria**:
    - GIVEN a project with `ledgerSyncStatus: "synced"`
    - THEN a green badge MUST display "Ledger gesynchroniseerd"
    - GIVEN `ledgerSyncStatus: "pending"`
    - THEN a yellow badge MUST display "Ledger in behandeling"
    - GIVEN `ledgerSyncStatus: "failed"`
    - THEN a red badge MUST display "Ledger mislukt"
    - GIVEN `ledgerSyncStatus: null`
    - THEN a grey dash MUST appear

- [ ] 7.3 Use i18n for badge text
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-007`
  - **files**: `src/views/projects/ProjectList.vue`, `l10n/en.json`, `l10n/nl.json`
  - **acceptance_criteria**:
    - GIVEN the badge is rendered
    - THEN all text MUST come from i18n keys (ledger_synced, ledger_pending, ledger_failed)
    - AND both en.json and nl.json MUST be updated with the keys

---

## 8. Frontend: Project Detail Ledger Card (REQ-PLG-005)

- [ ] 8.1 Create ledger status card component in ProjectDetail.vue
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-005`
  - **files**: `src/views/projects/ProjectDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the project detail view loads
    - WHEN a webhook URL is configured in admin settings
    - THEN a "Shillinq Ledger" card MUST appear above the WBS tree
    - AND it MUST display: status badge, "Status:" label, "Last synced:" timestamp

- [ ] 8.2 Show retry button when ledgerSyncStatus is failed
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-005-02`
  - **files**: `src/views/projects/ProjectDetail.vue`
  - **acceptance_criteria**:
    - GIVEN `ledgerSyncStatus: "failed"`
    - WHEN the ledger card renders
    - THEN a "Retry Sync" button MUST be visible and enabled
    - GIVEN `ledgerSyncStatus: "synced"` or `"pending"`
    - THEN the button MUST NOT appear or be disabled

- [ ] 8.3 Implement retry button click handler
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-005-03`
  - **files**: `src/views/projects/ProjectDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the retry button is clicked
    - WHEN the button is in `failed` state
    - THEN a POST request MUST be sent to `/apps/pipelinq/api/ledger/retry/{projectId}`
    - AND the button MUST show a loading state during the request
    - AND on success, the ledger status card MUST refresh with the new status
    - AND on error, an error toast MUST appear with details

- [ ] 8.4 Hide ledger card if webhook not configured
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-005-04`
  - **files**: `src/views/projects/ProjectDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the admin has NOT configured `shillinq_ledger_webhook_url`
    - WHEN the project detail view loads
    - THEN the ledger status card MUST NOT be rendered in the DOM
    - AND no ledger-related UI MUST appear

---

## 9. Frontend: Admin Settings Panel (REQ-PLG-006)

- [ ] 9.1 Add webhook URL input to admin settings Vue component
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-006`
  - **files**: `src/components/AdminSettings.vue` (or create if not exists)
  - **acceptance_criteria**:
    - GIVEN the admin settings page loads
    - THEN a "Shillinq Integration" or "Integraties" section MUST be visible
    - AND a text input field labeled "Shillinq Ledger Webhook URL" MUST appear
    - AND the field MUST show the current value (if set)

- [ ] 9.2 Implement webhook URL validation
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-006-02`
  - **files**: `src/components/AdminSettings.vue`
  - **acceptance_criteria**:
    - GIVEN the webhook URL field is edited
    - WHEN an invalid URL is entered (e.g., "http://", "not a url")
    - THEN an error message "Please enter a valid HTTPS URL" MUST appear
    - AND the save button MUST be disabled
    - WHEN a valid HTTPS URL is entered
    - THEN the error message MUST disappear
    - AND the save button MUST be enabled

- [ ] 9.3 Save webhook URL to backend
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-006-03`
  - **files**: `src/components/AdminSettings.vue`
  - **acceptance_criteria**:
    - GIVEN a valid HTTPS URL is entered
    - WHEN the admin clicks "Save Settings"
    - THEN a POST/PUT request MUST be sent to the admin settings endpoint
    - AND the request body MUST include `{ shillinq_ledger_webhook_url: "<url>" }`
    - AND on success, a "Settings saved" toast MUST appear
    - AND on page reload, the field MUST repopulate with the saved value

- [ ] 9.4 Handle empty webhook URL
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-006-04`
  - **files**: `src/components/AdminSettings.vue`
  - **acceptance_criteria**:
    - GIVEN the webhook URL field is currently set to a valid URL
    - WHEN the admin clears the field and saves
    - THEN the backend MUST persist an empty string
    - AND ledger sync MUST be disabled for new projects and status changes

---

## 10. Localization (REQ-PLG-007)

- [ ] 10.1 Add English (en) translation keys
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-007`
  - **files**: `l10n/en.json`
  - **acceptance_criteria**:
    - GIVEN the English translation file is loaded
    - THEN the following keys MUST be present:
      - `ledger_synced`: "Ledger synchronized"
      - `ledger_pending`: "Ledger pending"
      - `ledger_failed`: "Ledger sync failed"
      - `ledger_status`: "Status"
      - `ledger_last_synced`: "Last synced"
      - `ledger_retry_button`: "Retry Sync"
      - `shillinq_integration`: "Shillinq Integration"
      - `shillinq_ledger_webhook_url`: "Shillinq Ledger Webhook URL"
      - `shillinq_webhook_url_invalid`: "Please enter a valid HTTPS URL"

- [ ] 10.2 Add Dutch (nl) translation keys
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/spec.md#REQ-PLG-007`
  - **files**: `l10n/nl.json`
  - **acceptance_criteria**:
    - GIVEN the Dutch translation file is loaded
    - THEN the following keys MUST be present with Dutch translations:
      - `ledger_synced`: "Ledger gesynchroniseerd"
      - `ledger_pending`: "Ledger in behandeling"
      - `ledger_failed`: "Ledger mislukt"
      - `ledger_status`: "Status"
      - `ledger_last_synced`: "Laatst gesynchroniseerd"
      - `ledger_retry_button`: "Synchronisatie opnieuw proberen"
      - `shillinq_integration`: "Shillinq Integratie"
      - `shillinq_ledger_webhook_url`: "Shillinq Ledger Webhook URL"
      - `shillinq_webhook_url_invalid`: "Voer een geldige HTTPS-URL in"

---

## 11. Seed Data (REQ-PLG-003)

- [ ] 11.1 Add seed project objects with ledger sync status
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/design.md#Seed Data`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register is initialized with seed data
    - THEN 3 example `project` objects MUST be included:
      1. One with `ledgerSyncStatus: "synced"` and `ledgerSyncedAt: <timestamp>`
      2. One with `ledgerSyncStatus: "pending"` and `ledgerSyncedAt: null`
      3. One with `ledgerSyncStatus: "failed"` and `ledgerSyncedAt: <timestamp>`
    - AND all projects MUST have realistic Dutch names and values

---

## 12. Build and Testing

- [ ] 12.1 Run `npm run build` and verify zero errors
  - **spec_ref**: `specs/pipelinq-project-to-shillinq-ledger/proposal.md#Success Criteria`
  - **files**: All modified files
  - **acceptance_criteria**:
    - GIVEN `npm run build` is executed
    - THEN the build MUST complete without errors
    - AND no console warnings related to the new code MUST appear
    - AND the bundled app MUST be ready for deployment

- [ ] 12.2 Verify PHP syntax and linting
  - **files**: All new/modified `.php` files
  - **acceptance_criteria**:
    - GIVEN `php -l` is run on all PHP files
    - THEN no syntax errors MUST be reported
    - GIVEN coding standards checker (if configured) is run
    - THEN no violations MUST be found in the new code

- [ ] 12.3 Test ledger listener in isolation
  - **acceptance_criteria**:
    - GIVEN a test that mocks `ObjectCreatedEvent` for a project
    - WHEN `ProjectCreationListener` is triggered
    - THEN the listener MUST call the expected service methods
    - AND `ledgerSyncStatus` MUST be updated correctly

---

## 13. Documentation and Cleanup

- [ ] 13.1 Verify no debug statements left in code
  - **files**: All new PHP and Vue files
  - **acceptance_criteria**:
    - GIVEN the code is reviewed for debug statements
    - THEN no `dump()`, `dd()`, `console.log()`, or temporary comments MUST remain
    - AND the code MUST be production-ready

- [ ] 13.2 Commit changes with proper message
  - **files**: All modified/new files
  - **acceptance_criteria**:
    - GIVEN all changes are staged
    - WHEN `git commit` is run
    - THEN the commit message MUST be:
      ```
      feat: Add OpenSpec change pipelinq-project-to-shillinq-ledger from Specter
      ```
