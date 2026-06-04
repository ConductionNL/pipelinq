# Proposal: POS Kassakoppeling-compliant Audit Log

## Problem

Dutch POS (Point of Sale) systems must comply with the Kassakoppeling standard for tax authority auditing. Currently, Pipelinq lacks a cryptographically signed, append-only audit ledger that records every register action (sale, void, refund, no-sale) with mandatory fields required by the Belastingdienst (Dutch tax authority). Without this, POS operators cannot meet regulatory requirements for transaction traceability and cannot export compliant reports to tax authorities.

## Proposed Change

Implement a POS Kassakoppeling-compliant audit log system that:

1. Records every register action with cryptographic signatures and hash-chain linkage
2. Stores audit entries as immutable ledger objects with required Kassakoppeling fields
3. Provides export functionality for Belastingdienst reports in compliant format
4. Links audit entries to transaction UUIDs from `pos-transaction-core` for cross-app traceability
5. Validates signatures and hash chain integrity on read
6. Enables compliance reporting and audit trail verification

### Scope

**In Scope:**
- `kassakoppelingAuditLog` schema (append-only ledger entity)
- Cryptographic signing and hash-chain management
- Belastingdienst export format (XML/JSON with tax-authority fields)
- Audit entry search and filtering UI
- Signature verification endpoint
- Transaction UUID linkage to `pos-transaction-core`

**Out of Scope:**
- Public key infrastructure (PKI) / certificate management (V1)
- Hardware security module (HSM) integration (Enterprise)
- Distributed ledger / blockchain storage (V1)
- Real-time compliance reporting dashboard (V1)
- Integration with Belastingdienst API (V1)
- Multi-register aggregation reports (V1)

## Impact

- **Files modified**: 1 new schema definition in `pipelinq_register.json`; 2 PHP service/controller files; 1–2 Vue detail/list views
- **New files**: 3–4 (backend services, frontend components)
- **Schema changes**: 1 new entity `kassakoppelingAuditLog` with 12 properties (append-only design)
- **Risk**: Medium — cryptographic operations require careful implementation; append-only design prevents editing/deletion
- **Feature tier**: P0-must (5/13 competitor implementations; regulatory requirement)
- **Dependencies**: `pos-transaction-core` app (for transaction UUID linkage)

## Competitive Advantage

**Unique NL moat** — no open-source CRM competitor packages POS Kassakoppeling compliance with signed audit ledgers and hash-chain verification. This differentiates Pipelinq in the Dutch SMB POS market and enables compliance without third-party tax software.
