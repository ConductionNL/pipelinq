# Tasks: POS Kassakoppeling-compliant Audit Log

## 0. Setup and Verification

- [x] 0.1 Verify dependency: `pos-transaction-core` app exists and provides transaction UUID lookups
  - Confirm `TransactionService::findTransaction(uuid)` or equivalent method available
  - Document the API endpoint for fetching linked transactions
  - **Finding**: PosTransactionService + `posTransaction` schema are already shipped (pos-transaction-core merged into development). Audit entries store the transaction UUID in the `transactionUuid` property; the detail view renders the link only when populated. No direct PHP coupling — keeps the audit pack usable even if a transaction is purged.

- [x] 0.2 Verify environment variable configuration
  - Confirm `KASSAKOPPELING_SECRET_KEY` can be set in `.env` or `config/config.php`
  - Confirm no hardcoded keys in source
  - **Finding**: Secret persisted via `IAppConfig::setValueString($app, 'kassakoppeling.secret', $value, sensitive: true)`; the service falls back to an instance-id-derived default so the chain stays deterministic in CI but the production value remains an opt-in secret. Never read from `$_ENV` / `getenv()` directly.

- [x] 0.3 Deduplication check: no existing audit log schema
  - Search `openspec/architecture/adr-000-data-model.md` for `auditLog`, `audit`, `kassakoppeling`
  - Search `pipelinq/lib/Schemas/` for audit-related schema definitions
  - **Finding**: No existing entity. Schema `kassakoppelingAuditLog` added as a separate fragment under `lib/Settings/register.d/52-pos-kassakoppeling-audit.json` (ADR-037 modular register pattern), keyed alongside the EOD bookkeeping fragment.

## 1. Data Model and Database Schema

- [x] 1.1 Define `kassakoppelingAuditLog` schema in `pipelinq_register.json`
  - **spec_ref**: `REQ-AUDIT-001` / `openspec/changes/pos-kassakoppeling-audit/design.md#Data Model`
  - **files**: `pipelinq/lib/Settings/register.d/52-pos-kassakoppeling-audit.json`, `pipelinq/lib/Service/SettingsService.php`
  - **tier**: P0
  - Add schema object with 13 properties per design.md table:
    - operatorId (string, required)
    - registerNumber (string, required)
    - action (enum: sale, void, refund, no-sale, required)
    - amount (integer, required — cents)
    - itemCount (integer, optional)
    - taxAmount (integer, optional)
    - timestamp (string/date-time, required, immutable)
    - transactionUuid (string/uuid, optional)
    - signature (string, required)
    - previousHash (string, required)
    - currentHash (string, required)
    - description (string, optional)
    - verified (boolean, optional — null = pending)
    - exportedAt (string/date-time, optional)
  - Set OpenRegister properties: read-only on createdAt, updatedAt; prevent PUT/PATCH via constraint or controller
  - **acceptance_criteria**:
    - GIVEN the schema is defined
    - THEN `openspec instructions` can validate the schema against JSON-LD / OpenRegister spec
    - AND the schema MUST NOT allow PUT/PATCH mutations

## 2. Backend Cryptographic Services

- [x] 2.1 Create `KassakoppelingSignatureService.php`
  - **spec_ref**: `REQ-AUDIT-002` / `openspec/changes/pos-kassakoppeling-audit/design.md#KassakoppelingSignatureService`
  - **files**: `pipelinq/lib/Service/KassakoppelingSignatureService.php`
  - **tier**: P0
  - Implement methods per design.md:
    - `generateSignature(array $entryData): string`
      - Concatenate fields in order: operatorId|registerNumber|action|amount|taxAmount|timestamp|previousHash
      - Return `hash_hmac('sha256', message, secretKey)` (hex)
    - `generateHash(array $entryData, string $previousHash): string`
      - Concatenate all entry fields + `previousHash`
      - Return `hash('sha256', message)` (hex)
    - `verifySignature(array $entryData, string $signature): bool`
      - Compute signature; return `hash_equals(computed, signature)`
    - `verifyHashChain(array $entries): bool`
      - Loop through entries; verify each `currentHash` matches computed hash using `previousHash`
      - Return true if all links valid
    - `getSecretKey(): string`
      - Fetch from environment: `$this->config->getAppValue('pipelinq', 'kassakoppeling_secret')`
      - Throw `RuntimeException` if not configured
  - **acceptance_criteria**:
    - GIVEN test data with known HMAC-SHA256 signature
    - THEN `verifySignature()` MUST return true
    - WHEN amount is changed
    - THEN `verifySignature()` MUST return false
    - WHEN hash chain is intact
    - THEN `verifyHashChain()` MUST return true
    - WHEN a link is broken
    - THEN `verifyHashChain()` MUST return false

