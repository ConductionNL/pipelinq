# pos-kassakoppeling-audit Specification

## Purpose
The cash register audit trail: each audit entry is a detail page with its verification status, summary and cryptographic data, and its signature can be verified from the page. The requirements with REQ ids are synced from the archived change `2026-06-14-pos-kassakoppeling-audit`.

## Requirements

### Requirement: An audit entry is a declared detail page under an English address

An audit entry SHALL open at `/cash-register-audit/{id}` and SHALL show its verification status, its summary with the
amounts in money, and its signature and hashes with copy buttons. Verify signature SHALL be offered while the entry is not
verified. The old `/kassakoppeling/audit` addresses SHALL redirect to the new ones.

#### Scenario: An old link

- GIVEN a bookmark to `/kassakoppeling/audit/a1`
- WHEN the auditor opens it
- THEN the page opens at `/cash-register-audit/a1` and shows entry a1

### Requirement: Append-Only Audit Log Entry Creation (REQ-AUDIT-001)

Audit log entries MUST be immutable, append-only records. Once created, an entry CANNOT be edited, deleted, or have its cryptographic signature invalidated.

**Feature tier**: P0 (regulatory requirement)  
**Spec ref**: `openspec/changes/pos-kassakoppeling-audit/design.md#Data Model`  
**Files**: `pipelinq/lib/Service/KassakoppelingAuditService.php`, `pipelinq/lib/Controller/KassakoppelingAuditController.php`

#### Scenario: Sale entry created with signature and hash chain (REQ-AUDIT-001-01)

- GIVEN a POS operator at register "REG-001" processes a sale transaction
- WHEN the operator posts to `POST /api/kassakoppeling/audit` with data:
  ```json
  {
    "operatorId": "user_john",
    "registerNumber": "REG-001",
    "action": "sale",
    "amount": 4950,
    "itemCount": 3,
    "taxAmount": 870,
    "timestamp": "2026-05-20T08:15:30Z",
    "description": "Regular sale"
  }
  ```
- THEN the system MUST:
  - Generate `signature` using HMAC-SHA256 of entry fields + secret key
  - Compute `currentHash` as SHA-256 of entry fields + `previousHash`
  - Retrieve `previousHash` from the last audit entry for register "REG-001" (or '0' if first entry)
  - Store the entry in `kassakoppelingAuditLog` schema with all fields
  - Return HTTP 201 with complete entry including `signature`, `currentHash`, `previousHash`

#### Scenario: Void transaction appended to chain (REQ-AUDIT-001-02)

- GIVEN a previous sale entry exists with `currentHash` = "e8a2c6d7f5b1e3a9c2f7d8b4a1e9c3f6"
- WHEN an operator posts a void action for the same register
- THEN the new entry MUST have:
  - `previousHash` = "e8a2c6d7f5b1e3a9c2f7d8b4a1e9c3f6" (from prior entry)
  - `currentHash` computed with this `previousHash` included
  - Separate `signature` field
  - All data immutable after creation

#### Scenario: No PUT/PATCH allowed on audit entries (REQ-AUDIT-001-03)

- GIVEN an existing audit log entry
- WHEN an operator attempts `PUT /api/kassakoppeling/audit/{id}` to modify the entry
- THEN the system MUST reject with HTTP 405 Method Not Allowed
- AND the entry in the database MUST remain unchanged
- AND an error log MUST be recorded (backend only; do not expose to client)

#### Scenario: Refund entry links to transaction UUID (REQ-AUDIT-001-04)

- GIVEN a refund transaction in `pos-transaction-core` with UUID "uuid-txn-20260520-002"
- WHEN an operator creates an audit entry with `transactionUuid: "uuid-txn-20260520-002"`
- THEN the audit entry MUST store this UUID in the `transactionUuid` field
- AND the detail view MUST display a link to the transaction in `pos-transaction-core`

### Requirement: Cryptographic Signature Generation and Verification (REQ-AUDIT-002)

Audit entries MUST be signed using HMAC-SHA256. The signature MUST be verifiable using the same secret key and field order. Each entry is part of a hash chain where each hash depends on the previous entry's hash.

**Feature tier**: P0 (regulatory requirement)  
**Spec ref**: `openspec/changes/pos-kassakoppeling-audit/design.md#KassakoppelingSignatureService`  
**Files**: `pipelinq/lib/Service/KassakoppelingSignatureService.php`  
**Standards**: HMAC-SHA256, SHA-256 (hash chain)

#### Scenario: Signature verification passes for valid entry (REQ-AUDIT-002-01)

