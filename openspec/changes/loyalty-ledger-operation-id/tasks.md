# Tasks: loyalty-ledger-operation-id

- [ ] **T1**: `pointsLedgerEntry` gets an `operationId` string property (screens session, `lib/Settings/pipelinq_register.json`)
- [ ] **T2**: `PointsLedgerService` takes an optional `operationId` on all five movements, returns the stored entry on a repeat and refuses a mismatch
  - PHPUnit: REQ-LOY-011-01 to REQ-LOY-011-05
- [ ] **T3**: The callers pass an operation id: `LoyaltyEngineService` (POS transaction id), `RedemptionService` and `LoyaltyPaymentService` (their own id), `PointsExpiryBatchJob` (account and expiry run)
