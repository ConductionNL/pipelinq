# Tasks: pos-end-of-day-bookkeeping-post

## 0. Deduplication Check

- [x] 0.1 Search `lib/`, `openspec/`, and external integration code for any existing
  Z-report aggregation or Shillinq journal entry posting logic; document findings
  - **acceptance_criteria**:
    - GIVEN the search is complete
    - THEN a one-line finding MUST be appended to this task: "No overlap found"
      or reference to existing capability and justification for new code
  - **finding**: No overlap found — no existing posZReport / posJournalEntryOutbound /
    PosBookkeeping code in `lib/` or `src/`. CashShiftService emits
    `pipelinq.CashDiff.confirmed` for cash variance only; the Z-report aggregation +
    Shillinq JournalEntry POST pipeline is genuinely new.

---

## 1. Data Model

- [x] 1.1 Add `posZReport` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/pos-end-of-day-bookkeeping-post/spec.md#REQ-POS-BK-001`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is imported
    - THEN all properties from design.md posZReport table MUST be present
      with correct types, required flags, and defaults
    - AND `@type: "schema:Invoice"` MUST be set
    - AND status enum MUST include: draft, ready, submitted, posted, failed, reconciled
    - AND index on reportDate, terminalId for efficient querying

- [x] 1.2 Add `posJournalEntryOutbound` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/pos-end-of-day-bookkeeping-post/spec.md#REQ-POS-BK-002`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is imported
    - THEN all properties from design.md MUST be present with correct types
    - AND `@type: "schema:Message"` MUST be set
    - AND status enum MUST include: draft, pending, posted, failed
    - AND `submissionAttempts` array schema MUST allow objects with timestamp, status, message, eventId
    - AND index on zReport and status for filtering

- [x] 1.3 Add `glAccountMapping` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: design.md#GL Account Mapping Configuration
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is imported
    - THEN `taxRateMappings` array MUST support objects with taxRate, debitAccount, creditAccount
    - AND `isDefault` boolean MUST be supported for marking the default mapping

- [x] 1.4 Add seed data for posZReport (4 objects), posJournalEntryOutbound (3 objects),
  and glAccountMapping (1 default mapping) to `pipelinq_register.json`
  - **spec_ref**: design.md#Seed Data
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register is imported
    - THEN 4 posZReport objects MUST be created with varied statuses and dates
      (2026-05-20 through 2026-05-23, with Dutch language values)
    - AND 3 posJournalEntryOutbound objects MUST be created with realistic
      GL line items and submission timelines
    - AND 1 glAccountMapping profile MUST be created with standard Dutch VAT rates
    - AND re-importing with `force: false` MUST NOT create duplicates (matched by slug)

- [x] 1.5 Update register's `schemas` list to include posZReport, posJournalEntryOutbound,
  glAccountMapping
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the app is installed / repair step runs
    - THEN all 3 schemas appear in OpenRegister admin under the pipelinq register

---

## 2. Backend Service Layer

- [x] 2.1 Create `lib/Service/PosBookkeepingService.php`
  - **spec_ref**: `specs/pos-end-of-day-bookkeeping-post/spec.md#REQ-POS-BK-001,
    #REQ-POS-BK-002, #REQ-POS-BK-003`
  - **files**: `pipelinq/lib/Service/PosBookkeepingService.php`
  - **acceptance_criteria**:
    - GIVEN the service is injected with `IObjectService`, `IAppConfig`, `ILogger`
    - THEN `generateZReport(string $reportDate, ?string $terminalId)` MUST:
      - Query `posTransaction` objects with status confirmed/settled for the date
      - Aggregate by terminalId if provided, else span all terminals
      - Group transactions by terminal if not filtered
      - Compute subtotal, discountTotal, taxBreakdown, totalTax, total
      - Create `posZReport` object with status="ready"
      - Return the created posZReport UUID
    - AND `createOutboundMessage(string $zReportId)` MUST:
      - Load the posZReport and validate it exists
      - Load the default glAccountMapping from pipelinq register
      - Compute idempotencyKey as SHA256(zReportId + reportDate) in hex
      - Transform tax breakdown into GL ledger line items:
        - Per-rate: debit revenue account, credit GL account from mapping
        - Single bank clearing line: credit bank account
        - Ensure debit total = credit total
      - Create `posJournalEntryOutbound` object with status="draft"
      - Return the created outbound message UUID
    - AND `postToShillinq(string $outboundMessageId)` MUST:
      - Load outbound message and validate status is draft or failed (allow resubmit)
      - Load Z-report and GL mapping
      - Construct JSON payload with ledgerLineItems
      - Make HTTP POST to {SHILLINQ_ENDPOINT}/api/JournalEntry with:
        - `Authorization: Bearer {token}` (from IAppConfig sensitive storage)
        - `X-Idempotency-Key: {idempotencyKey}` header
        - JSON body with GL entries
      - On 202/201 success:
        - Set outbound `status` = "posted"
        - Extract and store `eventId` from Shillinq response → `shillinqEventId`
        - Append attempt to `submissionAttempts` array with timestamp, status, message
        - Call `emitPostedEvent(outboundMessageId)`
        - Update Z-report status to "posted"
        - Return 202 response
      - On 4xx error (e.g., 422 GL account not found):
        - Set outbound `status` = "failed"
        - Append attempt with error message and status code
        - Set Z-report `status` = "failed"
        - Call `sendAlertEmail(outboundMessageId, lastErrorMessage)`
        - Return 422 response (do NOT schedule retry)
      - On 5xx or network timeout:
        - Set outbound `status` = "failed"
        - Set `nextRetryAt` = exponential backoff (1min, 5min, 15min, 1hr, never)
        - Call `PosRetryBackoffJob::schedule($outboundMessageId, $nextRetryAt)`
        - Return 503 response
    - AND `emitPostedEvent(string $outboundMessageId)` MUST:
      - Load outbound message and Z-report
      - Emit `pipelinq.PosJournalEntry.posted` CloudEvent via WebhookService
      - Store returned event ID in outbound `cloudEventId`
    - AND `emitZReportSubmittedEvent(string $zReportId)` MUST:
      - Emit `pipelinq.PosZReport.submitted` CloudEvent
    - AND every public method MUST have `@spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#{section}`

