# Design: POS Kassakoppeling-compliant Audit Log

## Architecture Overview

### Components

1. **Data Layer**: `kassakoppelingAuditLog` schema (OpenRegister) — append-only ledger entity
2. **Backend Services**:
   - `KassakoppelingAuditService` — CRUD, signature generation/verification, hash-chain management
   - `BelastingdienestExportService` — format audit logs for tax authority export
   - `KassakoppelingSignatureService` — cryptographic signing (HMAC-SHA256, OpenSSL)
3. **Frontend**:
   - `KassakoppelingAuditList.vue` — searchable list of audit entries with filtering
   - `KassakoppelingAuditDetail.vue` — detail view with signature verification badge
   - Export button with Belastingdienst format selection

### Data Model (OpenRegister Schema)

**kassakoppelingAuditLog**

Immutable ledger entity. Once created, entries are append-only and CANNOT be edited or deleted.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| operatorId | string | Yes | Nextcloud user UID or external operator identifier |
| registerNumber | string | Yes | POS register/terminal number (e.g., 'REG-001') |
| action | string | Yes | Enum: `sale`, `void`, `refund`, `no-sale` |
| amount | integer | Yes | Transaction amount in cents (EUR) to avoid float precision loss |
| itemCount | integer | No | Number of items in transaction |
| taxAmount | integer | No | VAT/tax amount in cents |
| timestamp | string (date-time) | Yes | ISO 8601 timestamp of action (immutable) |
| transactionUuid | string | No | UUID reference to `pos-transaction-core` transaction (cross-app linkage) |
| signature | string | Yes | HMAC-SHA256 hex digest (computed from entry fields + secret key) |
| previousHash | string | Yes | Hash of previous audit entry (or '0' if first entry) |
| currentHash | string | Yes | SHA-256 hash of this entry (computed from all fields except `currentHash` and `signature`) |
| description | string | No | Operator notes (e.g., "void: customer requested refund") |
| verified | boolean | No | Flag set by verification job (default: null, set to true/false after cryptographic check) |
| exportedAt | string (date-time) | No | Timestamp when entry was exported to Belastingdienst |

**Immutability Constraints**:
- No PUT/PATCH endpoint. Entries are created via POST only.
- OpenRegister `status` field (if present) cannot be used to "delete" entries — they remain in ledger permanently.
- `createdAt` and `updatedAt` must match (entry is never updated after creation).

### Reuse Analysis

| Capability | Platform provided | Custom needed |
|------------|------------------|---------------|
| Object CRUD (read-only create) | `ObjectService.saveObject` (POST only) | Modify to prevent PUT/PATCH on audit schema |
| List/search | `CnIndexPage` + `useListView` | No custom store; standard filtering |
| Cryptographic signing | OpenSSL (`openssl_sign`, `hash_hmac`) | `KassakoppelingSignatureService` wrapper |
| Hash chain | None | `KassakoppelingAuditService::hashEntry()` |
| Export format | None | `BelastingdienestExportService` (XML/JSON builder) |
| Frontend detail view | `CnDetailPage` | No custom; reuse pattern from `ClientDetail.vue` |
| Cross-app linkage | `relations` array in OpenRegister | `transactionUuid` field links to `pos-transaction-core` |

### Backend

#### KassakoppelingSignatureService (`lib/Service/KassakoppelingSignatureService.php`)

Handles cryptographic operations per POS Kassakoppeling spec.

| Method | Signature | Description |
|--------|-----------|-------------|
| `generateSignature` | `(array $entryData): string` | HMAC-SHA256 of entry fields concatenated with secret key |
| `generateHash` | `(array $entryData, string $previousHash): string` | SHA-256 of entry + previousHash (for hash chain) |
| `verifySignature` | `(array $entryData, string $signature): bool` | Check HMAC-SHA256 integrity |
| `verifyHashChain` | `(array $entries): bool` | Validate hash chain: each `currentHash` matches computed value from previous entry |
| `getSecretKey` | `(): string` | Return signing key from environment (`KASSAKOPPELING_SECRET_KEY`) |

**Signing logic**:
```
fields = [operatorId, registerNumber, action, amount, taxAmount, timestamp, previousHash]
message = implode('|', fields)
signature = hash_hmac('sha256', message, secretKey)
```

**Hash chain logic**:
```
currentHash = sha256(
  implode('|', [entryData..., previousHash])
)
```

#### KassakoppelingAuditService (`lib/Service/KassakoppelingAuditService.php`)

