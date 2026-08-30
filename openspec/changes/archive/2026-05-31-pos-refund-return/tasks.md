# Tasks: pos-refund-return

## 0. Deduplication Check

- [x] 0.1 Search `openspec/specs/` and `openregister/lib/Service/` for any existing refund,
  return, or stock reversal logic; document findings (expected: no overlap)
  - **acceptance_criteria**:
    - GIVEN the search is complete
    - THEN a one-line finding MUST be appended: "No overlap found" or reference to existing
      capability and why new code is needed
  - **finding**: No overlap found. The merged `PosTransactionService::refundTransaction()`
    only flips a whole transaction to `status=refunded` with a free-text `refundReason` and
    emits no reversal/stock event. This change adds the structured `posRefund`/`posRefundLine`
    record model (partial line selection, reason codes, restock, server-computed reversal
    amounts, reversal + stock CloudEvents), referencing the parent posTransaction and reusing
    its persisted, server-authoritative tax data. No refund/return/stock-reversal logic exists
    in `openregister/lib/Service/` or elsewhere in `pipelinq/lib/`.

---

## 1. Data Model

- [x] 1.1 Add `refundReason` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/pos-refund-return/specs.md#REQ-REF-001`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is imported
    - THEN properties MUST include: code (string, required), label (string, required),
      description (string, optional), isActive (boolean, default true), icon (string, optional)
    - AND the schema MUST NOT have a `@type` (or use "schema:DefinedTerm")

- [x] 1.2 Add `posRefund` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/pos-refund-return/specs.md#REQ-REF-002, #REQ-REF-006, #REQ-REF-007`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is imported
    - THEN all properties from design.md posRefund table MUST be present with correct types,
      required flags, and defaults
    - AND `@type: "schema:Order"` MUST be set on the schema
    - AND `status` enum MUST be: pending, completed, rejected (default: pending)
    - AND `originalTransaction` MUST be required (UUID reference to posTransaction)

- [x] 1.3 Add `posRefundLine` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/pos-refund-return/specs.md#REQ-REF-003, #REQ-REF-005, #REQ-REF-008`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is imported
    - THEN all properties from design.md posRefundLine table MUST be present
    - AND `refund` and `originalLine` MUST both be required (UUID references)
    - AND `returnedQuantity` MUST be required and minimum 0.001
    - AND `restock` MUST default to true
    - AND `@type: "schema:OrderItem"` MUST be set on the schema

- [x] 1.4 Add seed data: refundReason (6 objects), posRefund (4 objects), posRefundLine (5 objects)
  using the `@self` envelope in `pipelinq_register.json`
  - **spec_ref**: ADR-001 (data-layer) — seed data requirements
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register is imported
    - THEN 6 refundReason objects MUST be created with codes: DAMAGED, UNWANTED, WRONG,
      EXPIRED, ERROR, OTHER (all with isActive: true and Dutch labels)
    - AND 4 posRefund objects MUST be created with varied statuses and Dutch-language values
    - AND 5 posRefundLine objects MUST be created with realistic Dutch context
    - AND re-importing with `force: false` MUST NOT create duplicates (matched by slug)

- [x] 1.5 Update the register's `schemas` list to include refundReason, posRefund, posRefundLine
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the app is installed / repair step runs
    - THEN all three schemas appear in OpenRegister admin under the pipelinq register

---

## 2. Backend Service