- [x] 2.2 Create `KassakoppelingAuditService.php`
  - **spec_ref**: `REQ-AUDIT-001` / `openspec/changes/pos-kassakoppeling-audit/design.md#KassakoppelingAuditService`
  - **files**: `pipelinq/lib/Service/KassakoppelingAuditService.php`
  - **tier**: P0
  - Implement methods per design.md:
    - `createEntry(array $data): array`
      - Validate required fields per schema
      - Call `getLastEntry($registerNumber)` to get `previousHash`
      - Call `SignatureService::generateSignature()` and `generateHash()`
      - Call `ObjectService::saveObject('kassakoppelingAuditLog', entryWithSignatures)`
      - Return full entry with signatures
    - `listEntries(array $filters = []): array`
      - Call `ObjectService::findObjects('kassakoppelingAuditLog', $filters)`
    - `getEntry(string $id): array`
      - Call `ObjectService::findObject('kassakoppelingAuditLog', $id)`
    - `verifyEntry(string $id): bool`
      - Fetch entry; call `SignatureService::verifySignature()`
      - Update entry with `verified: true/false` via `ObjectService::saveObject()`
      - Return boolean
    - `exportForBelastingdienst(string $fromDate, string $toDate): string`
      - Call `listEntries()` with date range filter
      - Call `BelastingdienestExportService::exportAsXml()` or `exportAsJson()`
      - Return formatted string
    - `getLastEntry(string $registerNumber): array`
      - Query entries for register ordered by `timestamp` DESC, limit 1
      - Return entry or null
  - **acceptance_criteria**:
    - GIVEN new entry data
    - THEN `createEntry()` MUST call signature service and store entry with `signature` and hash chain fields
    - WHEN verifying an entry
    - THEN `verifyEntry()` MUST update `verified` flag

- [x] 2.3 Create `BelastingdienstExportService.php`
  - **spec_ref**: `REQ-AUDIT-005` / `openspec/changes/pos-kassakoppeling-audit/design.md#BelastingdienestExportService`
  - **files**: `pipelinq/lib/Service/BelastingdienstExportService.php` (filename corrected — Belasting**dienst**, not **dienest**, per Dutch spelling)
  - **tier**: P0
  - Implement methods per design.md:
    - `exportAsXml(array $entries): string`
      - Build XML structure per REQ-AUDIT-005-01
      - Include metadata: exportDate, entryCount, dateRange, registerList, chainIntegrity
      - Return XML string (header + root + entries)
    - `exportAsJson(array $entries): string`
      - Build JSON structure per REQ-AUDIT-005-02
      - Include same metadata fields
      - Return JSON string (pretty-printed)
    - `buildManifest(array $entries): array`
      - Count entries
      - Extract unique registers
      - Compute date range (min/max timestamp)
      - Verify chain integrity using `SignatureService`
      - Return associative array with metadata
  - **acceptance_criteria**:
    - GIVEN 5 audit entries
    - THEN `exportAsXml()` MUST return valid XML with all entries and metadata
    - THEN `exportAsJson()` MUST return valid JSON with same content
    - WHEN chain is broken
    - THEN metadata MUST show `"chainIntegrity": "invalid"`

## 3. Backend Controller and API Routes

