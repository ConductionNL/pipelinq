# Tasks: pos-transaction-core

## 0. Deduplication Check

- [ ] 0.1 Search `openspec/specs/` and `openregister/lib/Service/` for any existing POS
  or transaction logic; document findings (expected: no overlap)
  - **acceptance_criteria**:
    - GIVEN the search is complete
    - THEN a one-line finding MUST be appended to this task: "No overlap found" or a
      reference to the existing capability and why new code is needed

---

## 1. Data Model

- [ ] 1.1 Add `posTransaction` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/pos-transaction-core/spec.md#REQ-POS-001`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is imported
    - THEN all properties from the design.md posTransaction table MUST be present
      with correct types, required flags, enums (draft/parked/confirmed/settled/refunded),
      and defaults
    - AND `@type: "schema:Order"` MUST be set on the schema

- [ ] 1.2 Add `posTransactionLine` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/pos-transaction-core/spec.md#REQ-POS-002`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is imported
    - THEN all properties from the design.md posTransactionLine table MUST be present
    - AND `quantity` minimum MUST be 0.001
    - AND `discount` MUST be constrained to 0–100
    - AND `@type: "schema:OrderItem"` MUST be set on the schema

- [ ] 1.3 Add seed data for posTransaction (5 objects) and posTransactionLine (5 objects)
  using the `@self` envelope in `pipelinq_register.json`
  - **spec_ref**: ADR-001 (data-layer) — seed data requirements
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register is imported
    - THEN 5 posTransaction objects MUST be created with varied statuses
      (draft, parked, confirmed, settled, refunded) and Dutch-language values
    - AND 5 posTransactionLine objects MUST be created with realistic Dutch products
    - AND re-importing with `force: false` MUST NOT create duplicates (matched by slug)

- [ ] 1.4 Update the register's `schemas` list to include posTransaction and posTransactionLine
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the app is installed / repair step runs
    - THEN both schemas appear in OpenRegister admin under the pipelinq register

---

## 2. Backend Service

- [ ] 2.1 Create `lib/Service/PosTransactionService.php`
  - **spec_ref**: `specs/pos-transaction-core/spec.md#REQ-POS-003, #REQ-POS-004,
    #REQ-POS-005, #REQ-POS-006, #REQ-POS-007, #REQ-POS-010`
  - **files**: `pipelinq/lib/Service/PosTransactionService.php`
  - **acceptance_criteria**:
    - GIVEN the service is injected
    - THEN `recalculateLine(array $lineData)` MUST compute taxAmount and lineTotal
      using formula: `(qty × unitPrice × (1 − discount/100)) × taxRate/100`
    - AND `recalculateTotals(string $transactionId)` MUST aggregate lines into
      subtotal, discountTotal, taxBreakdown (grouped by rate), totalTax, total
      and persist the updated posTransaction
    - AND `confirmTransaction(string $id, string $userId)` MUST validate status
      is draft or parked, cart is non-empty, call recalculateTotals, set
      status=confirmed + confirmedAt, and call emitConfirmedEvent
    - AND `settleTransaction`, `refundTransaction`, `parkTransaction`, `resumeTransaction`
      MUST enforce valid status preconditions and return 422 on violation
    - AND `refundTransaction` MUST check manager permission via `AuthorizationService`
      and return 403 if the user lacks it
    - AND `emitConfirmedEvent` MUST call `WebhookService` with the CloudEvent envelope
      from design.md and store the returned event ID in `cloudEventId`
    - AND every public method MUST have `@spec openspec/changes/pos-transaction-core/tasks.md#2.1`

- [ ] 2.2 Create `lib/Controller/PosTransactionController.php`
  - **spec_ref**: `specs/pos-transaction-core/spec.md#REQ-POS-004, #REQ-POS-005,
    #REQ-POS-006, #REQ-POS-007`
  - **files**: `pipelinq/lib/Controller/PosTransactionController.php`
  - **acceptance_criteria**:
    - GIVEN the controller is registered
    - THEN POST `/api/pos-transactions/{id}/confirm` MUST call
      `PosTransactionService::confirmTransaction()` and return the updated transaction
    - AND POST `/api/pos-transactions/{id}/settle` MUST call `settleTransaction()`
    - AND POST `/api/pos-transactions/{id}/refund` MUST call `refundTransaction()` with
      `reason` from the request body; returns 422 if reason is missing
    - AND POST `/api/pos-transactions/{id}/park` MUST call `parkTransaction()`
    - AND POST `/api/pos-transactions/{id}/resume` MUST call `resumeTransaction()`
    - AND all methods MUST be `<10 lines` (thin controller pattern)
    - AND file header docblock MUST include `@spec openspec/changes/pos-transaction-core/tasks.md#2.2`

---

## 3. Backend Routes

