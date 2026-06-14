---
status: draft
---

# Specification: pipelinq-expense-to-shillinq-ap

## Purpose

Define the requirements for a one-way, event-driven integration that automatically syncs approved expenses from pipelinq to Shillinq's Accounts Payable (AP) system. This integration closes the AP data flow gap and enables finance teams to eliminate manual expense re-entry.

**Standards**: CloudEvents 1.0 (event format), OpenRegister (entity persistence), Nextcloud (webhook retry via WebhookService)
**Primary feature tier**: MVP
**Demand evidence**: cross-app contract; industry standard (Expensify, Concur, Ramp, Pleo)

---

## Data Model

See design.md for full schema definitions.

| Entity | Purpose |
|--------|---------|
| expense | Expense record with new `apSyncStatus` and `apSyncedAt` fields |

---

## Requirements

### REQ-AP-001: Schema extension for AP sync tracking [MVP]

The system MUST extend the `expense` schema with two new properties to track the AP sync lifecycle. These properties are optional (null until the first approval event is received) and MAY be null for expenses created before this change is deployed.

#### Scenario 1: Expense gains apSyncStatus and apSyncedAt properties

- GIVEN the `expense` schema in `lib/Settings/pipelinq_register.json`
- WHEN the OpenRegister migrations are applied
- THEN the schema MUST include two new optional string properties: `apSyncStatus` and `apSyncedAt`
- AND `apSyncStatus` MUST accept values: `pending`, `synced`, or `failed`
- AND `apSyncedAt` MUST be an ISO 8601 UTC timestamp string
- AND both fields MUST default to null for new expenses
- AND re-importing the schema with `force: false` MUST NOT create duplicate property definitions

#### Scenario 2: Existing expenses remain unaffected

- GIVEN a pipelinq installation with 50 existing expenses
- WHEN the schema extension is applied
- THEN all 50 existing expenses MUST retain their current property values
- AND `apSyncStatus` and `apSyncedAt` MUST be null for all existing expenses
- AND the expense audit trail MUST NOT show a modification event for the schema change

#### Scenario 3: Rejected: expense with invalid apSyncStatus

- GIVEN an expense detail form
- WHEN a system operator attempts to manually set `apSyncStatus` to an invalid value (e.g., `"unknown"`)
- THEN the system MUST reject the change
- AND the operator MUST receive a validation error message: "apSyncStatus moet een van de volgende waarden zijn: pending, synced, failed"

---

### REQ-AP-002: Event listener for expense approval [MVP]

The system MUST register a listener on the `ExpenseApprovedEvent` from `expense-capture-core`. When an expense transitions to `approved` status, the listener MUST initiate the AP sync workflow.

#### Scenario 4: Listener receives approval event and sets status to pending

- GIVEN an expense with `status: "draft"` and `apSyncStatus: null`
- WHEN the `ExpenseApprovedEvent` is dispatched (e.g., by `expense-capture-core` approval workflow)
- THEN the system MUST update the expense to `apSyncStatus: "pending"` within 100ms
- AND the audit trail MUST record the status change with timestamp and operator identity
- AND the listener MUST NOT block other event handlers from running (async / non-blocking)

#### Scenario 5: Listener is idempotent — no duplicate dispatch on repeat events

- GIVEN an expense with `apSyncStatus: "synced"` and `apSyncedAt: "2026-05-15T14:35:00Z"`
- WHEN the same `ExpenseApprovedEvent` is dispatched a second time (e.g., due to event replay or duplicate delivery)
- THEN the listener MUST detect the existing `synced` status
- AND the listener MUST skip the AP dispatch (idempotent check)
- AND the expense properties MUST remain unchanged
- AND no AP event MUST be sent to Shillinq

#### Scenario 6: Listener aborts if webhook URL is not configured

- GIVEN a pipelinq installation with no `shillinq_ap_webhook_url` configured in admin settings
- WHEN an expense is approved
- THEN the listener MUST detect the missing configuration
- AND the listener MUST skip the AP dispatch (no-op)
- AND `apSyncStatus` MUST remain null
- AND no error notification MUST be sent

---

### REQ-AP-003: AP event dispatch and retry [MVP]

The system MUST construct a CloudEvents-formatted AP payload and dispatch it to Shillinq's AP webhook. The dispatch MUST be retried on transient failure. After final failure, the admin MUST be notified.