- [x] 2.1 Create `lib/Service/PosRefundService.php`
  - **spec_ref**: `specs/pos-refund-return/specs.md#REQ-REF-003, #REQ-REF-006, #REQ-REF-007,
    #REQ-REF-010, #REQ-REF-011, #REQ-REF-012, #REQ-REF-014`
  - **files**: `pipelinq/lib/Service/PosRefundService.php`
  - **acceptance_criteria**:
    - GIVEN the service is injected
    - THEN `recalculateLine(array $originalLine, float $returnedQty)` MUST compute taxAmount
      and lineTotal proportionally: (returnedQty / originalQty) × original amounts
    - AND `recalculateTotals(string $refundId)` MUST aggregate all refundLines into
      refundAmount, totalTax, and persist the updated posRefund
    - AND `confirmRefund(string $id, string $userId)` MUST:
      - Validate status is pending
      - Fetch originalTransaction and verify it exists
      - Validate total refund <= original transaction total (REQ-REF-014)
      - Call recalculateTotals
      - Set status=completed + confirmedAt
      - Call emitRefundEvent + emitStockMovementEvents
      - Return updated refund object
    - AND `rejectRefund(string $id, string $reason, string $userId)` MUST validate status is
      pending, set status=rejected + rejectedAt + rejectionReason, return updated object
    - AND `emitRefundEvent(array $refund)` MUST call WebhookService with CloudEvent envelope
      containing: refundId, reference, originalTransactionId, refundAmount, totalTax,
      paymentMethod, paymentReference, confirmedAt (from design.md)
    - AND `emitStockMovementEvents(string $refundId)` MUST:
      - Fetch all refundLines with restock=true
      - For each, emit `shillinq.StockMovement` CloudEvent with transactionType=refund_return,
        refundId, productId, quantity (positive number), unit, notes (from design.md)
      - Skip lines with restock=false
    - AND every public method MUST have `@spec openspec/changes/pos-refund-return/tasks.md#2.1`

- [x] 2.2 Create `lib/Controller/PosRefundController.php`
  - **spec_ref**: `specs/pos-refund-return/specs.md#REQ-REF-006, #REQ-REF-007`
  - **files**: `pipelinq/lib/Controller/PosRefundController.php`
  - **acceptance_criteria**:
    - GIVEN the controller is registered
    - THEN POST `/api/pos-refunds/{id}/confirm` MUST call `PosRefundService::confirmRefund()`
      and return the updated refund
    - AND POST `/api/pos-refunds/{id}/reject` MUST call `rejectRefund()` with `reason` from
      request body; returns 422 if reason is missing
    - AND both methods MUST be `<10 lines` (thin controller pattern)
    - AND file header docblock MUST include `@spec openspec/changes/pos-refund-return/tasks.md#2.2`

---

## 3. Backend Routes

- [x] 3.1 Add POS refund routes to `appinfo/routes.php`
  - **spec_ref**: ADR-002 (api)
  - **files**: `pipelinq/appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN routes are registered
    - THEN POST `/api/pos-refunds/{id}/confirm` and `/api/pos-refunds/{id}/reject` MUST both
      route to `PosRefundController`
    - AND routes MUST be placed before any wildcard `{slug}` route

---

## 4. Frontend — Store Registration

- [x] 4.1 Register `posRefund`, `posRefundLine`, and `refundReason` object types in `src/store/store.js`
  - **files**: `pipelinq/src/store/store.js`
  - **acceptance_criteria**:
    - GIVEN the app initialises
    - THEN `objectStore.registerObjectType('posRefund', 'posRefund', 'pipelinq')` MUST be called
    - AND `objectStore.registerObjectType('posRefundLine', 'posRefundLine', 'pipelinq')` MUST be called
    - AND `objectStore.registerObjectType('refundReason', 'refundReason', 'pipelinq')` MUST be called
    - AND all stores MUST include the `relations` plugin

---

## 5. Frontend — Views

- [x] 5.1 Create `src/views/pos/PosRefundList.vue`
  - **spec_ref**: `specs/pos-refund-return/specs.md#REQ-REF-008`
  - **files**: `pipelinq/src/views/pos/PosRefundList.vue`
  - **acceptance_criteria**:
    - GIVEN the user navigates to `/pos/refunds`
    - THEN `CnIndexPage` with `useListView('posRefund', ...)` MUST render the list
    - AND columns Reference, Original Transaction, Refund Reason, Amount, Status (badge), Created
      MUST be shown
    - AND status multi-select filter and search by reference MUST be functional
    - AND date range filter MUST work (scenarios 21, 22, 23)
    - AND rows MUST navigate to detail on click
    - AND empty state MUST show "Geen retouren gevonden" with "Nieuwe retour" button