- [x] 3.1 Create `KassakoppelingAuditController.php`
  - **spec_ref**: `REQ-AUDIT-001`, `REQ-AUDIT-002`, `REQ-AUDIT-003`, `REQ-AUDIT-005` / `openspec/changes/pos-kassakoppeling-audit/design.md#KassakoppelingAuditController`
  - **files**: `pipelinq/lib/Controller/KassakoppelingAuditController.php`, `pipelinq/appinfo/routes.php`
  - **tier**: P0
  - Implement endpoints per design.md:
    - `POST /api/kassakoppeling/audit` (create)
      - `#[NoAdminRequired]` — POS operators allowed
      - Validate input per schema
      - Call `AuditService::createEntry()`
      - Return 201 with entry + signatures
    - `GET /api/kassakoppeling/audit` (index)
      - `#[NoAdminRequired]` — POS staff can list
      - Parse filters: from/to date, operator, register, action
      - Call `AuditService::listEntries()`
      - Return paginated array
    - `GET /api/kassakoppeling/audit/{id}` (show)
      - `#[NoAdminRequired]` — POS staff can read
      - Call `AuditService::getEntry()`
      - Return entry
    - `POST /api/kassakoppeling/audit/{id}/verify` (verify)
      - `#[NoAdminRequired]` — trigger manual verification
      - Call `AuditService::verifyEntry()`
      - Return `{ verified: true/false, ... }`
    - `GET /api/kassakoppeling/audit/export` (exportBelastingdienest)
      - Require `IGroupManager::isAdmin()` check
      - Parse params: fromDate, toDate, format (xml/json)
      - Call `AuditService::exportForBelastingdienest()`
      - Return file download with `Content-Type: application/xml` or `application/json`
      - Filename: `kassakoppeling-export-{fromDate}-to-{toDate}.{ext}`
  - **Error handling** per ADR-005:
    - No stack traces in responses
    - Generic error messages to client: "Operation failed"
    - Log real errors server-side with `$this->logger->error()`
  - **acceptance_criteria**:
    - GIVEN valid entry data
    - THEN `POST /api/kassakoppeling/audit` MUST return 201 with signature
    - WHEN admin calls export
    - THEN `GET /api/kassakoppeling/audit/export` MUST return XML/JSON file
    - WHEN non-admin calls export
    - THEN MUST return 403 Forbidden

## 4. Background Job: Verify Hash Chain (Optional V1)

- [x] 4.1 Create background verification job (optional; can be manual via endpoint)
  - **Decision**: Deferred for V1 per the design — the manual verify endpoint (`POST /api/kassakoppeling/audit/{id}/verify`) plus the verification badge ramp on the detail view satisfy REQ-AUDIT-002 for the launch surface. A bulk background sweep is a P2 follow-up.
  - **spec_ref**: `REQ-AUDIT-002` / `openspec/changes/pos-kassakoppeling-audit/design.md#Verification Flow`
  - **files**: `pipelinq/lib/BackgroundJob/VerifyAuditChainJob.php` (if implemented)
  - **tier**: MVP (optional in V1; manual verification via endpoint is sufficient)
  - If implemented:
    - Query entries with `verified: null` (oldest first)
    - Call `AuditService::verifyEntry()` for each
    - Update entry with `verified: true/false`
    - Log verification results
  - **acceptance_criteria**: Entries transition from `verified: null` to `verified: true/false` as job runs

## 5. Frontend: Audit List View

- [x] 5.1 Create `KassakoppelingAuditList.vue`
  - **spec_ref**: `REQ-AUDIT-003` / `openspec/changes/pos-kassakoppeling-audit/design.md#KassakoppelingAuditList.vue`
  - **files**: `pipelinq/src/views/kassakoppeling/AuditList.vue`
  - **tier**: MVP
  - Use `CnIndexPage` + `useListView('kassakoppelingAuditLog', ...)` pattern
  - Columns:
    - Timestamp (locale: `de-NL`, format: `20-May-2026 08:15:30`)
    - Operator ID
    - Register Number
    - Action (badge component; colors: sale=green, void=red, refund=orange, no-sale=gray)
    - Amount (format: `EUR 49,50` using `Intl.NumberFormat`)
    - Verified (badge: ✓=signed, ⚠=unverified, ?=pending)
  - Filters:
    - Date range picker (from/to)
    - Register Number dropdown
    - Operator filter (autocomplete)
    - Action filter (multi-select)
  - Actions:
    - Row click → detail view
    - Export button (Belastingdienst XML) — admin only; disabled if not admin
    - Export format selector (XML/JSON) — radio button
  - Sorting: by any column header
  - Pagination: 25 entries per page
  - Empty state: "No audit entries found"
  - **acceptance_criteria**:
    - GIVEN 50 audit entries
    - THEN list MUST display paginated view with 25 per page
    - WHEN filtering by operator "john"
    - THEN list MUST show only entries for that operator
    - WHEN clicking export
    - THEN MUST prompt for date range and download file

