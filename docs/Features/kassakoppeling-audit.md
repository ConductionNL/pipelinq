---
title: Kassakoppeling Audit Log
description: POS Kassakoppeling-compliant immutable audit ledger for the Dutch Belastingdienst
sidebar_position: 95
---

# Kassakoppeling Audit Log

Pipelinq includes a POS audit log that complies with the Dutch **Kassakoppeling** standard required by the Belastingdienst (tax authority). Every register action is recorded as an immutable, cryptographically signed ledger entry with SHA-256 hash-chain linkage.

## Overview

Dutch POS operators must maintain a tamper-evident transaction ledger that auditors can inspect. Pipelinq's Kassakoppeling Audit Log meets this requirement by:

1. Recording every **sale, void, refund, and no-sale** action with mandatory fields.
2. Signing each entry with an HMAC-SHA256 signature tied to a server-side secret.
3. Linking every entry to the previous one via a SHA-256 hash chain (hash of previous entry is included in the next entry's hash computation).
4. Exporting batches of entries in XML or JSON format for Belastingdienst submission.

## Configuration

Before creating audit entries the signing key must be configured:

```bash
php occ config:app:set pipelinq kassakoppeling_secret --value="<strong-random-key>"
```

The key is stored in Nextcloud's app config (server-side only). It is never exposed in API responses or logs.

## API Endpoints

| Method | URL | Auth | Description |
|--------|-----|------|-------------|
| `POST` | `/api/kassakoppeling/audit` | Authenticated | Create a new audit entry |
| `GET`  | `/api/kassakoppeling/audit` | Authenticated | List audit entries (filterable) |
| `GET`  | `/api/kassakoppeling/audit/export` | **Admin only** | Download XML/JSON export |
| `GET`  | `/api/kassakoppeling/audit/{id}` | Authenticated | Fetch a single entry |
| `POST` | `/api/kassakoppeling/audit/{id}/verify` | Authenticated | Verify entry signature |

### Create an entry (POST)

```json
{
  "operatorId": "user_john",
  "registerNumber": "REG-001",
  "action": "sale",
  "amount": 4950,
  "itemCount": 3,
  "taxAmount": 870,
  "timestamp": "2026-05-20T08:15:30Z",
  "transactionUuid": "<uuid-from-pos-transaction-core>",
  "description": "Regular sale"
}
```

Required fields: `operatorId`, `registerNumber`, `action`, `amount`, `timestamp`.

`action` must be one of: `sale`, `void`, `refund`, `no-sale`.

`amount` and `taxAmount` are **integer cents** (e.g. 4950 = EUR 49.50). This avoids floating-point precision loss.

The API automatically computes and attaches:
- `signature` (HMAC-SHA256 of the canonical field set)
- `previousHash` (SHA-256 of the previous entry for this register, or `"0"` for the first)
- `currentHash` (SHA-256 of all entry fields + previousHash)

### List entries (GET)

Query parameters:

| Parameter | Description |
|-----------|-------------|
| `registerNumber` | Filter by register (e.g. `REG-001`) |
| `operatorId` | Filter by operator UID |
| `action` | Filter by action type |
| `fromDate` | ISO 8601 date lower bound |
| `toDate` | ISO 8601 date upper bound |

### Export (GET /api/kassakoppeling/audit/export) — admin only

```
GET /api/kassakoppeling/audit/export?fromDate=2026-05-01&toDate=2026-05-31&format=xml
```

Returns a file download. Supported formats: `xml` (default), `json`.

The export includes a `<Manifest>` (XML) or `manifest` (JSON) block with:
- `exportDate` — when the export was generated
- `entryCount` — number of entries
- `dateRange` — from/to timestamps
- `registers` — list of registers covered
- `chainIntegrity` — `"valid"` | `"invalid"` | `"empty"`

Non-admin users receive `HTTP 403 Forbidden`.

## Signature Verification

### Signing algorithm

```
fields  = [operatorId, registerNumber, action, amount, taxAmount, timestamp, previousHash]
message = implode('|', fields)
signature = HMAC-SHA256(message, kassakoppeling_secret)
```

### Hash-chain algorithm

```
hashFields = [operatorId, registerNumber, action, amount, itemCount, taxAmount,
              timestamp, transactionUuid, previousHash]
currentHash = SHA-256(implode('|', hashFields))
```

### Manual verification

```
POST /api/kassakoppeling/audit/{id}/verify
```

Returns:
```json
{ "verified": true, "entryId": "<uuid>" }
```

`verified: true` means the HMAC-SHA256 signature is valid (the entry has not been tampered with).
`verified: false` means the signature does not match — possible tampering or key rotation.

### Background verification job

`VerifyAuditChainJob` runs hourly and processes up to 100 unverified entries per run, setting `verified: true` or `verified: false`.

## Immutability

Entries are **append-only**. The controller does not expose PUT or PATCH endpoints for `kassakoppelingAuditLog` objects. The OpenRegister schema is marked `x-immutable: true`.

## Frontend

Navigate to **Kassakoppeling Audit** in the footer section of the sidebar.

- **List view** (`/kassakoppeling/audit`): paginated table with action badges (sale=green, void=red, refund=orange, no-sale=gray), EUR-formatted amounts, and verified status badges.
- **Detail view** (`/kassakoppeling/audit/{id}`): verification banner, field cards, optional transaction cross-link to `pos-transaction-core`, and a collapsible signature section.
- **Export button** (admin only): select XML/JSON format and date range, then download.

## Cross-app linkage

When creating an audit entry, set `transactionUuid` to the UUID of the matching `posTransaction` from `pos-transaction-core`. The detail view renders a clickable link to the transaction.

## Compliance notes

- Amounts are stored as integer cents (no floating-point rounding errors).
- Timestamps are ISO 8601 UTC (`Z` suffix).
- The Kassakoppeling secret must be rotated carefully: rotating it invalidates all existing signatures. Generate a new key, re-sign all entries, then update the config.
- Hash chains are per-register (`registerNumber`). Each register has its own chain starting with `previousHash: "0"`.