- GIVEN an audit entry:
  ```
  operatorId=user_john
  registerNumber=REG-001
  action=sale
  amount=4950
  taxAmount=870
  timestamp=2026-05-20T08:15:30Z
  previousHash=0
  signature=a3f2c1e9b4d7f8c2a9e3b1f5d7c6a2e8
  ```
- WHEN an operator calls `POST /api/kassakoppeling/audit/{id}/verify`
- THEN the system MUST:
  - Retrieve the entry
  - Compute HMAC-SHA256(message, secretKey) where message = concatenated fields in order
  - Compare computed signature with stored `signature` field
  - Return JSON: `{ "verified": true, "timestamp": "...", "message": "Signature valid" }`

#### Scenario: Signature verification fails for tampered entry (REQ-AUDIT-002-02)

- GIVEN an entry with tampered `amount` field (changed from 4950 to 5000 after signing)
- WHEN verification runs
- THEN the computed signature MUST NOT match the stored signature
- AND the system MUST return `{ "verified": false, "message": "Signature mismatch — possible tampering detected" }`
- AND an audit log MUST be written server-side (without exposing to client per ADR-005)

#### Scenario: Hash chain integrity verified (REQ-AUDIT-002-03)

- GIVEN a sequence of three entries:
  - Entry 1: `currentHash=e8a2...c3f6`
  - Entry 2: `previousHash=e8a2...c3f6`, `currentHash=f7b1...a6b9`
  - Entry 3: `previousHash=f7b1...a6b9`, `currentHash=a6b9...d1e4`
- WHEN verification runs on the chain
- THEN the system MUST validate each link:
  - Entry 2's `previousHash` matches Entry 1's `currentHash`
  - Entry 3's `previousHash` matches Entry 2's `currentHash`
  - Each `currentHash` is correctly computed from entry fields
- AND the response MUST include `{ "chainValid": true, "unbrokenLinks": 3 }`

#### Scenario: Hash chain break detected (REQ-AUDIT-002-04)

- GIVEN Entry 2's `previousHash` does NOT match Entry 1's `currentHash` (due to tampering or missing entry)
- WHEN verification runs
- THEN the system MUST identify the break and return `{ "chainValid": false, "brokenAt": 2, "message": "Hash chain integrity violated at entry 2" }`

### Requirement: Audit Log List with Search and Filtering (REQ-AUDIT-003)

POS staff MUST be able to search, filter, and view audit entries in a paginated list. List MUST show key fields: timestamp, operator, register, action, amount, verified status.

**Feature tier**: MVP  
**Spec ref**: `openspec/changes/pos-kassakoppeling-audit/design.md#KassakoppelingAuditList.vue`  
**Files**: `pipelinq/src/views/kassakoppeling/AuditList.vue`  
**Standards**: `de-NL` locale formatting, EUR currency

#### Scenario: List displays all audit entries for date range (REQ-AUDIT-003-01)

- GIVEN audit entries spanning 2026-05-20 to 2026-05-21
- WHEN a user navigates to `/kassakoppeling/audit`
- THEN the system MUST display a paginated list with columns:
  - Timestamp (formatted: `20-May-2026 08:15:30`)
  - Operator ID
  - Register Number
  - Action (badge colored: sale=green, void=red, refund=orange, no-sale=gray)
  - Amount (formatted: `EUR 49,50`)
  - Verified (badge: ✓=signed, ⚠=unverified)
- AND default page size MUST be 25 entries
- AND total entry count MUST be displayed (e.g., "Showing 1-25 of 150")

#### Scenario: Filter by date range (REQ-AUDIT-003-02)

- GIVEN audit entries from 2026-05-01 to 2026-05-31
- WHEN a user selects date range "2026-05-20 to 2026-05-21" in the filter panel
- THEN the list MUST refresh showing only entries within that range
- AND the filter MUST persist on page reload (via URL query params)

#### Scenario: Filter by operator (REQ-AUDIT-003-03)

- GIVEN entries from multiple operators (user_john, user_maria, user_peter)
- WHEN a user types "john" in the Operator filter autocomplete
- THEN the list MUST show only entries with `operatorId` containing "john"
- AND pressing Enter or selecting from dropdown MUST apply filter
- AND other filters remain active (AND logic)

#### Scenario: Filter by register number (REQ-AUDIT-003-04)

- GIVEN entries from registers REG-001, REG-002, REG-003
- WHEN a user selects register "REG-001" from the dropdown
- THEN the list MUST show only entries where `registerNumber = 'REG-001'`

#### Scenario: Filter by action (REQ-AUDIT-003-05)