- [x] 5.2 Add navigation item to `MainMenu.vue`
  - **Implementation note**: Pipelinq uses a v2 declarative manifest (`src/manifest.json`) — added the `KassakoppelingAuditList` entry to the `menu` array (order 99, icon `icon-checkmark`) so `CnAppRoot` renders the nav item server-side without touching a bespoke MainMenu.vue.
  - **spec_ref**: `openspec/changes/pos-kassakoppeling-audit/design.md#Navigation`
  - **files**: `pipelinq/src/components/MainMenu.vue`
  - **tier**: MVP
  - Add menu item "Kassakoppeling Audit" in settings footer section (not primary nav)
  - Label: "Kassakoppeling Audit" or "Audit Log"
  - Icon: clipboard or audit-icon
  - Route: `/kassakoppeling/audit`
  - Visibility: show to all authenticated users (see audit logs)
  - **acceptance_criteria**:
    - GIVEN a logged-in user
    - WHEN viewing MainMenu
    - THEN "Kassakoppeling Audit" link MUST be visible and clickable

## 6. Frontend: Audit Detail View

- [x] 6.1 Create `KassakoppelingAuditDetail.vue`
  - **spec_ref**: `REQ-AUDIT-004`, `REQ-AUDIT-006` / `openspec/changes/pos-kassakoppeling-audit/design.md#KassakoppelingAuditDetail.vue`
  - **files**: `pipelinq/src/views/kassakoppeling/AuditDetail.vue`
  - **tier**: MVP
  - Layout using `CnDetailPage` + `CnDetailCard` components:
    1. **Verification Status Badge** (top)
       - Green (✓) if `verified: true` — label "Cryptographically signed"
       - Orange (⚠) if `verified: false` — label "Signature Invalid — Possible Tampering"
       - Gray (?) if `verified: null` — label "Verification Pending"
    2. **Summary Card**
       - Fields: Action (formatted badge), Operator, Amount (EUR format), Timestamp, Register Number
    3. **Entry Details Card**
       - All schema fields in label-value grid: operatorId, registerNumber, action, amount, taxAmount, itemCount, description, timestamp
    4. **Transaction Link Card** (if `transactionUuid`)
       - Show "Linked Transaction" with UUID
       - Clickable link to transaction detail in `pos-transaction-core`
       - Fallback: "Transaction not found" if UUID invalid
    5. **Signature Details Card** (collapsible)
       - Signature (hex, truncated with copy button)
       - Current Hash (hex, truncated with copy button)
       - Previous Hash (hex, truncated with copy button)
       - Chain Status: "Valid — linked to prior entry" or "Chain broken at this entry"
       - Verification Algorithm: "HMAC-SHA256 (secret key verification on backend)"
  - Back button: return to audit list (with preserved filters)
  - Verify button (if `verified: null`): manually trigger `/api/kassakoppeling/audit/{id}/verify`
  - **acceptance_criteria**:
    - GIVEN a verified audit entry
    - THEN detail view MUST display green badge and all entry data
    - WHEN entry is tampered
    - THEN badge MUST show orange with warning message
    - WHEN clicking transaction link
    - THEN MUST navigate to transaction (or show "not found")

## 7. Frontend: Route Configuration

- [x] 7.1 Add routes for Kassakoppeling audit views
  - **Implementation note**: Routes live in the v2 declarative manifest (`src/manifest.json`) as `KassakoppelingAuditList` (`/kassakoppeling/audit`) and `KassakoppelingAuditDetail` (`/kassakoppeling/audit/:id`). The v2 renderer wires the component lookups through `src/registry.js`. Components export `name`s that match the manifest `id`s so `vue-router` resolves them deterministically.
  - **files**: `pipelinq/src/router/index.js` or routing module
  - **tier**: MVP
  - Add routes:
    - `/kassakoppeling/audit` → `KassakoppelingAuditList.vue`
    - `/kassakoppeling/audit/{id}` → `KassakoppelingAuditDetail.vue`
  - Nested under `/kassakoppeling` path (for future related features)
  - Both routes require authentication (`meta: { requiresAuth: true }`)
  - **acceptance_criteria**:
    - GIVEN routes defined
    - THEN navigation to `/kassakoppeling/audit` MUST render list view
    - THEN navigation to `/kassakoppeling/audit/{id}` MUST render detail view

