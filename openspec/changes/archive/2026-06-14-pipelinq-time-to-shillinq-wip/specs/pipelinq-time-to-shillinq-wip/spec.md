---
status: draft
---

# Spec: Cross-app: Time → shillinq WIP Ledger

## Purpose

Define the requirements for the one-way event integration that propagates approved pipelinq time entries to shillinq's billable WIP (Work In Progress) balance. This spec covers approval event capture, WIP event dispatch, sync status tracking on `timeEntry`, failure handling, administrator configuration, and visibility of sync state in the time entry views.

**Main ADR refs**: [adr-000-data-model.md](../../../../architecture/adr-000-data-model.md), [adr-001-international-first-dutch-mapping.md](../../../../architecture/adr-001-international-first-dutch-mapping.md)
**Feature tier**: P0-must
**Demand evidence**: cross-app contract
**Depends on**: `time-approval-workflow` (provides `TimeEntryApprovedEvent`), `time-entry-core` (provides `timeEntry` schema), `billable-categories-and-tags` (provides `billingCategory` field on `timeEntry`)

---

## REQ-WIP-001: WIP event dispatch on time entry approval

When a time entry is approved, the system MUST dispatch a CloudEvents-formatted WIP ledger event to shillinq's configured webhook endpoint. The event MUST be dispatched within 5 seconds of the approval state transition.

### Scenario: WIP event dispatched on approval

- GIVEN a `timeEntry` with `status: pending_approval` and a configured `shillinq_wip_webhook_url`
- WHEN `time-approval-workflow` approves the entry and emits `TimeEntryApprovedEvent`
- THEN `TimeApprovalListener` MUST receive the event
- AND `wipSyncStatus` MUST be set to `pending` on the `timeEntry` before dispatch starts
- AND `ShillinqWipService` MUST construct a CloudEvents payload with `type: nl.conduction.pipelinq.time.approved`
- AND the payload MUST be dispatched to `shillinq_wip_webhook_url` via `WebhookService::dispatchEvent()`
- AND on successful HTTP delivery `wipSyncStatus` MUST be set to `synced` and `wipSyncedAt` to the current UTC ISO 8601 timestamp

### Scenario: WIP event payload contains required fields

- GIVEN a `timeEntry` is approved with `hours: 2.5`, linked to a `billingCategory`, `client`, and `lead`
- WHEN `ShillinqWipService` constructs the WIP event payload
- THEN the `data` object MUST contain: `timeEntryId`, `hours`, `billingCategoryId`, `clientId`, `leadId`, `approvedBy`, `approvedAt`
- AND `hours` MUST be the numeric value from the `timeEntry` (not a string)
- AND all UUID reference fields MUST be string UUIDs matching the source object `uuid` values

### Scenario: Missing webhook URL prevents dispatch without error

- GIVEN `shillinq_wip_webhook_url` is not configured in app settings
- WHEN a `timeEntry` is approved
- THEN NO event MUST be dispatched
- AND `wipSyncStatus` MUST remain null
- AND NO admin notification MUST be sent (unconfigured is a valid initial state, not a failure)

### Scenario: Dispatch is idempotent for already-synced entries

- GIVEN a `timeEntry` with `wipSyncStatus: synced`
- WHEN `TimeEntryApprovedEvent` is received again for the same entry
- THEN `TimeApprovalListener` MUST skip dispatch
- AND `wipSyncStatus` MUST remain `synced`
- AND `wipSyncedAt` MUST NOT be overwritten

---

## REQ-WIP-002: Sync status tracking on timeEntry

The `timeEntry` schema MUST be extended with `wipSyncStatus` and `wipSyncedAt` fields. These fields reflect the current state of the WIP sync for each approved entry and MUST be updated atomically with each dispatch attempt outcome.

### Scenario: Status transitions on successful dispatch

- GIVEN a `timeEntry` with `wipSyncStatus: null`
- WHEN the approval event is received and dispatch begins
- THEN `wipSyncStatus` MUST be set to `pending` before `WebhookService` is called
- AND after successful delivery `wipSyncStatus` MUST become `synced`
- AND `wipSyncedAt` MUST be set to the UTC ISO 8601 timestamp of successful delivery

### Scenario: Status transitions on failed dispatch

- GIVEN `WebhookService` exhausts all retry attempts for a WIP event delivery
- WHEN the final retry fails
- THEN `wipSyncStatus` MUST be set to `failed`
- AND `wipSyncedAt` MUST remain null
- AND `wipSyncStatus` MUST NOT automatically revert without an explicit admin retry action

### Scenario: Pre-existing approved entries display neutrally

- GIVEN `timeEntry` objects approved before this change was deployed have `wipSyncStatus: null`
- WHEN these entries appear in the list or detail view
- THEN null `wipSyncStatus` MUST display as `–` (grey, no badge color)
- AND these entries MUST NOT automatically trigger a retroactive WIP dispatch

---

## REQ-WIP-003: Failure handling and admin notification