- GIVEN entries with mixed actions (sale, void, refund, no-sale)
- WHEN a user selects actions "sale" and "void" in the multi-select filter
- THEN the list MUST show entries with `action` matching either value (OR logic within filter)

#### Scenario: Sort by verified status (REQ-AUDIT-003-06)

- GIVEN a mixed list of verified, unverified, and pending entries
- WHEN a user clicks the "Verified" column header
- THEN the list MUST sort by `verified` status (true → false → null)

### Requirement: Audit Entry Detail View with Signature Verification Badge (REQ-AUDIT-004)

Each audit entry detail view MUST display the complete entry data, hash chain context, and a prominent verification status badge indicating cryptographic signature validity.

**Feature tier**: MVP  
**Spec ref**: `openspec/changes/pos-kassakoppeling-audit/design.md#KassakoppelingAuditDetail.vue`  
**Files**: `pipelinq/src/views/kassakoppeling/AuditDetail.vue`

#### Scenario: Detail view displays entry with verification badge (REQ-AUDIT-004-01)

- GIVEN a verified audit entry with `verified: true`
- WHEN a user navigates to `/kassakoppeling/audit/{id}`
- THEN the detail view MUST display:
  - Summary card: Action, Operator, Amount, Timestamp, Register
  - ✓ Green verification badge: "Cryptographically signed"
  - All entry fields: operatorId, registerNumber, action, amount, taxAmount, itemCount, description
  - Transaction link (if `transactionUuid` is set)
  - Collapsible "Signature Details" section with signature hex, currentHash, previousHash
  - Back button to return to list

#### Scenario: Unverified entry shown with warning badge (REQ-AUDIT-004-02)

- GIVEN an entry with `verified: false` (tampering detected)
- WHEN the user views the detail
- THEN the verification badge MUST be:
  - ⚠ Orange background
  - Label: "Signature Invalid — Possible Tampering"
  - Icon: warning triangle
  - Displayed prominently at the top

#### Scenario: Pending verification shown with pending badge (REQ-AUDIT-004-03)

- GIVEN a newly created entry with `verified: null` (awaiting background verification job)
- WHEN the user views the detail
- THEN the verification badge MUST be:
  - ? Gray background
  - Label: "Verification Pending"
  - Icon: spinner or hourglass

#### Scenario: Linked transaction displayed (REQ-AUDIT-004-04)

- GIVEN an entry with `transactionUuid: "uuid-txn-20260520-001"`
- WHEN the detail view renders
- THEN the Transaction Link section MUST display:
  - Transaction ID (clickable link)
  - Link to transaction detail in `pos-transaction-core` app
  - Transaction summary (amount, items) if available

#### Scenario: Signature details collapsible section (REQ-AUDIT-004-05)

- GIVEN a verified entry
- WHEN the user clicks "Show Signature Details"
- THEN the section MUST expand to show:
  - Signature (hex): `a3f2c1e9b4d7f8c2a9e3b1f5d7c6a2e8`
  - Current Hash (hex): `e8a2c6d7f5b1e3a9c2f7d8b4a1e9c3f6`
  - Previous Hash (hex): `0`
  - Hash Chain Status: "Valid — linked to prior entry"
  - Verifiable By: "HMAC-SHA256 (secret key verification on backend)"

### Requirement: Belastingdienst Export Functionality (REQ-AUDIT-005)

Administrators MUST be able to export audit logs in Kassakoppeling-compliant format for submission to the Dutch tax authority (Belastingdienst). Export MUST include metadata, hash chain verification status, and cryptographic proof.

**Feature tier**: P0 (regulatory requirement)  
**Spec ref**: `openspec/changes/pos-kassakoppeling-audit/design.md#BelastingdienestExportService`  
**Files**: `pipelinq/lib/Service/BelastingdienestExportService.php`, `pipelinq/src/views/kassakoppeling/AuditList.vue`  
**Standards**: POS Kassakoppeling XML format (per Belastingdienst spec), ISO 8601 timestamps

#### Scenario: Export to Belastingdienst XML format (REQ-AUDIT-005-01)