- [x] 5.2 Create `src/views/pos/PosRefundDetail.vue`
  - **spec_ref**: `specs/pos-refund-return/specs.md#REQ-REF-009, #REQ-REF-012`
  - **files**: `pipelinq/src/views/pos/PosRefundDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a refund detail is loaded
    - THEN refund header (reference, original transaction link, cashier, status badge, reason),
      original transaction context, refund line items table (description, qty, returned qty,
      reason label, restock flag, total), refund totals panel, and notes MUST render
    - AND `CnDetailCard` sections MUST be used for layout
    - AND `CnObjectSidebar` MUST be present with files/notes/audit trail tabs
    - AND lifecycle action buttons (Bevestigen, Afwijzen) MUST appear only for pending refunds
    - AND completed/rejected refunds MUST have no action buttons
    - AND timestamps (confirmedAt, rejectedAt) MUST be displayed when set

- [x] 5.3 Create `src/views/pos/PosRefundForm.vue`
  - **spec_ref**: `specs/pos-refund-return/specs.md#REQ-REF-002, #REQ-REF-003, #REQ-REF-004,
    #REQ-REF-005, #REQ-REF-010, #REQ-REF-011`
  - **files**: `pipelinq/src/views/pos/PosRefundForm.vue`
  - **acceptance_criteria**:
    - GIVEN the user opens a refund form (new or edit)
    - THEN:
      - Original transaction picker/selector MUST be present
      - Overall refund reason picker MUST be shown
      - Line items table MUST display all original lines with:
        - Checkbox to select / deselect
        - Original description, qty, price
        - Returned qty input field (validated: <= original qty)
        - Reason picker per line
        - Restock toggle per line
        - Computed refund total per line
      - Real-time refund totals panel (`PosRefundTotalsPanel`) MUST update on every change
      - Save MUST call `objectStore.saveObject('posRefund', ...)` and individually
        save/update/delete changed posRefundLines
      - Validation: rejects if no lines selected; rejects if any returned qty > original qty;
        returns 422 with appropriate error message

---

## 6. Frontend — Components

- [x] 6.1 Create `src/components/pos/PosRefundLineRow.vue`
  - **spec_ref**: `specs/pos-refund-return/specs.md#REQ-REF-003, #REQ-REF-004, #REQ-REF-005`
  - **files**: `pipelinq/src/components/pos/PosRefundLineRow.vue`
  - **acceptance_criteria**:
    - GIVEN the row is rendered
    - THEN:
      - Original description (read-only label)
      - Original qty (read-only label)
      - Returned qty input field (number, min=0.001, max=original qty)
      - Reason picker (NcSelect, loads refundReasons from store)
      - Restock toggle (checkbox, default: true)
      - Computed line total (read-only, updated in real time)
      - Remove button (`delete` or `X`)
      MUST all be present
    - AND any field change MUST emit `update:line` with updated line object and recomputed totals
    - AND remove button MUST emit `remove`
    - AND returned qty validation MUST reject values > original qty

- [x] 6.2 Create `src/components/pos/PosRefundTotalsPanel.vue`
  - **spec_ref**: `specs/pos-refund-return/specs.md#REQ-REF-006, #REQ-REF-010, #REQ-REF-011`
  - **files**: `pipelinq/src/components/pos/PosRefundTotalsPanel.vue`
  - **acceptance_criteria**:
    - GIVEN the panel receives a `lines` array prop
    - THEN:
      - Refund amount (sum of pre-tax line totals), total tax, and grand total
        MUST be computed and displayed
      - Grand total MUST be visually prominent (primary colour, larger font)
      - All amounts MUST be formatted as `€ #.##0,00` (Dutch locale)
      - Panel MUST update in real time on prop changes

