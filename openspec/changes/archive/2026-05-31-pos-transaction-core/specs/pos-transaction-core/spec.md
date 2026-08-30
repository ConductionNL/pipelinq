# Spec: pos-transaction-core

**Feature tier:** P0-must  
**Schema.org mapping:** `schema:Order` (posTransaction), `schema:OrderItem` (posTransactionLine)  
**Demand evidence:** 13/13 competitors

---

## Requirements

### REQ-POS-001: Transaction Creation (Draft)

The system MUST allow a cashier to open a new POS transaction in `draft` status.

#### Scenario: Create a new draft transaction

- GIVEN a cashier is logged in
- WHEN they navigate to `/pos/new`
- THEN a new posTransaction MUST be created via `objectStore.saveObject('posTransaction', data)`
  with `status: 'draft'`, `cashier` set to the current Nextcloud user UID, and all totals initialised to 0
- AND the cashier MUST be navigated to the transaction form to add line items

#### Scenario: Terminal identifier pre-fill

- GIVEN a `terminalId` is configured in app settings
- WHEN a new draft transaction is created
- THEN `terminalId` MUST be pre-filled from settings
- AND the cashier MAY override it manually

---

### REQ-POS-002: Line Item Management

The system MUST allow adding, editing, and removing line items on a draft or parked transaction.

#### Scenario: Add a line item from the product catalog

- GIVEN a draft transaction is open in the form
- WHEN the cashier selects a product from the product picker
- THEN a new posTransactionLine MUST be created with `description` from `product.name`,
  `unitPrice` from `product.unitPrice`, and `taxRate` from `product.taxRate` (default 21)
- AND `quantity` MUST default to 1
- AND `taxAmount` and `lineTotal` MUST be computed immediately

#### Scenario: Add a free-text line item (no catalog product)

- GIVEN the product picker is empty / cleared
- WHEN the cashier types a free-text description and enters quantity and unit price
- THEN a posTransactionLine MUST be created with the free-text description
- AND `product` reference MUST remain null

#### Scenario: Edit quantity on an existing line item

- GIVEN a line item with quantity 1 and unitPrice 4.25 and taxRate 9
- WHEN the cashier changes quantity to 3
- THEN `taxAmount` MUST be recomputed as `(3 × 4.25 × 1.00) × 0.09 = 1.15`
- AND `lineTotal` MUST be recomputed as `(3 × 4.25) + 1.15 = 13.90`
- AND the totals panel MUST reflect the updated grand total

#### Scenario: Apply a line-level discount

- GIVEN a line item with unitPrice 54.98, quantity 1, taxRate 21
- WHEN the cashier sets discount to 10
- THEN `taxAmount` MUST be `(54.98 × 0.90) × 0.21 = 10.39`
- AND `lineTotal` MUST be `(54.98 × 0.90) + 10.39 = 59.82`

#### Scenario: Remove a line item

- GIVEN a draft transaction with 2 line items
- WHEN the cashier removes one line item
- THEN the posTransactionLine MUST be deleted
- AND the totals panel MUST recalculate without the removed line

---

### REQ-POS-003: Real-time Total Calculation

The totals panel MUST update in real time as line items are added, edited, or removed.

#### Scenario: Subtotal reflects all lines

- GIVEN a transaction with 3 lines having lineTotals 6.43, 13.90, and 46.94
- WHEN the totals panel is displayed
- THEN `subtotal` MUST equal 67.27
- AND `total` MUST equal `subtotal + totalTax`

#### Scenario: Tax breakdown groups by rate

- GIVEN a transaction with lines at 9% BTW (base 22.00) and 21% BTW (base 45.00)
- WHEN the totals panel is displayed
- THEN the tax breakdown MUST show two rows: `9% — base: €22.00 — BTW: €1.98`
  and `21% — base: €45.00 — BTW: €9.45`
- AND `totalTax` MUST equal 11.43

#### Scenario: Zero total on empty cart

- GIVEN a draft transaction with no lines
- WHEN the totals panel is displayed
- THEN all fields (subtotal, discountTotal, totalTax, total) MUST display as €0.00

---

### REQ-POS-004: Transaction Confirmation

The system MUST support confirming a draft or parked transaction, which locks totals and
emits a CloudEvent.

#### Scenario: Confirm a valid transaction

- GIVEN a draft transaction with at least one line item
- WHEN the cashier clicks "Bevestigen" and confirms
- THEN `PosTransactionService::confirmTransaction()` MUST be called
- AND totals MUST be recomputed and persisted on the posTransaction
- AND `status` MUST change to `confirmed`
- AND `confirmedAt` MUST be set to the current ISO 8601 timestamp
- AND a `pipelinq.PosTransaction.confirmed` CloudEvent MUST be emitted

#### Scenario: Confirmation blocked on empty cart

- GIVEN a draft transaction with no line items
- WHEN the cashier attempts to click "Bevestigen"
- THEN the button MUST be disabled
- AND an error message "Voeg minimaal één artikel toe" MUST be shown

#### Scenario: Confirmed transaction is read-only

- GIVEN a transaction with `status: confirmed`
- WHEN the cashier views the transaction detail
- THEN line items MUST be displayed as read-only — no add / edit / remove controls
- AND the "Bevestigen" button MUST NOT be shown

---

### REQ-POS-005: Transaction Settlement

The system MUST allow settling a confirmed transaction to mark payment received.

#### Scenario: Settle a confirmed transaction

- GIVEN a transaction with `status: confirmed`
- WHEN the cashier clicks "Afrekenen" and confirms
- THEN `status` MUST change to `settled`
- AND `settledAt` MUST be set to the current ISO 8601 timestamp
- AND no CloudEvent is emitted on settle (already emitted on confirm)