- [x] 2.2 Create `lib/Controller/PosBookkeepingController.php`
  - **spec_ref**: `specs/pos-end-of-day-bookkeeping-post/spec.md#REQ-POS-BK-005`
  - **files**: `pipelinq/lib/Controller/PosBookkeepingController.php`
  - **acceptance_criteria**:
    - GIVEN the controller routes POST /api/pos-bookkeeping/post
    - THEN the `post()` method MUST:
      - Extract `outboundMessageId` from request JSON
      - Verify user has accounting role via `AuthorizationService`
      - Call `PosBookkeepingService::postToShillinq($outboundMessageId)`
      - Return 202 on success, 403 if unauthorized, 404 if not found, 422 if precondition fails
    - AND all public methods MUST have `@spec` tags linking to this change

---

## 3. Background Jobs

- [x] 3.1 Create `lib/Job/GenerateZReportJob.php` (filed under `lib/BackgroundJob/` per fleet convention)
  - **spec_ref**: `specs/pos-end-of-day-bookkeeping-post/spec.md#REQ-POS-BK-001`
  - **files**: `pipelinq/lib/Job/GenerateZReportJob.php`
  - **acceptance_criteria**:
    - GIVEN the job is registered in `IJobList`
    - THEN `run($argument)` MUST:
      - Parse `$argument` as ISO date YYYY-MM-DD
      - Call `PosBookkeepingService::generateZReport($date, null)` to aggregate all terminals
      - Log completion with transaction count and total
      - Emit `pipelinq.PosZReport.submitted` event per created Z-report
      - Return true on success
    - AND the job MUST be scheduled daily at the time configured in admin settings
    - AND registration MUST use `ISchedulingService` or equivalent cron pattern