When all delivery retries are exhausted, the administrator MUST be notified via Nextcloud's notification system and MUST be able to manually trigger re-dispatch from the time entry detail view.

### Scenario: Admin notification on permanent sync failure

- GIVEN `WebhookService` has exhausted all retries for a WIP event
- WHEN `wipSyncStatus` is set to `failed`
- THEN a Nextcloud notification MUST be dispatched to admin users with the message: `t('pipelinq', 'WIP sync failed for time entry {title}', { title: timeEntry.title })`
- AND the notification MUST include a direct link to the time entry detail view

### Scenario: Manual retry from detail view resolves failed sync

- GIVEN a `timeEntry` with `wipSyncStatus: failed`
- WHEN the administrator clicks "Opnieuw synchroniseren" in the time entry detail view
- THEN `wipSyncStatus` MUST be set to `pending`
- AND `ShillinqWipService::dispatchWipEvent()` MUST be called for the entry
- AND on successful delivery `wipSyncStatus` MUST become `synced` and `wipSyncedAt` MUST be updated to now
- AND on renewed failure `wipSyncStatus` MUST be set to `failed` again

### Scenario: Retry button absent on synced entries

- GIVEN a `timeEntry` with `wipSyncStatus: synced`
- WHEN the time entry detail view is rendered
- THEN the "Opnieuw synchroniseren" button MUST NOT be visible
- AND the `wipSyncedAt` timestamp MUST be displayed next to the `t('pipelinq', 'WIP synced at')` label

### Scenario: Retry endpoint requires authentication

- GIVEN an unauthenticated request to `POST /api/time-entries/{uuid}/wip-retry`
- WHEN the request is received
- THEN the endpoint MUST return HTTP 401
- AND no dispatch MUST be triggered

---

## REQ-WIP-004: Administrator configuration

The administrator MUST be able to configure the shillinq WIP webhook URL in the pipelinq admin settings panel. The setting MUST be stored via Nextcloud's `IAppConfig` and used for all subsequent WIP event dispatches.

### Scenario: Configure shillinq WIP webhook URL

- GIVEN the administrator opens the pipelinq admin settings at `/settings/admin/pipelinq`
- WHEN the administrator enters a valid HTTPS URL in the "Shillinq WIP-webhook-URL" field and saves
- THEN the URL MUST be persisted via `IAppConfig::setValueString('pipelinq', 'shillinq_wip_webhook_url', $url)`
- AND `ShillinqWipService::shouldDispatch()` MUST return `true` for subsequent approval events

### Scenario: Invalid URL rejected client-side

- GIVEN the administrator enters a string that is not a valid HTTP(S) URL
- WHEN the input field loses focus or the form is submitted
- THEN a client-side validation error MUST appear next to the field
- AND the previous saved URL (if any) MUST remain in `IAppConfig` unchanged

### Scenario: Webhook delivery log accessible from admin settings

- GIVEN WIP events have been dispatched
- WHEN the administrator clicks "Bekijk webhook log" link in the admin settings
- THEN the administrator MUST be taken to the OpenRegister webhook delivery log
- AND the log MUST be pre-filtered for events with `type: nl.conduction.pipelinq.time.approved`

---

## REQ-WIP-005: WIP sync status visibility in time entry views

The time entry list and detail views MUST display `wipSyncStatus` so administrators and billing managers can identify entries that have not yet reached shillinq's WIP balance.

### Scenario: Sync status badge in time entry list

- GIVEN the time entry list contains entries with `wipSyncStatus` values: `synced`, `pending`, `failed`, and null
- WHEN the list is displayed
- THEN each row MUST include a `wipSyncStatus` badge
- AND `synced` MUST render a green badge labeled `t('pipelinq', 'WIP synced')`
- AND `pending` MUST render a yellow badge labeled `t('pipelinq', 'WIP pending')`
- AND `failed` MUST render a red badge labeled `t('pipelinq', 'WIP sync failed')`
- AND null MUST render a grey `–` placeholder (no badge text)

### Scenario: Filter list by WIP sync status

- GIVEN the time entry list view is open with `CnFacetSidebar`
- WHEN the user selects "WIP synchronisatie mislukt" in the WIP status facet
- THEN ONLY time entries with `wipSyncStatus: failed` MUST be shown
- AND the result count MUST appear next to the facet option
- AND the selected filter MUST persist in URL query params across page navigation

### Scenario: Detail view shows sync timestamp

- GIVEN a `timeEntry` with `wipSyncStatus: synced` and `wipSyncedAt: 2026-05-15T16:05:23Z`
- WHEN the time entry detail view is displayed
- THEN the Shillinq WIP sidebar section MUST show: green badge "WIP gesynchroniseerd" and timestamp "15 mei 2026, 16:05" next to the "WIP gesynchroniseerd op" label

### Scenario: No hardcoded strings in WIP status components

- GIVEN the time entry list view and detail view WIP section are rendered
- WHEN a grep is run for hardcoded Dutch strings in the component files
- THEN all user-visible strings MUST use `t('pipelinq', ...)` — zero hardcoded Dutch or English strings in template or script