#### Scenario: Settlement blocked on wrong status

- GIVEN a transaction with `status: draft`
- WHEN the API receives a settle request
- THEN a 422 Unprocessable Entity MUST be returned with message
  "Transactie moet bevestigd zijn voor afrekenen"

---

### REQ-POS-006: Refund and Void

The system MUST support refunding a confirmed or settled transaction. Refund requires
manager-level permission.

#### Scenario: Manager refunds a settled transaction

- GIVEN a transaction with `status: settled` and the current user has manager role
- WHEN the user clicks "Terugboeken", enters reason "klant ontevreden", and confirms
- THEN `status` MUST change to `refunded`
- AND `refundedAt` MUST be set to the current ISO 8601 timestamp
- AND `refundReason` MUST be stored as "klant ontevreden"

#### Scenario: Non-manager cannot refund

- GIVEN a transaction with `status: settled` and the current user does NOT have manager role
- WHEN the transaction detail is displayed
- THEN the "Terugboeken" button MUST NOT be visible
- AND if called via API directly, a 403 Forbidden MUST be returned

#### Scenario: Refund reason is required

- GIVEN the refund dialog is open
- WHEN the user submits without entering a reason
- THEN validation error "Vul een reden in voor de terugboeking" MUST appear
- AND the refund MUST NOT proceed

---

### REQ-POS-007: Park and Resume

The system MUST allow parking a draft transaction to be resumed later.

#### Scenario: Park a draft transaction

- GIVEN a draft transaction with one or more line items
- WHEN the cashier clicks "Parkeren"
- THEN `status` MUST change to `parked`
- AND `parkedAt` MUST be set to the current ISO 8601 timestamp
- AND the cashier MUST be redirected to create a new draft transaction

#### Scenario: Parked transaction appears in the list

- GIVEN a parked transaction "TXN-2026-0004"
- WHEN the cashier views the transaction list filtered by status "parked"
- THEN "TXN-2026-0004" MUST appear in the list with a "Geparkeerd" status badge
- AND a "Hervatten" action MUST be available on the row

#### Scenario: Resume a parked transaction

- GIVEN a parked transaction
- WHEN the cashier clicks "Hervatten"
- THEN `status` MUST change back to `draft`
- AND `parkedAt` MUST be cleared
- AND the cashier MUST be navigated to the transaction form

---

### REQ-POS-008: Transaction List View

The system MUST provide a searchable, filterable list of all POS transactions.

#### Scenario: Display transaction list with key columns

- GIVEN multiple transactions exist
- WHEN the cashier navigates to `/pos`
- THEN a table MUST display columns: Reference, Cashier, Terminal, Status, Total, Created
- AND each row MUST be clickable to navigate to the transaction detail
- AND pagination MUST show 20 transactions per page

#### Scenario: Filter by status

- GIVEN transactions with statuses: draft (2), confirmed (3), settled (5), refunded (1)
- WHEN the cashier filters by status "settled"
- THEN exactly 5 transactions MUST be shown

#### Scenario: Search by reference

- GIVEN transactions "TXN-2026-0001" and "TXN-2026-0099"
- WHEN the cashier types "0001" in the search box
- THEN only "TXN-2026-0001" MUST appear

#### Scenario: Empty state

- GIVEN no transactions exist
- WHEN the cashier navigates to `/pos`
- THEN an empty state MUST display "Geen transacties gevonden" with a "Nieuwe transactie" button

---

### REQ-POS-009: Transaction Detail View

The system MUST provide a full detail view of a transaction including line items and tax breakdown.

#### Scenario: View transaction core information

- GIVEN a settled transaction with reference "TXN-2026-0001", total 21.53, 9% BTW
- WHEN the cashier navigates to the detail view
- THEN reference, cashier, terminalId, status, confirmedAt, settledAt MUST be displayed
- AND total MUST be formatted as "€ 21,53"

#### Scenario: Line items table on detail view

- GIVEN a transaction with 3 line items
- WHEN the cashier views the detail
- THEN the line items table MUST display: description, quantity, unit price, discount %, tax rate, line total
- AND line totals MUST be formatted as EUR with 2 decimal places

#### Scenario: Tax breakdown on detail view

- GIVEN a transaction with taxBreakdown containing 9% and 21% rows
- WHEN the detail view is rendered
- THEN both tax rows MUST be displayed in the tax breakdown section
- AND the grand total section MUST clearly show: subtotal, − discount, + BTW, = **totaal**

---

### REQ-POS-010: CloudEvent Emission on Confirmation

The system MUST emit a `pipelinq.PosTransaction.confirmed` CloudEvent when a transaction
is confirmed, enabling Shillinq to draft a journal entry.

#### Scenario: CloudEvent is emitted on confirm

- GIVEN a draft transaction with reference "TXN-2026-0001" and total 21.53
- WHEN `PosTransactionService::confirmTransaction()` is called
- THEN `WebhookService` MUST emit a CloudEvent with:
  - `type: "pipelinq.PosTransaction.confirmed"`
  - `source: "/apps/pipelinq/pos"`
  - `data.transactionId` = transaction UUID
  - `data.reference` = "TXN-2026-0001"
  - `data.total` = 21.53
  - `data.taxBreakdown` = the full tax breakdown array
  - `data.confirmedAt` = ISO 8601 confirmation timestamp
- AND `cloudEventId` MUST be stored on the posTransaction object

#### Scenario: CloudEvent not emitted on settle or refund

- GIVEN a confirmed transaction
- WHEN the transaction is settled or refunded
- THEN NO CloudEvent MUST be emitted
- AND only the status and timestamp fields change