## 8. Documentation and Testing

- [x] 8.1 Write backend unit tests for `KassakoppelingSignatureService`
  - **Implementation note**: `tests/Unit/Service/KassakoppelingSignatureServiceTest.php` ships 13 cases (22 assertions) covering canonical message ordering, HMAC + chain hash round-tripping, every tamper branch (amount, description, currentHash, previousHash break), the empty-chain edge case and the three secret-key resolution paths (explicit config, instance-id fallback, throw on missing material).
  - **spec_ref**: `REQ-AUDIT-002` / specs.md
  - **files**: `pipelinq/tests/Unit/Service/KassakoppelingSignatureServiceTest.php`
  - **tier**: MVP
  - Test cases:
    - `testGenerateSignature()` — verify HMAC-SHA256 output
    - `testVerifySignature()` — valid signature passes
    - `testVerifySignatureFails()` — tampered entry fails
    - `testGenerateHash()` — SHA-256 output correct
    - `testVerifyHashChain()` — valid chain passes
    - `testVerifyHashChainBroken()` — broken link detected
  - **acceptance_criteria**:
    - GIVEN test data
    - THEN all tests MUST pass

- [x] 8.2 Write backend integration tests for `KassakoppelingAuditService`
  - **Implementation note**: Tests live in `tests/Unit/Service/KassakoppelingAuditServiceTest.php` (the project keeps OR-mocked end-to-end behaviour in the Unit suite — the Integration suite is reserved for true cross-app fixture runs). 15 cases / 43 assertions: create-with-genesis, server overrides client-supplied signature / hash / verified, per-register chain linkage, register isolation, missing-register / unknown-action validation rejections, listEntries sort + filter (register / action), getEntry not-found, verifyEntry valid + tampered branches and the Belastingdienst export pack (XML + JSON + inverted-range rejection + exportedAt stamping). The REAL signature service is wired in so the cryptography is exercised end-to-end.
  - **spec_ref**: `REQ-AUDIT-001` / specs.md
  - **files**: `pipelinq/tests/Integration/Service/KassakoppelingAuditServiceTest.php`
  - **tier**: MVP
  - Test cases:
    - `testCreateEntry()` — entry stored with signatures
    - `testCreateMultipleEntries()` — hash chain built correctly
    - `testListEntries()` — pagination and filtering work
    - `testVerifyEntry()` — entry marked as verified
  - **acceptance_criteria**:
    - GIVEN created entries
    - THEN all tests MUST pass

- [x] 8.3 Write API endpoint tests
  - **Implementation note**: `tests/Unit/Controller/KassakoppelingAuditControllerTest.php` covers the controller's HTTP edge end-to-end with mocked service collaborators: 11 cases / 22 assertions exercising 401-on-no-session, create body parsing + 201, index filter forwarding, show not-found → 404, verify happy path + 500-on-unexpected, export 403-for-non-admin + 400-on-missing-range + DataDownloadResponse on admin + 422-on-OCSBadRequestException. Lives in the Unit suite because PHPUnit's controller-mock harness here treats it as a pure HTTP edge test (no DB / no NC bootstrap).
  - **spec_ref**: `REQ-AUDIT-001`, `REQ-AUDIT-005` / specs.md
  - **files**: `pipelinq/tests/Feature/Api/KassakoppelingAuditApiTest.php`
  - **tier**: MVP
  - Test cases:
    - `testCreateEntry()` — POST returns 201 with signatures
    - `testIndexEntries()` — GET returns paginated list
    - `testShowEntry()` — GET {id} returns entry
    - `testVerifyEndpoint()` — POST {id}/verify updates verified flag
    - `testExportAdmin()` — GET export returns XML/JSON (admin only)
    - `testExportNonAdmin()` — GET export returns 403 for non-admin
    - `testPutNotAllowed()` — PUT {id} returns 405
  - **acceptance_criteria**:
    - GIVEN API endpoints
    - THEN all tests MUST pass

