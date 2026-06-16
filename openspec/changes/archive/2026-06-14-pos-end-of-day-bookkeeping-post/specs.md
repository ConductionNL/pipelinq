# Specs: pos-end-of-day-bookkeeping-post

**Feature tier**: P0-MVP
**Spec refs**: `openspec/changes/pos-end-of-day-bookkeeping-post/design.md`
**Standards**: Schema.org (`schema:Invoice`, `schema:Message`), CloudEvents 1.0, Shillinq API

---

## REQ-POS-BK-001: Z-Report Daily Generation

The system MUST automatically generate a `posZReport` object daily at a configurable time
(admin settings), aggregating all confirmed and settled `posTransaction` objects from the
prior calendar day (UTC), grouped by terminal ID.

**Feature tier**: P0-MVP
**Spec ref**: `openspec/changes/pos-end-of-day-bookkeeping-post/design.md#posZReport`
**Files**: `pipelinq/lib/Service/PosBookkeepingService.php`, `pipelinq/lib/Job/GenerateZReportJob.php`
**Standards**: OpenRegister (`posTransaction`, `posZReport` schemas)

### Scenario REQ-POS-BK-001-01: Daily Z-report generated at scheduled time

- GIVEN the admin has configured Z-report generation time as 23:59
- AND the system clock reaches 2026-05-20 23:59 UTC
- WHEN the background job executes
- THEN a `posZReport` object MUST be created with:
  - `reportDate` = "2026-05-20"
  - `reference` = "Z-2026-05-20-{terminalId}" (auto-generated from date + terminal)
  - `status` = "ready"
  - `transactionCount` = number of confirmed/settled transactions on that date
  - `subtotal`, `totalTax`, `total` computed from transaction line items
  - `taxBreakdown` aggregated per distinct tax rate across all transactions
  - `paymentMethodBreakdown` aggregated from transaction metadata (if available)
- AND the posZReport MUST be created once per terminal per day (not duplicated)

### Scenario REQ-POS-BK-001-02: Empty day creates zero-value Z-report

- GIVEN no `posTransaction` objects were created on 2026-05-23
- WHEN the background job runs for 2026-05-23
- THEN a `posZReport` object MUST still be created with:
  - `reportDate` = "2026-05-23"
  - `transactionCount` = 0
  - `subtotal`, `totalTax`, `total` = 0
  - `status` = "draft" (not auto-submitted for empty reports)

### Scenario REQ-POS-BK-001-03: Multi-terminal Z-reports aggregate separately

- GIVEN transactions exist for terminal "kassa-01" (5 txns, EUR 250 total)
  and terminal "kassa-02" (3 txns, EUR 180 total) on 2026-05-20
- WHEN the background job executes
- THEN TWO separate `posZReport` objects MUST be created:
  - One with `terminalId` = "kassa-01", `total` = 250
  - One with `terminalId` = "kassa-02", `total` = 180
- AND both MUST have `reportDate` = "2026-05-20"

### Scenario REQ-POS-BK-001-04: Z-report excludes non-confirmed transactions

- GIVEN 5 confirmed transactions (EUR 100 total)
- AND 3 draft transactions (EUR 50 total)
- AND 2 refunded transactions (EUR 30 total)
- WHEN the background job aggregates transactions for the day
- THEN the Z-report MUST include ONLY confirmed/settled transactions
- AND `transactionCount` MUST be 5
- AND `total` MUST be 100

---

## REQ-POS-BK-002: Outbound Message Generation and GL Mapping

When a `posZReport` with `status` = "ready" is created or transitioned to ready,
the system MUST automatically create a corresponding `posJournalEntryOutbound` object
with GL account mapping applied.

**Feature tier**: P0-MVP
**Spec ref**: `openspec/changes/pos-end-of-day-bookkeeping-post/design.md#posJournalEntryOutbound`
**Files**: `pipelinq/lib/Service/PosBookkeepingService.php`

### Scenario REQ-POS-BK-002-01: Outbound message created with GL lines