---

## 7. Frontend — Navigation and Routing

- [x] 7.1 Add POS refund routes to `src/router/index.js`
  - **files**: `pipelinq/src/router/index.js`
  - **acceptance_criteria**:
    - GIVEN the router is initialised
    - THEN:
      - `/pos/refunds` → PosRefundList
      - `/pos/refunds/new` → PosRefundForm (create, empty)
      - `/pos/refunds/new/:transactionId` → PosRefundForm (create from transaction)
      - `/pos/refunds/:id` → PosRefundDetail
      - `/pos/refunds/:id/edit` → PosRefundForm (edit mode)
      MUST all be registered with route names

- [x] 7.2 Add "Retouren" navigation entry to `src/navigation/MainMenu.vue`
  - **files**: `pipelinq/src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - GIVEN the app is open
    - THEN a "Retouren" item MUST appear in the POS section of the sidebar with a return/undo icon
    - AND the item MUST be highlighted when the current route starts with `/pos/refunds`
    - AND clicking it MUST navigate to `/pos/refunds`

- [x] 7.3 Add "Retour registreren" button to `src/views/pos/PosTransactionDetail.vue`
  - **files**: `pipelinq/src/views/pos/PosTransactionDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a transaction detail is displayed with status confirmed or settled
    - THEN a "Retour registreren" action button MUST appear in the action/lifecycle buttons section
    - AND clicking it MUST navigate to `/pos/refunds/new/:transactionId` (with the transaction ID)
    - AND for pending transactions, the button SHOULD NOT appear (or be disabled)

---

## 8. Verification

- [x] 8.1 Run `npm run build` — zero errors or warnings
- [x] 8.2 Run PHP static analysis (`phpstan`, `phpcs`) on new PHP files — zero errors
- [ ] 8.3 Manual browser test: Create refund from transaction detail → select items with partial
  quantities → verify real-time totals → confirm → verify CloudEvents emitted (check logs)
- [ ] 8.4 Manual browser test: Filter refund list by status, refund reason, date range
  → verify filters work correctly
- [ ] 8.5 Manual browser test: Create refund with restock=true on one line, restock=false on another
  → confirm → verify only one stock movement event emitted (check Shillinq event log)
- [ ] 8.6 Manual browser test: Reject a pending refund with rejection reason
  → verify status becomes rejected, no events emitted
- [ ] 8.7 Manual browser test: Attempt to refund more than original transaction total
  → verify system rejects with error message
- [ ] 8.8 Verify seed data loads on fresh install via repair step and re-import is idempotent
  (run import twice, count objects unchanged)
- [ ] 8.9 Verify audit trail records creation, edits, confirmation, rejection with timestamps and user info
- [ ] 8.10 Integration test (if applicable): Mock Shillinq webhook receiver and verify refund +
  stock movement events arrive with correct structure

> **Verification status (honest):** The build (8.1) and full PHP static analysis
> (8.2: phpcs + phpmd + phpstan all green on lib/) pass. The core logic behind the
> manual/live scenarios is covered by `PosRefundServiceTest` (16 tests, 45 assertions):
> partial proportional refund (8.3), restock-only stock movement emission (8.5),
> over-refund cap incl. cumulative (8.7), reject without events (8.6), and the
> reversal CloudEvent shape (8.10). The remaining browser scenarios (8.3 UI flow,
> 8.4 list filters, 8.6 UI, 8.9 audit trail) and live idempotent import (8.8) require
> a deployed instance and were NOT executed in this isolated worktree (no live
> Shillinq/inventory consumer is configured — events are emitted fire-and-forget and
> are a silent no-op when no subscriber exists). Seed objects use unique `@self`
> slugs so OR's slug-matched import is idempotent by construction (8.8).
