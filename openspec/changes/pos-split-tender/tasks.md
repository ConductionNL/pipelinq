# Tasks: POS Multi-tender Payment (cash + card + voucher)

## 0. Pre-implementation verification

- [x] 0.1 Verify `posTransaction` and `posTransactionLine` schemas exist in `lib/Settings/pipelinq_register.json` from pos-transaction-core
- [~] 0.2 Confirm shillinq app is available and can receive CloudEvents; test CloudEvent subscription pattern — DEFERRED: requires a live instance + shillinq; emission is fire-and-forget through OR WebhookService (mirrors pos-transaction-core/pos-refund-return), unit-tested against a fake WebhookService
- [x] 0.3 Verify OpenRegister supports nested object arrays; if not, plan alternative for `posTender` relationships — posTender is a flat child object keyed by `transaction` UUID (same pattern as posTransactionLine), no nested arrays needed
- [x] 0.4 Check whether `posTransaction.settledAt` timestamp already exists; if not, add to pos-transaction-core update — settledAt + confirmedAt already exist on posTransaction

## 1. Data Model: Add posTenderType and posTender Schemas

- [x] 1.1 Add `posTenderType` schema to `lib/Settings/pipelinq_register.json`:
  - Properties: `name`, `code`, `description`, `glAccount`, `requiresReference`, `requiresPin`, `allowsChange`, `isActive`, `sortOrder`
  - All properties use OpenRegister built-in field types (string, boolean, integer)

- [x] 1.2 Add `posTender` schema to `lib/Settings/pipelinq_register.json`:
  - Properties: `transaction`, `tenderType`, `amount`, `reference`, `glAccount`, `notes`, `sortOrder`
  - `transaction` and `tenderType` are UUID references (from-register)

- [x] 1.3 Add seed data for 3 `posTenderType` objects (CASH, CARD, VOUCHER) with Dutch names and correct GL accounts
  - Use `@self` envelope with slug `tender-cash`, `tender-card`, `tender-voucher`
  - Set correct GL account numbers: 1100 (kas), 1200 (bank), 2100 (debiteuren/giftcard)

- [x] 1.4 Add seed data for 5 `posTender` objects linked to existing posTransaction seeds
  - Include examples of: cash only, split cash+card, cash with change, voucher

## 2. Backend Service: PosPaymentService

- [x] 2.1 Create `lib/Service/PosPaymentService.php`

- [x] 2.2 Implement `getTenderTypeByCode(string $code): array`
  - Queries `posTenderType` register for matching `code`
  - Returns single type object or throws `TenderTypeNotFoundException`

- [x] 2.3 Implement `validateTenderSum(string $transactionId): array`
  - Fetches transaction and all tenders for that transaction
  - Computes `tenderSum` and compares to `posTransaction.total`
  - Returns array with keys: `tenderSum`, `transactionTotal`, `variance` (total - tenderSum)
  - Example: `['tenderSum' => 97.97, 'transactionTotal' => 97.97, 'variance' => 0.0]`

- [x] 2.4 Implement `calculateChange(float $cashTenderedAmount, float $transactionTotal): float`
  - If `$cashTenderedAmount > $transactionTotal`, return difference (change due)
  - If `$cashTenderedAmount <= $transactionTotal`, return 0
  - Example: `calculateChange(50.00, 27.20)` returns `22.80`

- [x] 2.5 Implement `addTender(string $transactionId, array $tender): array`
  - Validate transaction exists and status is not `settled`
  - Validate `amount >= 0.01`
  - Validate `tenderType` UUID exists and `isActive = true`
  - If `requiresReference = true`, verify `reference` field is present and non-empty
  - Copy `glAccount` from tender type to tender object
  - Create tender via `ObjectService`
  - Return created tender with full details

- [x] 2.6 Implement `removeTender(string $transactionId, string $tenderId): void`
  - Verify transaction status is not `settled` (throw `InvalidTenderException` if settled)
  - Verify tender exists and belongs to transaction
  - Delete tender via `ObjectService`

- [x] 2.7 Implement `getTendersForTransaction(string $transactionId): array`
  - Fetch all `posTender` objects where `transaction = transactionId`
  - Sort by `sortOrder` (ascending)
  - Return array of tender objects

## 3. Backend Service: Update PosTransactionService

- [x] 3.1 In `lib/Service/PosTransactionService.php`, locate the settlement method (e.g., `confirm()` or `settle()`)