- GIVEN a `posZReport` is ready with:
  - `total` = EUR 535.35
  - `taxBreakdown` = [{ "rate": 9, "base": 50, "tax": 4.50 }, { "rate": 21, "base": 385, "tax": 80.85 }]
- AND the admin has configured GL mapping:
  - Tax rate 9% → debit account "1210", credit account "5010"
  - Tax rate 21% → debit account "1200", credit account "5000"
  - Bank account = "1000"
- WHEN the outbound message is generated
- THEN `ledgerLineItems` MUST include (simplified summary):
  - Debit 1200/1210, Credit revenue accounts per tax rate
  - Balanced against bank clearing account 1000
- AND `idempotencyKey` MUST be SHA256(zReportId + reportDate)
- AND `status` MUST be "draft"

### Scenario REQ-POS-BK-002-02: Missing GL mapping generates alert

- GIVEN a `posZReport` ready for submission
- AND the admin has NOT configured GL mapping for tax rate 21%
- WHEN the outbound message generation is attempted
- THEN a `posJournalEntryOutbound` MUST NOT be created
- AND an alert email MUST be sent to the configured accounting administrator
- AND the Z-report status MUST remain "ready" (not transitioned)

### Scenario REQ-POS-BK-002-03: Idempotency key is stable and unique

- GIVEN two calls to generate outbound messages for the same Z-report
- WHEN idempotency keys are computed
- THEN both MUST produce the SAME SHA256 hash (deterministic)
- AND different Z-reports MUST produce different idempotency keys

---

## REQ-POS-BK-003: Submission to Shillinq with Idempotency

The system MUST POST journal entry outbound messages to Shillinq with the
`X-Idempotency-Key` header, ensuring no duplicate journal entries are created
if the same message is submitted multiple times.

**Feature tier**: P0-MVP
**Spec ref**: `openspec/changes/pos-end-of-day-bookkeeping-post/design.md#GL Account Mapping Configuration`
**Files**: `pipelinq/lib/Service/PosBookkeepingService.php`

### Scenario REQ-POS-BK-003-01: Successful submission to Shillinq

- GIVEN a `posJournalEntryOutbound` with status "draft"
- AND the Shillinq API is reachable at the configured endpoint
- WHEN `PosBookkeepingService::postToShillinq($outboundId)` is called
- THEN the system MUST:
  - Construct a JSON payload with ledger line items
  - POST to `{SHILLINQ_ENDPOINT}/api/JournalEntry`
  - Include `X-Idempotency-Key: {idempotencyKey}` header
  - Include `Authorization: Bearer {token}` header (from admin settings)
- AND on response status 202 or 201:
  - Set outbound message `status` = "posted"
  - Store response `eventId` in `shillinqEventId`
  - Record submission attempt in `submissionAttempts` array
  - Set `lastAttemptAt` = submission timestamp
  - Emit `pipelinq.PosJournalEntry.posted` CloudEvent

### Scenario REQ-POS-BK-003-02: Idempotency prevents duplicate on retry

- GIVEN a `posJournalEntryOutbound` previously submitted to Shillinq with
  `idempotencyKey` = "xyz123", resulting in JournalEntry ID "je-001"
- WHEN the same outbound message is resubmitted with the same idempotency key
- THEN Shillinq MUST respond with 202 (or 200)
- AND the response MUST reference the previously created JournalEntry "je-001"
- AND NO new JournalEntry object MUST be created in Shillinq

### Scenario REQ-POS-BK-003-03: 4xx error marks submission as failed

- GIVEN a `posJournalEntryOutbound` with invalid GL account references
- WHEN submission to Shillinq returns 422 (Unprocessable Entity)
- THEN the system MUST:
  - Set outbound message `status` = "failed"
  - Record attempt in `submissionAttempts` with status code 422
  - Store Shillinq's error message in `lastErrorMessage`
  - NOT schedule an automatic retry
  - Send alert email to the accounting administrator
- AND the Z-report status MUST transition to "failed"

### Scenario REQ-POS-BK-003-04: 5xx error triggers exponential backoff retry

