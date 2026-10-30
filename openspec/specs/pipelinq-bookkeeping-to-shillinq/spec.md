# pipelinq-bookkeeping-to-shillinq Specification

## Purpose
How pipelinq passes a POS day report on to shillinq bookkeeping, and how the day report shows whether, when and why passing it on failed. The requirements with REQ ids are synced from the archived change `2026-06-15-pipelinq-bookkeeping-to-shillinq`.

## Requirements

### Requirement: A Z-report says why and when passing it on failed

When a raise of the POS-day journal entry does not reach shillinq, the Z-report MUST store the reason (`unreachable` or `not_configured`) and the moment. A successful raise MUST clear both. The Boekhouding card MUST show the status with the reason and the time.

#### Scenario: shillinq does not respond

@e2e exclude Asserted in tests/Unit/Service/PosBookkeepingServiceTest.php and tests/vitest/zReportBookkeepingWidget.spec.js; the pixel check against board PqZRapport covers the drawn card.

- GIVEN a Z-report with takings and a shillinq connection that does not answer
- WHEN the day is passed on at 23:00 and the dispatch fails
- THEN the report stores bookkeepingFailureReason unreachable and the moment
- AND the Boekhouding card reads "Mislukt: shillinq reageerde niet om 23:00"

#### Scenario: a later pass-on succeeds

@e2e exclude Asserted in tests/Unit/Service/PosBookkeepingServiceTest.php.

- GIVEN a Z-report with a stored failure
- WHEN the day is passed on again and shillinq accepts it
- THEN the stored reason and moment are cleared

### Requirement: The Z-report's day figures are three tiles

The Z-report page MUST show the day total, the VAT and the number of transactions as three stat tiles in the side column, above the history.

#### Scenario: open a Z-report

@e2e exclude Asserted in tests/vitest/zReportDetailBoard.spec.js; the pixel check against board PqZRapport covers the tiles.

- GIVEN a Z-report with a total of € 864,00, VAT of € 97,42 and 29 transactions
- WHEN the user opens it
- THEN the side column shows the tiles Dagtotaal € 864,00, Btw € 97,42 and Transacties 29

### Requirement: The system SHALL raise the POS-day journal entry in shillinq through the ADR-019 integration registry (REQ-PBTS-001)

The system SHALL, when a `posZReport` reaches the ready-to-book state, raise a
`shillinq.JournalEntry.raise` integration message whose endpoint, authentication,
and idempotency policy are resolved from the ADR-019 integration registry, rather
than POSTing to a hard-coded `/api/JournalEntry` URL. The message SHALL carry the
Z-report business facts (date, totals, `taxBreakdown`) so shillinq builds the
journal; pipelinq SHALL NOT pre-build GL-balanced ledger lines.

#### Scenario: Ready Z-report raises a registry-mediated journal entry

- **GIVEN** a `posZReport` has reached the ready-to-book state and the shillinq integration is configured in the registry
- **WHEN** the bookkeeping consequence is triggered
- **THEN** the system MUST resolve the shillinq journal endpoint/auth from the ADR-019 integration registry
- **AND** dispatch a `shillinq.JournalEntry.raise` message containing the Z-report date, totals, and `taxBreakdown`
- **AND** MUST NOT POST to a hard-coded `/api/JournalEntry` URL
- **AND** MUST NOT include pre-mapped debit/credit GL lines in the payload

#### Scenario: Idempotency key is preserved across re-raises

- **GIVEN** a `posZReport` whose journal raise previously dispatched with idempotency key `SHA256(zReport.uuid + reportDate)`
- **WHEN** the same Z-report is raised again (retry or repair re-run)
- **THEN** the system MUST reuse the identical `SHA256(zReport.uuid + reportDate)` idempotency key
- **AND** shillinq MUST resolve the re-raise to the same journal entry (no duplicate booking)

#### Scenario: shillinq unavailable does not block the POS day

- **GIVEN** the shillinq integration is unreachable when a Z-report is ready to book
- **WHEN** the journal raise is attempted
- **THEN** the `posZReport` MUST still close as an operational record
- **AND** `bookkeepingStatus` MUST be set to `pending`
- **AND** the raise MUST be queued for retry without failing the Z-report close

### Requirement: The system SHALL keep a thin shillinq outcome projection on the Z-report (REQ-PBTS-002)

The system SHALL extend the `posZReport` schema with a `bookkeepingStatus`
(`pending` / `raised` / `failed`) field and a `shillinqJournalEntryId` field that
project the shillinq journal outcome, so operators can see whether a POS day is
booked without pipelinq re-owning the ledger.

#### Scenario: Successful raise records the shillinq journal id