- [x] 3.2 Before allowing transition to `settled`, add tender validation:
  ```php
  if (!in_array($status, ['confirmed', 'settled'])) {
    // Only validate for settlement, not for draft/parked transitions
  }
  
  $validation = $this->paymentService->validateTenderSum($transactionId);
  if ($validation['variance'] !== 0.0) {
    throw new InvalidTenderException(
      sprintf(
        "Tender sum (€%.2f) does not equal transaction total (€%.2f). Difference: €%.2f",
        $validation['tenderSum'],
        $validation['transactionTotal'],
        abs($validation['variance'])
      )
    );
  }
  ```

- [x] 3.3 After successful settlement transition, emit CloudEvent for each tender (see section 5)

## 4. Backend Controller: TenderTypeController

- [x] 4.1 Create `lib/Controller/PosPaymentController.php`

- [x] 4.2 Implement endpoint `GET /api/pos/tender-types`:
  - Auth: `#[NoAdminRequired]`
  - Fetch all `posTenderType` objects via `ObjectService`
  - Filter to `isActive = true`
  - Sort by `sortOrder` (ascending)
  - Return JSON array

- [x] 4.3 Implement endpoint `GET /api/pos/tender-types/{id}`:
  - Auth: `#[NoAdminRequired]`
  - Fetch single `posTenderType` by UUID
  - Return JSON object or 404 if not found

- [x] 4.4 Implement endpoint `POST /api/pos/tender-types`:
  - Auth: admin required (throw `OCSForbidden` if not admin)
  - Validate required fields: `name`, `code`, `glAccount`
  - Validate `code` is unique across all tender types
  - Validate `glAccount` is non-empty string
  - Create via `ObjectService`
  - Return JSON with created object and HTTP 201

- [x] 4.5 Implement endpoint `PUT /api/pos/tender-types/{id}`:
  - Auth: admin required
  - Validate updated `code` is still unique (allow same code on same object)
  - Update via `ObjectService`
  - Return updated object

- [x] 4.6 Implement endpoint `DELETE /api/pos/tender-types/{id}`:
  - Auth: admin required
  - Before deleting, check if any active `posTender` objects reference this type
  - If active references exist, throw `BadRequest` with message: `"Cannot delete tender type with active references"`
  - Delete via `ObjectService`
  - Return HTTP 204

## 5. Backend Controller: Tender Endpoints

- [x] 5.1 In `PosPaymentController`, implement `GET /api/pos/transactions/{transactionId}/tenders`:
  - Auth: `#[NoAdminRequired]`
  - Fetch all tenders for transaction via `PosPaymentService::getTendersForTransaction()`
  - Return JSON array sorted by `sortOrder`

- [x] 5.2 Implement `POST /api/pos/transactions/{transactionId}/tenders`:
  - Auth: `#[NoAdminRequired]`
  - Parse request body: `{ tenderType: uuid, amount: number, reference?: string }`
  - Call `PosPaymentService::addTender()` with transaction ID and tender data
  - If `validateTenderSum()` shows overpayment and no CASH change tender exists, return warning message (not error)
  - Return created tender with HTTP 201

- [x] 5.3 Implement `DELETE /api/pos/transactions/{transactionId}/tenders/{tenderId}`:
  - Auth: `#[NoAdminRequired]`
  - Call `PosPaymentService::removeTender()` with both IDs
  - Return HTTP 204 or error if transaction is settled

## 6. CloudEvent Emission on Settlement

- [x] 6.1 Implement the CloudEvents 1.0 envelope for a posted tender (CORRECTED — no separate `lib/Event/` class):
  - Built inline by `PosPaymentService::buildTenderPostedPayload()` and dispatched via OR's `WebhookService`, exactly mirroring `PosTransactionService::buildConfirmedPayload` / `PosRefundService::buildRefundPayload` (the app has no `lib/Event/` class for CloudEvents — dispatch goes through WebhookService, not a domain Event object)
  - Event `type`: `"nl.pipelinq.pos.tender.posted"`
  - Event `id`: generated UUID per emission
  - Payload includes: `transactionReference`, `tenderType` (code), `amount`, `glAccount`

- [x] 6.2 Update settlement method in `PosTransactionService`:
  - After transaction status → `settled`, iterate over all tenders
  - For each tender, dispatch `TenderPostedEvent` via event dispatcher
  - Log event ID in tender object or transaction notes for traceability