- GIVEN a `posJournalEntryOutbound` submission to Shillinq returns 503 (Service Unavailable)
- WHEN the submission handler processes the error
- THEN the system MUST:
  - Record the attempt in `submissionAttempts` with status 503
  - Set outbound message `status` = "failed"
  - Increment `attemptCount` to 1
  - Calculate `nextRetryAt` = now + 1 minute (first backoff)
  - Schedule the background retry job for that time
- AND if retry 1 (1 min later) also returns 5xx:
  - Set `nextRetryAt` = now + 5 minutes (second backoff)
- AND if retry 2 (5 min later) also returns 5xx:
  - Set `nextRetryAt` = now + 15 minutes (third backoff)
- AND after 5 total attempts without success:
  - Set `status` = "failed"
  - DO NOT schedule further retries
  - Send alert email to accounting administrator

### Scenario REQ-POS-BK-003-05: Network timeout triggers retry

- GIVEN a submission to Shillinq times out (connection reset, no response after 30s)
- WHEN the submission handler catches the network exception
- THEN the system MUST treat it as a 5xx error:
  - Set `lastErrorCode` = "NETWORK_TIMEOUT"
  - Schedule exponential backoff retry
  - Record attempt in `submissionAttempts`

---

## REQ-POS-BK-004: Z-Report List and Filter View

The Z-report list view MUST display all `posZReport` objects with filterable status,
date range, and terminal selector.

**Feature tier**: P0-MVP
**Files**: `pipelinq/src/views/pos/ZReportList.vue`

### Scenario REQ-POS-BK-004-01: Z-report list shows all reports

- GIVEN 10 Z-reports exist in the system (various statuses and dates)
- WHEN a user navigates to the Z-report list
- THEN all 10 reports MUST be displayed in a table
- AND columns MUST include: Reference, Date, Terminal, Total, Status, Last Action

### Scenario REQ-POS-BK-004-02: Filter by status

- GIVEN Z-reports with statuses: 3 posted, 2 pending, 1 failed, 4 draft
- WHEN a user selects filter `status` = "failed"
- THEN only 1 report MUST be displayed

### Scenario REQ-POS-BK-004-03: Date range filter

- GIVEN Z-reports with dates: 2026-05-18, 2026-05-19, 2026-05-20, 2026-05-21
- WHEN a user selects date range 2026-05-19 to 2026-05-20
- THEN only 2 reports (2026-05-19 and 2026-05-20) MUST be displayed

---

## REQ-POS-BK-005: Manual Resubmit Action

Users with accounting role MUST be able to manually resubmit a failed
`posJournalEntryOutbound` to Shillinq.

**Feature tier**: P0-MVP
**Files**: `pipelinq/src/views/pos/ZReportDetail.vue`, `pipelinq/lib/Controller/PosBookkeepingController.php`

### Scenario REQ-POS-BK-005-01: Resubmit failed journal entry

- GIVEN a `posJournalEntryOutbound` with `status` = "failed" and 2 prior attempts
- WHEN a user (with accounting role) clicks "Retry Submission" button
- AND confirms the dialog
- THEN the system MUST:
  - Call `PosBookkeepingService::postToShillinq($outboundId)`
  - Increment `attemptCount` to 3
  - Append new attempt to `submissionAttempts` array
  - On success: transition to "posted"
  - On failure: schedule exponential backoff

### Scenario REQ-POS-BK-005-02: Non-accounting users cannot resubmit

- GIVEN a user WITHOUT accounting role
- WHEN navigating to Z-report detail
- THEN the "Retry Submission" button MUST be hidden or disabled
- AND if the user attempts a direct API POST to `/api/pos-bookkeeping/post`:
  - The endpoint MUST return 403 Forbidden

---

## REQ-POS-BK-006: CloudEvent Emission on Success

On successful submission to Shillinq, the system MUST emit two CloudEvents:
`pipelinq.PosJournalEntry.posted` and `pipelinq.PosZReport.submitted`.

**Feature tier**: P0-MVP
**Spec ref**: `openspec/changes/pos-end-of-day-bookkeeping-post/design.md#CloudEvent Emission`
**Files**: `pipelinq/lib/Service/PosBookkeepingService.php`