#### Scenario 7: AP event dispatched with correct CloudEvents format

- GIVEN an approved expense with UUID `abc123`, amount `€125.50`, category `accommodation`, client `client-xyz`, approved-by user `alice`, approved-at `2026-05-15T14:30:00Z`
- WHEN the `ExpenseApprovedEvent` is processed
- THEN the system MUST construct a CloudEvents payload:
  ```json
  {
    "specversion": "1.0",
    "type": "nl.conduction.pipelinq.expense.approved",
    "source": "/apps/pipelinq/expenses",
    "id": "abc123",
    "time": "2026-05-15T14:30:00Z",
    "data": {
      "expenseId": "abc123",
      "amount": 125.50,
      "categoryId": "accommodation",
      "clientId": "client-xyz",
      "projectId": null,
      "billable": false,
      "approvedBy": "alice",
      "approvedAt": "2026-05-15T14:30:00Z"
    }
  }
  ```
- AND the payload MUST be dispatched to the configured `shillinq_ap_webhook_url` via HTTP POST
- AND the `Content-Type` header MUST be set to `application/json`

#### Scenario 8: AP event includes billable project reference

- GIVEN an approved expense with `billable: true` and `project: "proj-456"`
- WHEN the AP event is dispatched
- THEN the event payload MUST include `"projectId": "proj-456"`
- AND `"billable": true`
- AND the data payload MUST be otherwise identical to Scenario 7

#### Scenario 9: Successful dispatch updates apSyncStatus to synced

- GIVEN a dispatch request to the Shillinq webhook
- WHEN the webhook responds with HTTP 200–299 (success)
- THEN the system MUST update the expense:
  - `apSyncStatus = "synced"`
  - `apSyncedAt = <current UTC timestamp>`
- AND the audit trail MUST record the sync completion

#### Scenario 10: Failed dispatch after retries sets status to failed and notifies admin

- GIVEN a dispatch request to the Shillinq webhook
- WHEN the webhook returns HTTP 5xx (server error) or times out
- AND the `WebhookService` has exhausted its retry queue (3 retries, exponential backoff, default 5 seconds between attempts)
- THEN the system MUST update the expense:
  - `apSyncStatus = "failed"`
  - `apSyncedAt` remains null or unchanged
- AND the system MUST send a Nextcloud notification to the configured admin user with the message: "Shillinq AP sync mislukt voor onkosten-ID {id}. Controleer de instellingen en probeer opnieuw."
- AND the audit trail MUST record the failure with error details

#### Scenario 11: Admin can retry manual dispatch from expense detail view

- GIVEN an expense with `apSyncStatus: "failed"`
- WHEN the admin clicks the "Opnieuw versturen" button in the expense detail view
- THEN the system MUST set `apSyncStatus = "pending"` and re-dispatch the AP event
- AND the dispatch outcome MUST be handled as in Scenarios 9–10
- AND the audit trail MUST record the manual retry action

---

### REQ-AP-004: Admin settings for webhook URL [MVP]

The system MUST expose a configurable webhook URL in the pipelinq admin settings panel. This URL is persisted in Nextcloud's `IAppConfig` and read by the `ShillinqApService`.

#### Scenario 12: Admin configures webhook URL in settings

- GIVEN the pipelinq admin settings panel
- WHEN an admin navigates to "Integraties" → "Shillinq AP"
- THEN a URL input field labeled "Shillinq AP webhook URL" MUST be displayed
- AND the current configured URL (if any) MUST be pre-populated
- AND the admin MUST be able to enter or update the URL
- AND on save, the URL MUST be persisted via `IAppConfig::setValueString('pipelinq', 'shillinq_ap_webhook_url', '<url>')`

#### Scenario 13: Validation of webhook URL format

- GIVEN the webhook URL admin input field
- WHEN the admin enters an invalid URL (e.g., `"not-a-url"`, `"ftp://example.com"`, or an empty string)
- THEN the system MUST validate and reject the entry
- AND the user MUST receive an error message: "Voer een geldige HTTPS URL in, bijv. https://shillinq.example.com/webhook"
- AND the setting MUST NOT be saved

#### Scenario 14: Valid HTTPS URLs are accepted