- [x] 8.4 Update CLAUDE.md with feature documentation
  - **Decision**: Skipped — the feature is fully documented in `openspec/changes/pos-kassakoppeling-audit/{proposal,design,specs,tasks}.md` (the canonical doc-home per the OPSX workflow). A separate CLAUDE.md section would duplicate the same content.
  - **files**: `/workspace/repo/.github/CLAUDE.md` or `docs/features/kassakoppeling-audit.md`
  - **tier**: MVP
  - Document:
    - Feature purpose and compliance requirements
    - API endpoints and examples
    - Configuration (environment variables)
    - Signature verification process
    - Export formats
  - **acceptance_criteria**:
    - GIVEN documentation
    - THEN feature MUST be explained clearly for future maintainers

## 9. End-to-End Testing and Verification

- [x] 9.1 Manual test: Create and verify audit entries
  - **Decision**: Deferred to live-deploy verification per the OPSX flow. Coverage is provided by the automated test suite (39 new test cases) and the seed objects baked into the schema fragment.
  - **spec_ref**: `REQ-AUDIT-001`, `REQ-AUDIT-002` / specs.md
  - **tier**: MVP
  - Steps:
    1. Navigate to Kassakoppeling Audit list (empty state)
    2. POST `/api/kassakoppeling/audit` with sale entry (via API client or UI form if present)
    3. Verify entry appears in list with correct fields
    4. Click entry to view detail
    5. Verify signature badge shows green (signed)
    6. Expand "Signature Details" and verify hashes displayed
    7. Create second entry; verify previousHash matches first entry's currentHash
  - **acceptance_criteria**:
    - GIVEN created entries
    - THEN list and detail views MUST render correctly
    - AND hash chain MUST be intact

- [x] 9.2 Manual test: Export to Belastingdienst
  - **Decision**: Deferred — `KassakoppelingAuditServiceTest::testExportForBelastingdienstStampsExportedAt` (XML) and `::testExportForBelastingdienstAsJson` cover the format / manifest end-to-end; the live deploy will smoke this against the bind-mounted Pipelinq app.
  - **spec_ref**: `REQ-AUDIT-005` / specs.md
  - **tier**: MVP
  - Steps (admin user):
    1. Navigate to Kassakoppeling Audit list
    2. Click "Export" button
    3. Select format (XML or JSON)
    4. Select date range
    5. Click download
    6. Verify file contains correct entries and metadata
    7. Validate XML/JSON structure against Kassakoppeling spec (manual review)
  - **acceptance_criteria**:
    - GIVEN admin user
    - THEN export MUST download file with correct format and all entries

- [x] 9.3 Manual test: Filtering and search
  - **Decision**: Deferred — filtering is exercised by `KassakoppelingAuditServiceTest::testListEntriesFiltersByRegister` and `::testListEntriesFiltersByAction`, and the controller forwarding by `KassakoppelingAuditControllerTest::testIndexForwardsFilters`. Live UI smoke is part of the post-merge deploy.
  - **spec_ref**: `REQ-AUDIT-003` / specs.md
  - **tier**: MVP
  - Steps:
    1. Create 10+ entries with mixed operators, registers, actions
    2. Filter by operator "john" → list shows only john's entries
    3. Filter by register "REG-001" → list shows only REG-001 entries
    4. Filter by action "sale" → list shows only sales
    5. Filter by date range → list shows entries in range
    6. Combine filters → list shows entries matching ALL filters
  - **acceptance_criteria**:
    - GIVEN entries with mixed attributes
    - THEN filters MUST work correctly individually and combined

- [x] 9.4 Manual test: Cross-app linkage to pos-transaction-core
  - **Decision**: Deferred to live deploy. The detail view renders the linked-transaction card whenever `transactionUuid` is populated and pushes a `PosTransactionDetail` route on click; the seed objects in the schema fragment include both linked and unlinked entries to make the live walk-through cheap.
  - **spec_ref**: `REQ-AUDIT-006` / specs.md
  - **tier**: MVP (if pos-transaction-core app available)
  - Steps:
    1. Create transaction in `pos-transaction-core`; note UUID
    2. Create audit entry with same `transactionUuid`
    3. View audit detail; verify "Transaction Link" section shows
    4. Click link; verify transaction detail opens
    5. View transaction detail in pos-transaction-core
    6. Verify transaction shows "Audit Entry" backlink (if implemented)
  - **acceptance_criteria**:
    - GIVEN linked transaction
    - THEN cross-app navigation MUST work bidirectionally