- [ ] 3.1 Add POS lifecycle routes to `appinfo/routes.php`
  - **spec_ref**: ADR-002 (api)
  - **files**: `pipelinq/appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN routes are registered
    - THEN POST `/api/pos-transactions/{id}/confirm`, `/settle`, `/refund`, `/park`,
      `/resume` MUST all route to `PosTransactionController`
    - AND routes MUST be placed before any wildcard `{slug}` route

---

## 4. Frontend — Store Registration

- [ ] 4.1 Register `posTransaction` and `posTransactionLine` object types in `src/store/store.js`
  - **files**: `pipelinq/src/store/store.js`
  - **acceptance_criteria**:
    - GIVEN the app initialises
    - THEN `objectStore.registerObjectType('posTransaction', 'posTransaction', 'pipelinq')`
      MUST be called
    - AND `objectStore.registerObjectType('posTransactionLine', 'posTransactionLine', 'pipelinq')`
      MUST be called
    - AND both stores MUST include the `relations` plugin

---

## 5. Frontend — Views

- [ ] 5.1 Create `src/views/pos/PosTransactionList.vue`
  - **spec_ref**: `specs/pos-transaction-core/spec.md#REQ-POS-008`
  - **files**: `pipelinq/src/views/pos/PosTransactionList.vue`
  - **acceptance_criteria**:
    - GIVEN the cashier navigates to `/pos`
    - THEN `CnIndexPage` with `useListView('posTransaction', ...)` MUST render the list
    - AND columns Reference, Cashier, Terminal, Status (CnStatusBadge), Total, Created MUST be shown
    - AND status multi-select filter and search MUST be functional
    - AND rows MUST navigate to detail on click
    - AND empty state MUST show "Geen transacties gevonden" with "Nieuwe transactie" button

- [ ] 5.2 Create `src/views/pos/PosTransactionDetail.vue`
  - **spec_ref**: `specs/pos-transaction-core/spec.md#REQ-POS-004, #REQ-POS-005,
    #REQ-POS-006, #REQ-POS-007, #REQ-POS-009`
  - **files**: `pipelinq/src/views/pos/PosTransactionDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a transaction detail is loaded
    - THEN transaction info, line items table, tax breakdown, and totals panel MUST render
    - AND `CnDetailCard` sections MUST be used for layout
    - AND `CnObjectSidebar` MUST be present with files/notes/audit trail
    - AND lifecycle action buttons MUST be context-sensitive per the status table in design.md
    - AND the "Terugboeken" button MUST be hidden for non-manager users

- [ ] 5.3 Create `src/views/pos/PosTransactionForm.vue`
  - **spec_ref**: `specs/pos-transaction-core/spec.md#REQ-POS-001, #REQ-POS-002`
  - **files**: `pipelinq/src/views/pos/PosTransactionForm.vue`
  - **acceptance_criteria**:
    - GIVEN the cashier opens a new or existing draft/parked transaction
    - THEN header fields (terminalId, client, notes) MUST be editable
    - AND the line items table MUST allow adding rows via `PosLineItemRow`
    - AND `PosTotalsPanel` MUST update in real time on any line change
    - AND save MUST call `objectStore.saveObject('posTransaction', ...)` and then
      individually save/update/delete changed posTransactionLines

---

## 6. Frontend — Components

- [ ] 6.1 Create `src/components/pos/PosLineItemRow.vue`
  - **spec_ref**: `specs/pos-transaction-core/spec.md#REQ-POS-002`
  - **files**: `pipelinq/src/components/pos/PosLineItemRow.vue`
  - **acceptance_criteria**:
    - GIVEN the row is rendered
    - THEN product picker (NcSelect, searches by name and SKU), description, quantity,
      unitPrice, discount, and taxRate fields MUST be present
    - AND selecting a product MUST pre-fill description, unitPrice, and taxRate
    - AND any field change MUST emit `update:line` with recomputed taxAmount and lineTotal
    - AND a remove button MUST emit `remove`

- [ ] 6.2 Create `src/components/pos/PosTotalsPanel.vue`
  - **spec_ref**: `specs/pos-transaction-core/spec.md#REQ-POS-003`
  - **files**: `pipelinq/src/components/pos/PosTotalsPanel.vue`
  - **acceptance_criteria**:
    - GIVEN the panel receives a `lines` array prop
    - THEN subtotal, discountTotal, taxBreakdown rows (grouped by rate), totalTax,
      and total MUST be computed and displayed
    - AND total MUST be visually prominent (primary colour, larger font)
    - AND all amounts MUST be formatted as `€ #.##0,00` (Dutch locale)

---

## 7. Frontend — Navigation and Routing

- [ ] 7.1 Add POS routes to `src/router/index.js`
  - **files**: `pipelinq/src/router/index.js`
  - **acceptance_criteria**:
    - GIVEN the router is initialised
    - THEN `/pos` → PosTransactionList, `/pos/new` → PosTransactionForm,
      `/pos/:id` → PosTransactionDetail, `/pos/:id/edit` → PosTransactionForm MUST be registered
    - AND all routes MUST be named

- [ ] 7.2 Add "Kassabon" navigation entry to `src/navigation/MainMenu.vue`
  - **files**: `pipelinq/src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - GIVEN the app is open
    - THEN a "Kassabon" item MUST appear in the sidebar with an appropriate receipt icon
    - AND the item MUST be highlighted when the current route starts with `/pos`

---

## 8. Verification

- [ ] 8.1 Run `npm run build` — zero errors or warnings
- [ ] 8.2 Run PHP static analysis (`phpstan`, `phpcs`) on new PHP files — zero errors
- [ ] 8.3 Manual browser test: create draft → add 2 lines → verify real-time totals →
  confirm → verify CloudEvent emitted → settle
- [ ] 8.4 Manual browser test: park transaction → navigate away → resume from list →
  verify status returns to draft
- [ ] 8.5 Manual browser test: attempt refund as non-manager → button hidden;
  attempt as manager → refund succeeds with reason stored
- [ ] 8.6 Verify seed data loads on fresh install via repair step and
  re-import is idempotent (run import twice, count objects unchanged)