- GIVEN 50 audit entries from 2026-05-01 to 2026-05-31
- WHEN an admin user clicks the "Export → Belastingdienst XML" button on the audit list
- THEN the system MUST:
  - Prompt for date range (default: current month)
  - Generate XML file with structure:
    ```xml
    <KassakoppelingExport>
      <Metadata>
        <ExportDate>2026-05-21T18:00:00Z</ExportDate>
        <EntryCount>50</EntryCount>
        <DateRange>2026-05-01 to 2026-05-31</DateRange>
        <RegisterList>REG-001, REG-002</RegisterList>
        <ChainIntegrity>valid</ChainIntegrity>
        <SignatureAlgorithm>HMAC-SHA256</SignatureAlgorithm>
      </Metadata>
      <Entries>
        <Entry>
          <Timestamp>2026-05-20T08:15:30Z</Timestamp>
          <OperatorId>user_john</OperatorId>
          <RegisterNumber>REG-001</RegisterNumber>
          <Action>sale</Action>
          <Amount>4950</Amount>
          <TaxAmount>870</TaxAmount>
          <Signature>a3f2c1e9b4d7f8c2a9e3b1f5d7c6a2e8</Signature>
          <CurrentHash>e8a2c6d7f5b1e3a9c2f7d8b4a1e9c3f6</CurrentHash>
          <PreviousHash>0</PreviousHash>
          <Verified>true</Verified>
        </Entry>
        <!-- more entries -->
      </Entries>
    </KassakoppelingExport>
    ```
  - Set filename: `kassakoppeling-export-2026-05-01-to-2026-05-31.xml`
  - Return with `Content-Type: application/xml`

#### Scenario: Export to JSON format (REQ-AUDIT-005-02)

- GIVEN the same 50 entries
- WHEN an admin selects "Export → Belastingdienst JSON"
- THEN the system MUST generate:
  ```json
  {
    "exportMetadata": {
      "exportDate": "2026-05-21T18:00:00Z",
      "entryCount": 50,
      "dateRange": { "from": "2026-05-01", "to": "2026-05-31" },
      "registerList": ["REG-001", "REG-002"],
      "chainIntegrity": "valid",
      "signatureAlgorithm": "HMAC-SHA256"
    },
    "entries": [
      {
        "timestamp": "2026-05-20T08:15:30Z",
        "operatorId": "user_john",
        "registerNumber": "REG-001",
        "action": "sale",
        "amount": 4950,
        "taxAmount": 870,
        "signature": "a3f2c1e9b4d7f8c2a9e3b1f5d7c6a2e8",
        "currentHash": "e8a2c6d7f5b1e3a9c2f7d8b4a1e9c3f6",
        "previousHash": "0",
        "verified": true
      }
    ]
  }
  ```

#### Scenario: Only admin can export (REQ-AUDIT-005-03)

- GIVEN a non-admin POS operator
- WHEN the operator visits `/kassakoppeling/audit`
- THEN the "Export" button MUST NOT be visible
- AND if the operator manually calls `GET /api/kassakoppeling/audit/export`, the system MUST return HTTP 403 Forbidden

#### Scenario: Export includes hash chain verification status (REQ-AUDIT-005-04)

- GIVEN a chain with one broken link at entry 15
- WHEN exporting
- THEN the metadata MUST show:
  - `"chainIntegrity": "invalid"`
  - `"chainStatus": "Broken at entry 15: previousHash mismatch"`
- AND all entries MUST be included (with `verified: false` for broken entries)

### Requirement: Transaction UUID Cross-App Linkage (REQ-AUDIT-006)

Audit log entries MAY include a reference to transactions in the `pos-transaction-core` app via the `transactionUuid` field. The audit entry and transaction MUST be linkable for traceability across apps.

**Feature tier**: MVP  
**Spec ref**: Context brief: "Audit log linkable to each shillinq journal entry via transaction UUID"  
**Files**: `pipelinq/src/views/kassakoppeling/AuditDetail.vue`  
**Dependencies**: `pos-transaction-core` app

#### Scenario: Audit entry created with transaction link (REQ-AUDIT-006-01)

- GIVEN a transaction in `pos-transaction-core` with UUID "uuid-txn-20260520-001"
- WHEN an operator creates an audit entry with `transactionUuid: "uuid-txn-20260520-001"`
- THEN the audit entry MUST store the UUID
- AND the detail view MUST fetch and display the linked transaction

#### Scenario: Transaction link optional (REQ-AUDIT-006-02)

- GIVEN an audit entry for a "no-sale" action (not a transaction)
- WHEN creating the entry with `transactionUuid: null`
- THEN the entry MUST be created successfully
- AND the detail view MUST NOT display a Transaction Link section

#### Scenario: Bidirectional cross-app visibility (REQ-AUDIT-006-03)

- GIVEN an audit entry linked to a transaction
- WHEN the user views the transaction detail in `pos-transaction-core`
- THEN the transaction detail MUST show:
  - "Audit Entry" link with entry ID
  - Link to audit detail view in this app (openregister-pipelinq)

