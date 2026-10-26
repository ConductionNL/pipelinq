# Tasks: pos-sale-in-shift

- [x] 1. `posTransaction.shift` (uuid, `$ref cashShift`) in register fragment `93-pos-transaction-shift.json`
- [x] 2. `PosTransactionService::confirmTransaction()` links the sale to the open shift (till first, else the cashier)
- [x] 3. The shift page counts the sales in the shift
- [x] 4. The shift list and page name a shift "Shift {floatAt|date}, {drawer}"
- [x] 5. Unit tests for the open-shift lookup
