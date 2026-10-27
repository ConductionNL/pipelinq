---
kind: code
---

# Proposal: pos-transaction-moment

## Summary

Board PqKassabonnen: the transactions list's "Aangemaakt" column and "Vandaag" lens read when the sale happened, not when the object was written. An imported or seeded sale otherwise reads the import time.

- `posTransaction.momentAt` is a computed field set on save: `confirmedAt`, else `parkedAt`. It is empty for a draft.
- The column shows the confirmation, else the parking, else the object's creation (formatter `transactionMoment`, "7 okt, 11:42").
- The "Vandaag" lens and the newest-first sort use `momentAt`.
