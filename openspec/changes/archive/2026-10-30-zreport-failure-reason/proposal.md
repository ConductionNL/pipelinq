---
kind: code
---

# Proposal: zreport-failure-reason

## Summary

pl3 PqZRapport: the board's Boekhouding card says why and when passing the day on failed ("Mislukt: shillinq reageerde niet om 23:00"), and the side column draws Dagtotaal, Btw and Transacties as three stat tiles.

posZReport (1.0.1 to 1.1.0) gains two optional properties: `bookkeepingFailureReason` (`unreachable` or `not_configured`) and `bookkeepingFailedAt`. PosBookkeepingService stamps both when a raise does not reach shillinq and clears them when a raise succeeds. The bookkeeping card shows "{status}: {reason} at {time}" when both are stored and the day is not passed on, and the plain status otherwise.

The side column's one Day total data card becomes three `stat` widgets reading `total`, `totalTax` and `transactionCount` off the report (`objectField`), the first two as currency.
