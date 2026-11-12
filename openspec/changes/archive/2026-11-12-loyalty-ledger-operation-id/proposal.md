# Write a retried points movement once

## Why

A retried credit or debit writes a second ledger entry today. `PointsLedgerService` appends every call it gets. A POS that resends a transaction after a timeout credits the customer twice. A redemption that is retried after a lost answer debits twice.

Ruben decided on 2026-11-02: every movement carries an operation id, and a repeat returns the first entry.

## What changes

- **Callers pass an operation id.** `creditPoints`, `debitPoints`, `refundPoints`, `adjustPoints` and `expirePoints` take an optional `operationId`.
- **The entry stores it.** `pointsLedgerEntry` gets an `operationId` property.
- **A repeat writes nothing.** With an id that the account already has, the service returns that first entry. The balance and the tier stay as they are.
- **A reused id with other values is refused.** Same id, other type or other amount: the call fails and writes nothing.
- **No id, no change.** A call without an operation id appends as it does today.
- **The callers in pipelinq pass one.** The POS credit uses the POS transaction id, the redemption and payment debits use their own id, and the expiry batch uses the account and the expiry run.

## Known limit

Two calls with the same id that run at the same moment can both write. The same race exists between two debits on one account: both read the balance before either writes. Locking in OpenRegister would close both. That is out of scope here.

## Capabilities

### Modified capabilities

- `loyalty-program`: adds REQ-LOY-011, idempotent ledger movements.

## Impact

- `lib/Service/PointsLedgerService.php` and its five callers.
- `lib/Settings/pipelinq_register.json`: one new property on `pointsLedgerEntry`. The screens session owns that file, so the property lands through it.