- [x] 6.3 Create background job `lib/BackgroundJob/TenderPostedRetryJob.php`:
  - Runs every 5 minutes
  - Finds `posTender` objects created > 5 minutes ago without `glPosted` flag
  - Re-emits `TenderPostedEvent` for tenders not yet confirmed posted
  - Limit retries to 10 attempts per tender (soft-fail after that)

## 7. Frontend: Transaction Detail Component

- [x] 7.1 Open `src/views/PosTransactionDetail.vue` (or equivalent path)

- [x] 7.2 Add Tenders section below the transaction line items:
  - Display table with columns: Tender Type | Amount | GL Account | Change | Remove
  - Each tender type name is fetched dynamically from API (not hardcoded)

- [x] 7.3 Add "Add Tender" button that opens a modal dialog:
  - Modal fields:
    - Dropdown: `<select v-model="selectedTenderType">`; populated from `GET /api/pos/tender-types`
    - Amount: `<input type="number" v-model="tenderAmount" step="0.01" min="0.01">`
    - Reference (conditional): shown only if `selectedTenderType.requiresReference = true`
    - Current Tender Sum display: `"Current: €X.XX | Total: €Y.YY | Remaining: €Z.ZZ"`
  - Submit button calls `POST /api/pos/transactions/{id}/tenders`
  - On success, close modal and refresh tenders list
  - On error, display error message in modal

- [x] 7.4 Implement change display logic:
  - When a CASH tender amount > transaction total, compute change via `calculateChange()`
  - Display change amount in green highlight: `"Change: €22.80"`

- [x] 7.5 Implement remove button:
  - For each tender in list, add "Remove" button
  - Button is enabled only if transaction status != `settled`
  - On click, confirm with user: `"Remove this tender?"`
  - Call `DELETE /api/pos/transactions/{id}/tenders/{tenderId}`
  - On success, refresh tenders list

- [x] 7.6 Add validation feedback:
  - If tender sum < transaction total, show warning: `"Underpayment: €X.XX"`
  - If tender sum > transaction total (no CASH change), show error: `"Overpayment without change"`
  - If tender sum = transaction total, show success: `"Payment complete ✓"`

## 8. Frontend: Tender Type Admin Component

- [x] 8.1 Create or update admin page at `src/views/admin/PosSettings.vue`:
  - Add tab or section for "Tender Types"
  - List all tender types in table with columns: Name | Code | GL Account | Active | Actions

- [x] 8.2 Implement "Create Tender Type" button:
  - Opens modal form with fields: Name, Code, GL Account, Requires Reference (checkbox), Requires PIN (checkbox), Allows Change (checkbox), Active (checkbox), Sort Order (number)
  - Validate on submit: Code must be unique, GL Account must be non-empty
  - Call `POST /api/pos/tender-types`
  - On success, refresh table

- [x] 8.3 Implement edit action per row:
  - Opens form pre-populated with current values
  - Code field is read-only
  - Call `PUT /api/pos/tender-types/{id}` on submit
  - On success, update table row

- [x] 8.4 Implement delete action per row:
  - Show confirmation dialog
  - Call `DELETE /api/pos/tender-types/{id}`
  - If error 400 (active references), display message: `"Cannot delete: X active tenders reference this type"`
  - On success, remove row from table

## 9. i18n Translations

- [x] 9.1 Add following keys to `l10n/en.json`:
  - `"Tenders"`
  - `"Tender Type"`, `"Cash"`, `"Card"`, `"Voucher"`, `"Account"`
  - `"Add Tender"`, `"Remove Tender"`
  - `"Amount"`, `"Reference"`, `"Change"`
  - `"Current tender sum: {currentSum} EUR | Total: {total} EUR | Remaining: {remaining} EUR"`
  - `"Change due: {change} EUR"`
  - `"Tender sum ({sum} EUR) does not equal transaction total ({total} EUR). Underpayment: {diff} EUR"`
  - `"Tender sum ({sum} EUR) exceeds transaction total. Overpayment: {diff} EUR without change tender"`
  - `"Cannot add tenders to a settled transaction"`
  - `"Cannot remove tenders from a settled transaction"`
  - `"Reference is required for this tender type"`
  - `"Tender amount must be greater than €0.01"`
  - `"GL account is required"`
  - All field labels and button text per design

- [x] 9.2 Add corresponding keys to `l10n/nl.json` with Dutch translations:
  - Tender types: "Contant", "Betaalpas", "Cadeaubon", "Rekening"
  - Message examples:
    - `"Wisselgeld verschuldigd: {change} EUR"`
    - `"Betalingssaldo mismatch"`
    - `"Referentie is vereist voor dit betalingstype"`