- **GIVEN** a `shillinq.JournalEntry.raise` message is accepted by shillinq returning a journal id
- **WHEN** the outcome is received
- **THEN** the `posZReport` `bookkeepingStatus` MUST become `raised`
- **AND** `shillinqJournalEntryId` MUST be set to the shillinq journal id

#### Scenario: Permanent failure surfaces on the Z-report

- **GIVEN** the journal raise has exhausted its retries
- **WHEN** the final attempt fails
- **THEN** the `posZReport` `bookkeepingStatus` MUST become `failed`
- **AND** `shillinqJournalEntryId` MUST remain empty
- **AND** an administrator notification MUST be dispatched

### Requirement: The system SHALL resolve the billing entry point through the integration registry (REQ-PBTS-003)

The system SHALL resolve the "Timesheet approval" billing destination through the
ADR-019 integration registry so it points at the configured shillinq deployment,
rather than the hard-coded relative path `/index.php/apps/shillinq/`. Timesheet
approval SHALL remain a pipelinq act; only the billing destination delegates.

#### Scenario: Billing entry point points at the configured shillinq instance

- **GIVEN** the shillinq integration base URL is configured in the registry
- **WHEN** the user opens the "Timesheet approval" navigation entry
- **THEN** the destination MUST be resolved from the registry-configured shillinq URL
- **AND** MUST NOT be the hard-coded `/index.php/apps/shillinq/` path

#### Scenario: Approval stays in pipelinq

- **GIVEN** a `timeEntry` pending approval in pipelinq
- **WHEN** an approver acts on it
- **THEN** the approval MUST be recorded in pipelinq
- **AND** only the billing/invoicing consequence MUST delegate to shillinq

### Requirement: The system SHALL remove the bookkeeping navigation entries while keeping the Z-report routable (REQ-PBTS-004)

The system SHALL remove the `ZReports` ("Boekhoudkundige Afhandeling") and
`PosBookkeepingSettings` ("POS bookkeeping") entries from the `menu` array in
`src/manifest.json`, while keeping the `ZReports` (`/pos/z-reports`) and
`ZReportDetail` (`/pos/z-reports/:id`) pages routable for operational deep links.
The `PosBookkeepingSettings` page (`/admin/pos-bookkeeping`) SHALL be removed
entirely as it administers the delegated GL mapping.

#### Scenario: Bookkeeping nav entries are gone but the Z-report list deep link works

- **GIVEN** the corrected navigation
- **WHEN** the user opens the pipelinq menu
- **THEN** there MUST be no "Boekhoudkundige Afhandeling" entry and no "POS bookkeeping" admin entry
- **AND** navigating directly to `/pos/z-reports` MUST still render the Z-report list
- **AND** navigating directly to `/pos/z-reports/:id` MUST still render a Z-report detail

#### Scenario: The GL-mapping admin page is removed

- **GIVEN** the corrected navigation and pages
- **WHEN** the user navigates to `/admin/pos-bookkeeping`
- **THEN** the page MUST NOT resolve (the GL-mapping admin surface is delegated to shillinq)

### Requirement: The system SHALL no longer own a local general-ledger chart or build journal entries locally (REQ-PBTS-005)

The system SHALL remove the `glAccountMapping` schema and the persisted
`posJournalEntryOutbound` schema from pipelinq's register, and SHALL remove the
local VAT→GL journal-building logic from `PosBookkeepingService`. Existing
`glAccountMapping` and `posJournalEntryOutbound` objects SHALL NOT be deleted by
this change (no data loss); they become inert once the delegated path is live.

#### Scenario: GL chart and outbound journal schema are no longer registered

- **GIVEN** the migrated pipelinq register
- **WHEN** the register fragment `50-pos-end-of-day-bookkeeping.json` is loaded
- **THEN** the `glAccountMapping` schema MUST NOT be registered
- **AND** the `posJournalEntryOutbound` schema MUST NOT be registered as an owned record
- **AND** pre-existing `glAccountMapping` / `posJournalEntryOutbound` objects MUST NOT be deleted

#### Scenario: In-flight outbound entries are migrated idempotently

- **GIVEN** `posJournalEntryOutbound` records exist in status `pending`, `failed`, or `staged`
- **WHEN** the `MigratePosBookkeepingToShillinq` repair step runs
- **THEN** each MUST be re-raised through the registry with its original `SHA256(zReport.uuid + reportDate)` key
- **AND** records already `posted` MUST have `shillinqJournalEntryId` and `bookkeepingStatus: raised` projected onto the parent `posZReport`
- **AND** the repair step MUST be re-runnable and MUST NOT fail the upgrade if shillinq is unreachable