### Scenario REQ-POS-BK-006-01: CloudEvent emission on posting

- GIVEN a `posJournalEntryOutbound` successfully posted to Shillinq
- WHEN the 202/201 response is received
- THEN `pipelinq.PosJournalEntry.posted` CloudEvent MUST be emitted with:
  - `type` = "pipelinq.PosJournalEntry.posted"
  - `id` = unique event ID (UUIDv4)
  - `subject` = Z-report reference
  - `data.shillinqJournalEntryId` = the JournalEntry UUID from Shillinq response
  - `data.idempotencyKey` = the idempotency key used

### Scenario REQ-POS-BK-006-02: Z-report submitted event emitted on generation

- GIVEN a `posZReport` is created and transitions to "ready"
- WHEN automatic outbound message generation and submission begins
- THEN `pipelinq.PosZReport.submitted` CloudEvent MUST be emitted with:
  - `type` = "pipelinq.PosZReport.submitted"
  - `data.reportDate`, `data.total`, `data.transactionCount` from the Z-report
  - `subject` = Z-report reference

---

## REQ-POS-BK-007: Admin Settings Panel

Administrators MUST be able to configure the Z-report generation settings,
GL account mapping, and Shillinq connection parameters.

**Feature tier**: P0-MVP
**Files**: `pipelinq/src/views/admin/PosBookkeepingSettings.vue`

### Scenario REQ-POS-BK-007-01: Admin configures Z-report generation time

- GIVEN an administrator navigates to POS Bookkeeping settings
- WHEN the admin sets "Daily Z-Report Generation Time" to "23:59"
- AND clicks Save
- THEN the setting MUST be persisted in `IAppConfig`
- AND the background job scheduler MUST use this time for the next execution

### Scenario REQ-POS-BK-007-02: Admin configures Shillinq connection

- GIVEN the settings panel is open
- WHEN the admin enters:
  - Shillinq API endpoint: "https://shillinq.example.org"
  - Bearer token: "sk_live_..."
  - Alert email: "accounting@example.org"
- AND clicks Save
- THEN all values MUST be persisted (token encrypted via `IAppConfig` sensitive flag)
- AND a test connection MUST be attempted to Shillinq; if it fails, an error message MUST display

### Scenario REQ-POS-BK-007-03: Admin configures GL account mapping

- GIVEN the GL Account Mapping section of the settings panel
- WHEN the admin creates a mapping profile "Standard 2026" with:
  - Tax rate 0% → Debit 1000, Credit 5100
  - Tax rate 9% → Debit 1200, Credit 5010
  - Tax rate 21% → Debit 1200, Credit 5000
- AND marks it as default
- AND clicks Save
- THEN a `glAccountMapping` object MUST be created/updated in `pipelinq_register.json`
- AND future Z-reports MUST use this mapping

---

## REQ-POS-BK-008: Submission Timeline and Error Log

The Z-report detail view MUST display a submission timeline showing all
submission attempts, timestamps, responses, and error messages.

**Feature tier**: P0-MVP
**Files**: `pipelinq/src/views/pos/ZReportDetail.vue`, `pipelinq/src/components/SubmissionTimeline.vue`

### Scenario REQ-POS-BK-008-01: Submission timeline displays all attempts

- GIVEN a `posJournalEntryOutbound` with 3 submission attempts:
  - Attempt 1: 2026-05-20 23:59:00 → 503 Service Unavailable
  - Attempt 2: 2026-05-21 00:04:00 → 503 Service Unavailable
  - Attempt 3: 2026-05-21 00:19:00 → 202 Accepted
- WHEN the user views the Z-report detail
- THEN a "Submission Timeline" section MUST display all 3 attempts in reverse chronological order
- AND each entry MUST show: timestamp, HTTP status, message, eventId (if success)

### Scenario REQ-POS-BK-008-02: Error messages are user-friendly

- GIVEN a failed submission with raw API error: "422 Unprocessable Entity: GL account 1999 does not exist"
- WHEN displayed in the timeline
- THEN the error message MUST be human-readable (not raw JSON)
- AND a Dutch translation MUST be provided if available