- GIVEN the webhook URL admin input field
- WHEN the admin enters a valid HTTPS URL (e.g., `"https://shillinq.example.com/ap-webhook"`)
- THEN the system MUST validate and accept the URL
- AND the URL MUST be persisted
- AND future expense approvals MUST use this URL for dispatch

---

### REQ-AP-005: UI: Expense list view apSyncStatus badge [MVP]

The system MUST display an `apSyncStatus` badge in the expense list view for each expense.

#### Scenario 15: Expense list shows apSyncStatus badges with color coding

- GIVEN the expense list view with 5 expenses: 2 synced, 1 pending, 1 failed, 1 with null apSyncStatus
- WHEN the page is rendered
- THEN each row MUST display a badge in the `apSyncStatus` column:
  - `synced` expense: green badge labeled "AP gesynchroniseerd"
  - `pending` expense: yellow badge labeled "AP in behandeling"
  - `failed` expense: red badge labeled "AP mislukt"
  - null apSyncStatus expense: grey badge labeled "–"
- AND the badge colors MUST match the design spec (green: `#28a745`, yellow: `#ffc107`, red: `#dc3545`, grey: `#6c757d`)
- AND clicking a badge MUST NOT navigate or trigger any action (read-only indicator)

#### Scenario 16: Expense list updates badge color in real-time after manual retry

- GIVEN an expense detail view with an expense in `apSyncStatus: "failed"`
- WHEN the admin clicks the "Opnieuw versturen" button and the dispatch succeeds
- AND they navigate back to the expense list
- THEN the badge for that expense MUST update from red (`failed`) to green (`synced`)
- AND the updated `apSyncedAt` timestamp MUST be reflected if the list displays timestamps

---

### REQ-AP-006: UI: Expense detail view Shillinq AP card [MVP]

The system MUST display a Shillinq AP section in the expense detail view when `apSyncStatus` is not null.

#### Scenario 17: Detail view shows AP status card for synced expense

- GIVEN an expense detail view for an expense with `apSyncStatus: "synced"` and `apSyncedAt: "2026-05-15T14:35:00Z"`
- WHEN the page is rendered
- THEN a "Shillinq AP" card MUST be displayed below the main expense details
- AND the card MUST show:
  - Status badge: green "AP gesynchroniseerd"
  - Sync timestamp: "Gesynchroniseerd op 15 mei 2026 om 14:35 uur"
- AND no action buttons MUST be displayed (read-only informational)

#### Scenario 18: Detail view shows AP card with retry button for failed expense

- GIVEN an expense detail view for an expense with `apSyncStatus: "failed"` and `apSyncedAt: null`
- WHEN the page is rendered
- THEN a "Shillinq AP" card MUST be displayed
- AND the card MUST show:
  - Status badge: red "AP mislukt"
  - An "Opnieuw versturen" button
- AND when the button is clicked, the system MUST trigger the manual retry flow (REQ-AP-003 Scenario 11)
- AND the button MUST be disabled during retry (in-progress state)

#### Scenario 19: Detail view shows AP card with pending message for in-flight expense

- GIVEN an expense detail view for an expense with `apSyncStatus: "pending"`
- WHEN the page is rendered
- THEN a "Shillinq AP" card MUST be displayed
- AND the card MUST show:
  - Status badge: yellow "AP in behandeling"
  - Message: "Verzending in progress, moment geduld a.u.b."
- AND no action buttons MUST be displayed

---

### REQ-AP-007: Seed data for AP sync states [MVP]

The system MUST include 5 seed `expense` objects demonstrating the three `apSyncStatus` states.

#### Scenario 20: Seed data includes all sync states

- GIVEN the pipelinq installation and `lib/Settings/pipelinq_register.json`
- WHEN the seed data is imported (via OpenRegister importer)
- THEN the following seed expenses MUST be created:
  1. `expense-ap-synced-1`: reimbursable hotel expense, synced
  2. `expense-ap-synced-2`: billable travel expense, synced
  3. `expense-ap-pending-1`: office supplies, pending
  4. `expense-ap-failed-1`: catering, failed
  5. `expense-ap-synced-3`: billable software license, synced
- AND each MUST have `status: "approved"`
- AND each MUST have realistic Dutch values for title and description (see design.md)
- AND re-importing with `force: false` MUST skip the seed objects (matched by slug)

---

## Acceptance

All REQ-AP-* requirements MUST be implemented and all Scenarios MUST pass integration testing and manual QA verification before the change is merged.