- [x] 9.3 Verify no hardcoded English strings in Vue components:
  - Use `t('pipelinq', 'string')` for all UI text per ADR-007
  - Grep for hardcoded values to confirm compliance

## 10. Backend Tests

- [x] 10.1 Create `tests/Unit/Service/PosPaymentServiceTest.php`:
  - Test `calculateChange()` with multiple scenarios (overpay, exact, underpay)
  - Test `validateTenderSum()` with matching and mismatched amounts
  - Test `addTender()` with valid/invalid amounts
  - Test `addTender()` rejects when transaction is settled
  - Test `getTendersForTransaction()` returns sorted list
  - Test reference validation (required vs optional)

- [x] 10.2 Create `tests/Unit/Controller/PosPaymentControllerTest.php`:
  - Test `GET /api/pos/tender-types` returns active types only
  - Test `POST /api/pos/tender-types` validates required fields
  - Test `POST /api/pos/transactions/{id}/tenders` rejects settled transactions
  - Test `DELETE /api/pos/tender-types/{id}` rejects if active references exist
  - Test error messages and HTTP status codes

- [x] 10.3 Create `tests/Unit/Service/PosTransactionServiceTest.php` (update existing):
  - Add test for settlement validation: tender sum must equal total
  - Test CloudEvent is emitted per tender on settlement
  - Test settlement succeeds when tenders are correct

## 11. API Integration Tests

- [x] 11.1 Full add→validate→settle flow coverage (CORRECTED — covered by `PosTransactionServiceTest` + `PosPaymentServiceTest` end-to-end via in-memory fakes rather than a separate `tests/Integration/` suite, matching the app's existing test topology which has no `tests/Integration/`):
  - Full flow (add multiple tenders → validateTenderSum → settle) — `testSettleSucceedsOnExactTenderAndEmitsGlEvents`
  - Overpayment rejection (no CASH change tender) — `testSettleBlockedOnCardOverpayment` / `testValidateTenderSumCardOverpaymentNotReconciled`
  - Removing tenders before settlement — `testRemoveTenderDeletesIt` / `testRemoveTenderRejectsForeignTender`
  - GL account copied server-side — `testAddTenderCopiesGlAccountServerSide`
- [~] 11.2 CloudEvent emission + idempotency: payload shape + per-tender emission unit-tested (`testEmitTenderPostedEventsPerTender`); each event carries a fresh CloudEvents `id` for consumer-side dedup. Live shillinq subscription verification DEFERRED (requires a running shillinq + instance).

## 12. Verification & Documentation

- [~] 12.1 Manual test in POS UI — DEFERRED: requires a running instance with the register re-imported. Logic is covered by unit tests (change calc, settle gate, GL emit); UI wired into PosTransactionDetail + AddTenderModal + admin TenderTypeManager.
- [x] 12.2 Test error scenarios — under-payment block (`testSettleBlockedOnUnderpayment`), add-to-settled refusal (`testAddTenderRejectedOnSettledTransaction`), remove-from-settled refusal (`testRemoveTenderRejectedOnSettledTransaction`), all unit-tested; "no payment" state surfaced by the TendersCard empty/warning states.
- [x] 12.3 Verify migrations — no destructive schema change; the fragment additively extends posTransaction with `changeDue` and unions the two new schemas + seeds (ConfigFileLoaderService additive-union, regression-tested `testRealSplitTenderFragmentExtendsMonolith`). Backwards compatible: existing transactions simply have zero tenders until one is added.
- [~] 12.4 Check performance — DEFERRED (needs a live instance to profile). `validateTenderSum` is O(tenders) with a single indexed `transaction`-filtered findAll; `getTendersForTransaction` is one query (no N+1).
- [~] 12.5 Update API documentation (OpenAPI/Swagger) — DEFERRED: the app does not ship an OpenAPI doc surface; the register fragment's JSON-schema descriptions document the new schemas, and routes.php documents the endpoints.

## 13. Post-Implementation (Hydra coordination — out of opsx scope)

- [~] 13.1 Create GitHub issue — handled by Hydra coordination, not an opsx task.
- [~] 13.2 Notify QA team — handled by Hydra coordination.
- [~] 13.3 Update POS user documentation with screenshots — DEFERRED: requires the running UI to capture screenshots.
- [~] 13.4 Add admin onboarding guide — DEFERRED: documentation task for after merge.