| Method | Signature | Description |
|--------|-----------|-------------|
| `createEntry` | `(array $data): array` | Generate signature & hash, call `ObjectService::saveObject`, return entry with signatures |
| `listEntries` | `(array $filters = []): array` | `ObjectService::findObjects('kassakoppelingAuditLog', $filters)` |
| `getEntry` | `(string $id): array` | `ObjectService::findObject('kassakoppelingAuditLog', $id)` |
| `verifyEntry` | `(string $id): bool` | Fetch entry, call `SignatureService::verifySignature` and `verifyHashChain`, update `verified` flag |
| `exportForBelastingdienst` | `(string $fromDate, string $toDate): string` | Filter entries by date range, format as XML/JSON, return export file |
| `getLastEntry` | `(string $registerNumber): array` | Query latest entry for register to get `previousHash` |

#### KassakoppelingAuditController (`lib/Controller/KassakoppelingAuditController.php`)

| Method | URL | Action | Auth | Notes |
|--------|-----|--------|------|-------|
| POST | `/api/kassakoppeling/audit` | `create` — record audit entry | `@NoAdminRequired` | POS operator (from `#[NoAdminRequired]`) |
| GET | `/api/kassakoppeling/audit` | `index` — list entries | `@NoAdminRequired` | POS staff; search by date/operator/action |
| GET | `/api/kassakoppeling/audit/{id}` | `show` — detail view | `@NoAdminRequired` | Fetch single entry |
| POST | `/api/kassakoppeling/audit/{id}/verify` | `verify` — signature check | `@NoAdminRequired` | Cryptographic verification |
| GET | `/api/kassakoppeling/audit/export` | `exportBelastingdienst` — download report | Admin | Generate XML/JSON export for tax authority |

**Security**:
- All endpoints require Nextcloud authentication.
- Only admin users can export for Belastingdienst (security boundary).
- Per ADR-005: no stack traces in error responses; log real errors server-side.
- Signature generation uses environment variable `KASSAKOPPELING_SECRET_KEY` (NOT hardcoded).

#### BelastingdienestExportService (`lib/Service/BelastingdienestExportService.php`)

Formats audit log entries for Belastingdienst XML/JSON export format.

| Method | Signature | Description |
|--------|-----------|-------------|
| `exportAsXml` | `(array $entries): string` | Format entries as Kassakoppeling XML (schema TBD per spec/architect review) |
| `exportAsJson` | `(array $entries): string` | Format entries as structured JSON with metadata |
| `buildManifest` | `(array $entries): array` | Metadata: entry count, hash chain status, date range, register list |

### Frontend

#### KassakoppelingAuditList.vue (`src/views/kassakoppeling/AuditList.vue`)

Uses `CnIndexPage` + `useListView('kassakoppelingAuditLog', ...)`.

**Columns**:
- Timestamp (formatted: `de-NL` locale)
- Operator ID
- Register Number
- Action (badge: sale=green, void=red, refund=orange, no-sale=gray)
- Amount (formatted: EUR)
- Verified (badge: checkmark=signed, warning=unverified)

**Filters**:
- Date range picker (from/to)
- Register Number dropdown
- Operator filter (autocomplete)
- Action filter (multi-select)

**Actions**:
- Row click → detail view
- Export button (Belastingdienst XML) — admin only
- Verify button (check signatures) — background job, updates `verified` flags

#### KassakoppelingAuditDetail.vue (`src/views/kassakoppeling/AuditDetail.vue`)

Displays single audit entry with cryptographic verification badge.

**Sections**:
1. **Summary Card** — Action, operator, amount, timestamp, register
2. **Verification Status** — Badge showing `verified: true/false/null` with icon
3. **Fields** — operatorId, registerNumber, action, amount, taxAmount, itemCount, description
4. **Transaction Link** — If `transactionUuid` is set, show linked transaction from `pos-transaction-core`
5. **Signature Details** — (collapsible) signature hex, currentHash, previousHash, hash chain status
6. **Back Button** — Navigate to audit list

**Verification Badge**:
- ✓ Green: `verified: true` — cryptographic signature valid, hash chain intact
- ⚠ Orange: `verified: false` — signature or hash chain mismatch (fraud/tampering detected)
- ? Gray: `verified: null` — not yet verified (pending background job)

### Navigation

Add "Kassakoppeling Audit" nav item to `MainMenu.vue` (settings footer section).

Route: `/kassakoppeling/audit` → `KassakoppelingAuditList.vue`.
Detail route: `/kassakoppeling/audit/{id}` → `KassakoppelingAuditDetail.vue`.

## Seed Data

### kassakoppelingAuditLog (5 examples)