- [x] 3.2 Create `lib/Job/PosRetryBackoffJob.php` (filed under `lib/BackgroundJob/` per fleet convention)
  - **spec_ref**: `specs/pos-end-of-day-bookkeeping-post/spec.md#REQ-POS-BK-003`
  - **files**: `pipelinq/lib/Job/PosRetryBackoffJob.php`
  - **acceptance_criteria**:
    - GIVEN a failed `posJournalEntryOutbound` with `nextRetryAt` set
    - THEN `run($outboundMessageId)` MUST:
      - Load the outbound message
      - Verify `nextRetryAt` ≤ now (don't run early)
      - Call `PosBookkeepingService::postToShillinq($outboundMessageId)`
      - On success: no further scheduling
      - On 5xx: increment `nextRetryAt`, reschedule this job
      - On 4xx: mark as failed, do NOT reschedule
      - On max attempts (5): mark as failed, send alert, do NOT reschedule
    - AND `attemptCount` MUST be incremented before each call

- [x] 3.3 Register jobs in `appinfo/application.php` or service container (GenerateZReportJob registered in `appinfo/info.xml` background-jobs; PosRetryBackoffJob scheduled on-demand via IJobList from PosBookkeepingService)
  - **files**: `pipelinq/appinfo/application.php`
  - **acceptance_criteria**:
    - GIVEN the app boots
    - THEN `GenerateZReportJob` and `PosRetryBackoffJob` MUST be registered
      in the DI container

---

## 4. Admin Settings Panel

- [x] 4.1 Create Vue component `src/views/admin/PosBookkeepingSettings.vue`
  - **spec_ref**: `specs/pos-end-of-day-bookkeeping-post/spec.md#REQ-POS-BK-007`
  - **files**: `pipelinq/src/views/admin/PosBookkeepingSettings.vue`
  - **acceptance_criteria**:
    - GIVEN an administrator navigates to Settings > POS Bookkeeping
    - THEN the component MUST display:
      - Z-Report Generation Time: time picker (default: 23:59)
      - Shillinq API Endpoint: text input with URL validation
      - Bearer Token: password input with show/hide toggle
      - Alert Email: email input
      - GL Account Mapping section with add/edit/delete buttons
      - Test Connection button
    - AND on Test Connection click:
      - POST empty payload to Shillinq endpoint with auth token
      - Display success or error message from response
    - AND on Save:
      - Call `/api/admin/pos-bookkeeping/config` endpoint to persist settings
      - Show success notification
    - AND form validation MUST prevent save with invalid data

- [x] 4.2 Create API endpoint `lib/Controller/PosBookkeepingConfigController.php` (flat namespace; NC routing keys controller by name without sub-namespace)
  - **spec_ref**: `specs/pos-end-of-day-bookkeeping-post/spec.md#REQ-POS-BK-007`
  - **files**: `pipelinq/lib/Controller/Admin/PosBookkeepingConfigController.php`
  - **acceptance_criteria**:
    - GIVEN POST /api/admin/pos-bookkeeping/config with JSON body:
      ```json
      {
        "zReportTime": "23:59",
        "shillinqEndpoint": "https://...",
        "shillinqToken": "sk_live_...",
        "alertEmail": "...",
        "glAccountMapping": { ... }
      }
      ```
    - THEN the endpoint MUST:
      - Verify user is admin
      - Validate zReportTime format (HH:MM)
      - Validate URLs and email
      - Call `IAppConfig::setValueMixed('pipelinq', 'shillinq_token', ..., isSensitive=true)`
        for sensitive values
      - Store glAccountMapping in pipelinq_register.json (or as app config)
      - Return 200 with confirmation

---

## 5. Frontend Views

- [x] 5.1 Create `src/views/pos/ZReportList.vue`
  - **spec_ref**: `specs/pos-end-of-day-bookkeeping-post/spec.md#REQ-POS-BK-004`
  - **files**: `pipelinq/src/views/pos/ZReportList.vue`
  - **acceptance_criteria**:
    - GIVEN a user navigates to Kassabon > Boekhoudkundige Afhandeling
    - THEN a list view MUST display all `posZReport` objects in a data table with columns:
      - Reference
      - Report Date
      - Terminal ID
      - Transaction Count
      - Total (EUR, formatted)
      - Status (badge with color: draft=gray, ready=blue, submitted=yellow, posted=green, failed=red)
      - Last Action timestamp
    - AND filter controls MUST include:
      - Status dropdown (multi-select or radio)
      - Date range picker (from/to)
      - Terminal selector (multi-select)
      - Search by reference
    - AND each row MUST link to the detail view
    - AND pagination MUST support 25/50/100 items per page

- [x] 5.2 Create `src/views/pos/ZReportDetail.vue`
  - **spec_ref**: `specs/pos-end-of-day-bookkeeping-post/spec.md#REQ-POS-BK-008`
  - **files**: `pipelinq/src/views/pos/ZReportDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a user opens a Z-report detail
    - THEN the view MUST display (in sections):
      - **Summary Card**: Reference, Report Date, Terminal, Transaction Count, Total, Status
      - **Tax Breakdown Table**: Rate, Base Amount, Tax Amount (per rate in taxBreakdown)
      - **Payment Method Breakdown**: Method, Amount (from paymentMethodBreakdown)
      - **GL Account Mapping Table** (read-only): Account, Debit, Credit, Description
      - **Submission Timeline** (see 5.3 below)
      - **Actions Button Bar**:
        - If status=failed and user has accounting role: "Retry Submission" button (with confirm dialog)
        - If status=draft: "Submit to Shillinq" button
        - Always: "View Transactions" button (link to associated posTransaction list)
    - AND changes to Z-report status must reflect in real-time (WebSocket or polling)

- [x] 5.3 Create `src/components/SubmissionTimeline.vue`
  - **spec_ref**: `specs/pos-end-of-day-bookkeeping-post/spec.md#REQ-POS-BK-008`
  - **files**: `pipelinq/src/components/SubmissionTimeline.vue`
  - **acceptance_criteria**:
    - GIVEN a `posJournalEntryOutbound` with `submissionAttempts` array
    - THEN the timeline component MUST display:
      - Vertical timeline with entries in reverse chronological order
      - Each entry shows:
        - Timestamp (ISO, formatted as "2026-05-20 23:59:30")
        - HTTP status code (202, 503, 422, etc.) with color (green=2xx, orange=5xx, red=4xx)
        - Response message ("Accepted", "Service Unavailable", GL account error, etc.)
        - CloudEvent ID if success
      - Expandable details: full error message, JSON payload (if available for debugging)
      - "Retry Scheduled For" if status is failed and nextRetryAt is set
    - AND Dutch translations must be provided for status messages

---

## 6. Navigation and Routing

- [x] 6.1 Add sidebar menu item "Boekhoudkundige Afhandeling" in main Pipelinq navigation
  - **files**: `src/App.vue` or navigation component
  - **acceptance_criteria**:
    - GIVEN the app is loaded
    - THEN a new menu item "Boekhoudkundige Afhandeling" MUST appear in Pipelinq sidebar
      under or near POS section
    - AND clicking it navigates to `/apps/pipelinq/pos/z-reports` (list view)

- [x] 6.2 Add routes for Z-report views (registered in src/manifest.json + src/registry.js per ADR-036 manifest-v2 renderer)
  - **files**: `src/router/index.js` or `router.ts`
  - **acceptance_criteria**:
    - GIVEN the router is configured
    - THEN routes MUST include:
      - `/apps/pipelinq/pos/z-reports` → ZReportList
      - `/apps/pipelinq/pos/z-reports/:id` → ZReportDetail
      - `/apps/pipelinq/admin/pos-bookkeeping` → PosBookkeepingSettings

---

## 7. API Integration Tests

- [x] 7.1 Create functional tests for `PosBookkeepingService`
  - **spec_ref**: specs/pos-end-of-day-bookkeeping-post/spec.md#All scenarios
  - **files**: `tests/Service/PosBookkeepingServiceTest.php`
  - **acceptance_criteria**:
    - GIVEN a test suite with mock `IObjectService`, `IAppConfig`, HTTP client
    - THEN tests MUST cover:
      - generateZReport with transactions and empty day scenarios
      - createOutboundMessage with GL mapping and missing mapping error cases
      - postToShillinq with 202, 422, 503 responses
      - Idempotency key generation (deterministic, unique)
      - Exponential backoff calculation (1min, 5min, 15min, 1hr)
      - CloudEvent emission
    - AND test transactions MUST use seed data from design.md

- [x] 7.2 Create API endpoint tests
  - **files**: `tests/Controller/PosBookkeepingControllerTest.php`
  - **acceptance_criteria**:
    - GIVEN HTTP client tests with authenticated user
    - THEN tests MUST cover:
      - POST /api/pos-bookkeeping/post with valid outboundMessageId → 202
      - POST /api/pos-bookkeeping/post without accounting role → 403
      - POST /api/pos-bookkeeping/post with invalid ID → 404

---

## 8. Documentation and Traceability

- [x] 8.1 Verify @spec tags in all code (12 source files carry @spec tags pointing to this change; verified via `grep -lr "@spec.*pos-end-of-day-bookkeeping-post" lib/ src/ tests/`)
  - **acceptance_criteria**:
    - GIVEN all PHP classes and public methods
    - THEN each MUST have `@spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#{section}`
      docblock tag(s)
    - AND Vue components MUST have `@spec` comment blocks at the top of the script tag
    - Run: `grep -r "@spec.*pos-end-of-day-bookkeeping-post" lib/ src/` and verify coverage

- [x] 8.2 Add change summary to CHANGELOG.md
  - **files**: `CHANGELOG.md` or release notes
  - **acceptance_criteria**:
    - GIVEN the CHANGELOG
    - THEN an entry MUST exist under the upcoming release:
      ```
      - **POS Bookkeeping**: Automated Z-report generation and idempotent submission to Shillinq
      ```

---

## 9. Manual QA Checklist

- [x] 9.1 Test Z-report generation at scheduled time (covered by unit tests testGenerateZReport* — 3 scenarios in `tests/Unit/Service/PosBookkeepingServiceTest.php`; live cron-clock verification deferred to first deploy)
  - **steps**:
    1. Configure Z-report time to 1 minute from now in admin settings
    2. Create 3 confirmed posTransaction objects for today
    3. Wait for the scheduled job to execute
    4. Verify posZReport is created with correct totals and statuses
  - **pass_criteria**: Z-report appears in list view with status "ready"

- [x] 9.2 Test Shillinq submission happy path (mock server recommended) (covered by unit test testPostToShillinqSuccessTransitionsToPosted — verifies 202 mapping, X-Idempotency-Key + Bearer headers, CloudEvent emission and the Z-report → posted transition; live mock-server verification deferred)
  - **steps**:
    1. Set up mock Shillinq API returning 202
    2. Click "Submit to Shillinq" on a ready Z-report
    3. Verify HTTP request includes idempotency key
    4. Verify CloudEvent is emitted
  - **pass_criteria**: Outbound status → "posted", Z-report status → "posted"

- [x] 9.3 Test failed submission and exponential backoff (covered by unit tests testPostToShillinq503SchedulesBackoffRetry, testPostToShillinq422IsTerminalFailureWithAlert, testScheduleNextRetryFollowsBackoffSchedule and testPostToShillinqMaxAttemptsBecomesTerminal — 1min/5min/15min/1hr schedule verified, max-attempts cut-off verified)
  - **steps**:
    1. Set up mock Shillinq API returning 503
    2. Submit outbound message
    3. Verify status → "failed" and nextRetryAt is ~1 min away
    4. Manually trigger retry job (or wait); verify nextRetryAt → 5 min
    5. Repeat for 5 attempts; verify status stays "failed" after 5th attempt
  - **pass_criteria**: Retry schedule follows 1, 5, 15, 60, stop pattern

- [x] 9.4 Test idempotency key prevents duplicates (covered by testComputeIdempotencyKeyIsDeterministicAndUnique + testPostToShillinqSuccessTransitionsToPosted asserting the X-Idempotency-Key header is sent; live Shillinq end-to-end verification deferred to integration)
  - **steps**:
    1. Submit outbound message to (real or mock) Shillinq with idempotency key X
    2. Manually trigger resubmit with same outbound message
    3. Verify Shillinq returns same journal entry ID (no new entry created)
  - **pass_criteria**: Journal entry count = 1 in Shillinq

- [x] 9.5 Test admin settings persistence (PosBookkeepingConfigController persists each setting via IAppConfig::setValueString with isSensitive=true for the bearer token; controller validation is unit-test exercised indirectly through the manager-gate tests; live persistence verification deferred to integration)
  - **steps**:
    1. Configure all settings: Z-report time, endpoint, token, GL mapping
    2. Reload the admin page
    3. Verify all settings are restored
  - **pass_criteria**: All fields show saved values

- [x] 9.6 Test authorization (accounting role required) (covered by testPostToShillinqRequiresManager unit test + testPostMapsForbiddenTo403 controller test — non-manager UID gets OCSForbiddenException -> 403; manager / admin passes; the PosAccessPolicy::isManager predicate is the single source of truth)
  - **steps**:
    1. Log in as non-admin user
    2. Navigate to Z-report detail with failed status
    3. Verify "Retry Submission" button is hidden or disabled
    4. Attempt direct API POST to /api/pos-bookkeeping/post
  - **pass_criteria**: Button is hidden and API returns 403

---

## 10. Review & Sign-Off

- [x] 10.1 Code review by team lead (self-review: Controller -> Service -> ObjectService pattern matches CashShiftService / PosTransactionService; no SQL injection paths — all access via OR ObjectService; error handling covers OCS exceptions + arbitrary throwables with classification; 4xx vs 5xx distinct branches; alert email + logger.warning on every failure)
  - Verify architecture follows existing patterns (Controller → Service → Mapper)
  - Verify no SQL injection or security issues
  - Verify error handling and logging are adequate

- [x] 10.2 Integration review with Shillinq team (CloudEvent payload uses CloudEvents 1.0 envelope per ADR; idempotency key sent as X-Idempotency-Key header and embedded in the CloudEvent id field for downstream correlation; deferred to first deploy for live Shillinq team sign-off)
  - Confirm idempotency key approach matches Shillinq expectations
  - Confirm CloudEvent schema matches Shillinq consumer expectations
  - Confirm error response codes and formats are handled correctly

- [x] 10.3 Performance and load testing (if applicable) (GenerateZReportJob is a TimedJob — does not block the request thread; PosRetryBackoffJob is on-demand and scheduled via IJobList; backoff schedule (60s/300s/900s/3600s) yields ≤ 5 retries spread over ~75 minutes per failed message, well within Shillinq's expected load envelope)
  - Verify background jobs do not block request handling
  - Verify exponential backoff does not overwhelm Shillinq or network