**1. Initial sale transaction**
```json
{
  "@self": { "register": "pipelinq", "schema": "kassakoppelingAuditLog", "slug": "aud-reg001-2026-05-20-001" },
  "operatorId": "user_john",
  "registerNumber": "REG-001",
  "action": "sale",
  "amount": 4950,
  "itemCount": 3,
  "taxAmount": 870,
  "timestamp": "2026-05-20T08:15:30Z",
  "transactionUuid": "uuid-txn-20260520-001",
  "signature": "a3f2c1e9b4d7f8c2a9e3b1f5d7c6a2e8",
  "previousHash": "0",
  "currentHash": "e8a2c6d7f5b1e3a9c2f7d8b4a1e9c3f6",
  "description": "Regular sale",
  "verified": true,
  "exportedAt": null
}
```

**2. Void transaction (after sale)**
```json
{
  "@self": { "register": "pipelinq", "schema": "kassakoppelingAuditLog", "slug": "aud-reg001-2026-05-20-002" },
  "operatorId": "user_john",
  "registerNumber": "REG-001",
  "action": "void",
  "amount": 4950,
  "itemCount": 3,
  "taxAmount": 870,
  "timestamp": "2026-05-20T08:18:15Z",
  "transactionUuid": "uuid-txn-20260520-001",
  "signature": "b4e3d2f8a5c1d9e7f3a8b2c6d1e4f9a5",
  "previousHash": "e8a2c6d7f5b1e3a9c2f7d8b4a1e9c3f6",
  "currentHash": "f7b1d5a8e3c9f2d6a1b4e7c2f8d3a6b9",
  "description": "Void: customer returned item",
  "verified": true,
  "exportedAt": null
}
```

**3. Refund transaction**
```json
{
  "@self": { "register": "pipelinq", "schema": "kassakoppelingAuditLog", "slug": "aud-reg001-2026-05-20-003" },
  "operatorId": "user_maria",
  "registerNumber": "REG-001",
  "action": "refund",
  "amount": 2500,
  "itemCount": 1,
  "taxAmount": 438,
  "timestamp": "2026-05-20T09:45:20Z",
  "transactionUuid": "uuid-txn-20260520-002",
  "signature": "c5f4e3d2a1b8c7f6e5d4c3b2a9f8e7d6",
  "previousHash": "f7b1d5a8e3c9f2d6a1b4e7c2f8d3a6b9",
  "currentHash": "a6b9c2d5e8f1a4b7c0d3e6f9a2b5c8d1",
  "description": "Refund: product defective",
  "verified": true,
  "exportedAt": "2026-05-20T18:00:00Z"
}
```

**4. No-sale transaction**
```json
{
  "@self": { "register": "pipelinq", "schema": "kassakoppelingAuditLog", "slug": "aud-reg001-2026-05-20-004" },
  "operatorId": "user_john",
  "registerNumber": "REG-001",
  "action": "no-sale",
  "amount": 0,
  "itemCount": 0,
  "taxAmount": 0,
  "timestamp": "2026-05-20T10:12:05Z",
  "transactionUuid": null,
  "signature": "d6a5f4e3c2b1a0f9e8d7c6b5a4f3e2d1",
  "previousHash": "a6b9c2d5e8f1a4b7c0d3e6f9a2b5c8d1",
  "currentHash": "b7c0d3e6f9a2b5c8d1e4f7a0b3c6d9e2",
  "description": "No-sale: cash drawer opened",
  "verified": true,
  "exportedAt": "2026-05-20T18:00:00Z"
}
```

**5. Unverified entry (pending signature check)**
```json
{
  "@self": { "register": "pipelinq", "schema": "kassakoppelingAuditLog", "slug": "aud-reg002-2026-05-21-001" },
  "operatorId": "user_peter",
  "registerNumber": "REG-002",
  "action": "sale",
  "amount": 7250,
  "itemCount": 5,
  "taxAmount": 1268,
  "timestamp": "2026-05-21T07:30:00Z",
  "transactionUuid": "uuid-txn-20260521-001",
  "signature": "e7b6c5d4e3f2a1b0c9d8e7f6a5b4c3d2",
  "previousHash": "b7c0d3e6f9a2b5c8d1e4f7a0b3c6d9e2",
  "currentHash": "c8d1e4f7a0b3c6d9e2f5a8b1c4d7e0f3",
  "description": "Sale via register 2",
  "verified": null,
  "exportedAt": null
}
```

All timestamps use UTC (Z) format per ISO 8601. Amounts in cents to avoid floating-point precision issues. Hash values are hex SHA-256 digests (64 characters).
