---
status: draft
---

# Specification: pos-refund-return

## Purpose

Define the requirements for a complete refund and return workflow in Pipelinq POS:
**transaction refund → reason code → tender reversal → stock back-in → accounting events**

Enables cashiers to issue refunds on whole transactions or individual items, with
automatic reversal to the original payment method, inventory restoration, and
real-time accounting integration with Shillinq.

**Standards**: Schema.org (`schema:Order` with type=refund, `schema:OrderItem`), ISO 8601 (dates),
CloudEvents 1.0 (event envelope)
**Primary feature tier**: MVP
**Demand evidence**: 13/13 competitors implement refunds and returns as a foundational feature

---

## Data Model

See design.md for full schema definitions.

| Entity | Schema.org type | Required parents |
|--------|----------------|-----------------|
| refundReason | DefinedTerm (enum) | — (lookup/config) |
| posRefund | schema:Order (type=refund) | posTransaction |
| posRefundLine | schema:OrderItem (type=refund-line) | posRefund, posTransactionLine |

---

## Requirements

### REQ-REF-001: Refund Reason Configuration [MVP]

The system MUST provide a configurable list of standard refund reasons. Reasons are
stored as OpenRegister `refundReason` objects in the pipelinq register. Managers can
mark reasons as active/inactive to control which appear in refund forms.

#### Scenario 1: System loads standard refund reasons

- GIVEN a fresh Pipelinq install with repair step run
- WHEN the OpenRegister import completes
- THEN 6 refundReason objects MUST be created:
  - DAMAGED (Artikel beschadigd)
  - UNWANTED (Klant wil terug)
  - WRONG (Verkeerd artikel)
  - EXPIRED (Verlopen product)
  - ERROR (Kassa-fout)
  - OTHER (Overig)
- AND all MUST have `isActive: true`

#### Scenario 2: Refund form shows only active reasons

- GIVEN a refund reason with `isActive: false`
- WHEN the user opens a refund form
- THEN the inactive reason MUST NOT appear in the reason picker dropdown
- AND only 5 active reasons MUST be shown

---

### REQ-REF-002: Create Refund from Transaction [MVP]

The system MUST allow creating a refund against any settled or confirmed posTransaction.
The refund captures the original transaction reference, cashier, and payment method for
reversal purposes.

#### Scenario 3: Create refund from transaction detail

- GIVEN a settled transaction TXN-2026-0001 displayed in detail view
- WHEN the user clicks "Retour registreren"
- THEN the system MUST open PosRefundForm with:
  - `originalTransaction` pre-filled with TXN-2026-0001
  - All line items from the original transaction shown as options
  - `status` set to `pending`
  - `cashier` set to the current user

#### Scenario 4: Create new refund via list

- GIVEN the user navigates to `/pos/refunds`
- WHEN they click "Nieuwe retour"
- THEN a blank refund form MUST open
- AND the user MUST select the original transaction via dropdown

#### Scenario 5: Reject creation if transaction not specified

- GIVEN a refund form
- WHEN the user submits without selecting an original transaction
- THEN the system MUST reject with error: "Originele kassabon is verplicht"
- AND the refund MUST NOT be created

---

### REQ-REF-003: Select Items and Quantities to Refund [MVP]

The system MUST allow the user to select which line items from the original transaction
are being refunded, and how much of each (supporting partial refunds).

#### Scenario 6: Refund single line item fully

- GIVEN a refund form with original transaction containing 3 items
- WHEN the user selects item "Americano koffie" with original qty 2 and enters returned qty 2
- THEN the refund form MUST show:
  - Original item description "Americano koffie"
  - Original unit price €2.95
  - Returned qty: 2
  - Refund line total: €6.43 (incl. tax)

#### Scenario 7: Refund partial quantity

- GIVEN a refund form with item "Broodje kaas" (original qty 3, unit price €4.25, tax rate 9%)
- WHEN the user selects this item and enters returned qty 1
- THEN the system MUST compute the refund line total proportionally:
  - Refund amount = €4.25 × (1 / 3) = €1.42 (rounded)
  - AND the tax MUST be: €1.42 × 9% = €0.13
  - AND line total MUST be: €1.55

#### Scenario 8: Select multiple items for refund

- GIVEN a refund form with original transaction containing items:
  - Item A: qty 1, price €50
  - Item B: qty 2, price €30 each
  - Item C: qty 1, price €100
- WHEN the user selects A (qty 1), B (qty 1), and C (qty 1)
- THEN the refund form MUST show 3 refund lines
- AND the refund total MUST aggregate the three line totals

#### Scenario 9: Reject refund with no items selected

- GIVEN a refund form with original transaction
- WHEN the user tries to save without selecting any items
- THEN the system MUST reject with error: "Selecteer ten minste één artikel om terug te geven"
- AND the refund MUST NOT be created

---

### REQ-REF-004: Refund Reason per Item [MVP]

Each refund line MAY have its own reason (e.g., one item damaged, another unwanted).
The overall refund also has a primary reason.

#### Scenario 10: Set per-item return reason

- GIVEN a refund with 2 items selected
- WHEN the user opens the form for the first item
- THEN a reason picker MUST be shown with active reasons
- AND the user MUST be able to select a reason (e.g., "DAMAGED" for item 1, "UNWANTED" for item 2)

#### Scenario 11: Reason displayed in refund detail

- GIVEN a completed refund with:
  - Item 1: "Laptop" with reason "DAMAGED"
  - Item 2: "USB-C hub" with reason "UNWANTED"
- WHEN the user views the refund detail
- THEN each line MUST display its specific reason label

---

### REQ-REF-005: Restock Flag per Item [MVP]

Each refund line MUST include a boolean `restock` flag (default: true). When restock=true
and the refund is completed, a stock movement event is emitted to Shillinq to restore inventory.

#### Scenario 12: Toggle restock on a line

- GIVEN a refund form with an item selected
- WHEN the user toggles the "Teruggeven aan voorraad" checkbox
- THEN the `restock` flag for that line MUST update in real time
- AND the form MUST allow saving with any combination of restock flags

#### Scenario 13: Complete refund triggers stock movement

- GIVEN a completed refund with 2 lines:
  - Line 1: quantity 1, restock=true, product "Laptop"
  - Line 2: quantity 1, restock=false, product "USB hub"
- WHEN the refund reaches status=completed
- THEN Shillinq MUST receive:
  - 1 stock movement event for "Laptop" with quantity +1
  - NO stock movement event for "USB hub"

#### Scenario 14: Non-returnable items (restock=false)

- GIVEN a refund for food/beverage items (non-returnable)
- WHEN the user unchecks "Teruggeven aan voorraad" for these items
- THEN they MUST be refunded without triggering stock restoration
- AND the stock level MUST remain unchanged

---

### REQ-REF-006: Confirm Refund and Emit Events [MVP]

When a refund is confirmed, the system MUST validate the refund, compute final totals,
set status=completed, emit the refund event to Shillinq for accounting, and emit stock
movement events for restocked items.

#### Scenario 15: Confirm pending refund

- GIVEN a pending refund with 1 line (qty 1, amount €50 excl. tax, tax €10.50)
- WHEN the manager clicks "Bevestigen"
- THEN the system MUST:
  - Set status=completed
  - Set confirmedAt to current ISO 8601 timestamp
  - Emit `pipelinq.TransactionRefund.completed` event with:
    - refundId, reference, originalTransactionId, refundAmount (€50), totalTax (€10.50)
  - Emit `shillinq.StockMovement` event(s) for restocked items
  - Return the updated refund object

#### Scenario 16: Refund event received by Shillinq

- GIVEN a completed refund event `pipelinq.TransactionRefund.completed`
- WHEN Shillinq receives it
- THEN Shillinq MUST use the payload to:
  - Identify the original transaction and refund reference
  - Create a reversal journal entry (offset the original GL posting)
  - Store the refundId for audit linkage

#### Scenario 17: Stock movement event structure

- GIVEN a completed refund with restocked items
- WHEN stock movement events are emitted
- THEN each event MUST include:
  - `type: "shillinq.StockMovement"`
  - `transactionType: "refund_return"`
  - `refundId`: UUID of the posRefund
  - `productId`: product SKU or UUID from original line
  - `quantity`: positive number (e.g., 1 for a return)
  - `unit`: "piece" or UOM from product
  - `notes`: reference to original transaction

---

### REQ-REF-007: Reject Refund [MVP]

A pending refund MAY be rejected by a manager with a reason code. Rejected refunds are
immutable and do not trigger any events or stock changes.

#### Scenario 18: Reject pending refund

- GIVEN a pending refund
- WHEN the manager clicks "Afwijzen"
- THEN the system MUST:
  - Open a dialog with a reason text field
  - Allow the manager to enter a rejection reason (e.g., "Retourperiode verstreken")
  - Set status=rejected
  - Set rejectedAt to current timestamp
  - Store rejectionReason
  - NOT emit any events
  - NOT change stock

#### Scenario 19: Rejected refund is immutable

- GIVEN a refund with status=rejected
- WHEN the user tries to edit or delete it
- THEN the system MUST prevent any modifications
- AND the detail view MUST display the rejection reason

---

### REQ-REF-008: Refund List View [MVP]

The system MUST provide a list view of all refunds with search, filter, and sort capabilities.

#### Scenario 20: Display refund list with key columns

- GIVEN 10 refunds exist across multiple transactions
- WHEN the user navigates to `/pos/refunds`
- THEN the system MUST display a table with columns:
  - Reference (e.g., "RET-2026-0001")
  - Original Transaction reference
  - Refund Reason label
  - Refund Amount (EUR)
  - Status (badge: pending / completed / rejected)
  - Created (date)
- AND each row MUST link to the refund detail view

#### Scenario 21: Filter refunds by status

- GIVEN refunds with statuses: pending (3), completed (6), rejected (1)
- WHEN the user filters by status "completed"
- THEN exactly 6 refunds MUST be shown

#### Scenario 22: Search refunds by reference

- GIVEN refunds with references "RET-2026-0001" and "RET-2026-0002"
- WHEN the user searches for "0001"
- THEN only "RET-2026-0001" MUST appear

#### Scenario 23: Filter by date range

- GIVEN refunds created on 2026-05-19, 2026-05-20, and 2026-05-21
- WHEN the user filters by date range 2026-05-20 to 2026-05-20
- THEN only the refund from 2026-05-20 MUST be shown

---

### REQ-REF-009: Refund Detail View [MVP]

The system MUST provide a detail view showing the full refund, linked original transaction
context, refund line items, and lifecycle actions.

#### Scenario 24: View refund detail

- GIVEN a completed refund RET-2026-0001
- WHEN the user opens its detail view
- THEN the system MUST display:
  - Refund header: reference, original transaction link, cashier, status (completed)
  - Original transaction context: TXN reference and total (for audit)
  - Refund lines table: description, original qty, returned qty, reason, restock flag, refund total
  - Refund totals: refund amount, tax, grand total
  - Timestamps: confirmedAt
  - Cashier notes (if any)

#### Scenario 25: View pending refund with action buttons

- GIVEN a pending refund
- WHEN the user opens its detail view
- THEN action buttons MUST be displayed:
  - "Bevestigen" (confirm)
  - "Afwijzen" (reject)
- AND clicking "Bevestigen" MUST trigger confirmation workflow

#### Scenario 26: View completed refund (immutable)

- GIVEN a completed refund
- WHEN the user opens its detail view
- THEN:
  - No edit or delete buttons MUST be shown
  - Action buttons (confirm/reject) MUST NOT be visible
  - The UI MUST show confirmedAt timestamp
  - The detail MUST display the cloudEventId if present

---

### REQ-REF-010: Full Transaction Refund [MVP]

The system MUST support refunding an entire transaction at once (all line items, full quantities).

#### Scenario 27: Quick refund of entire transaction

- GIVEN a transaction TXN-2026-0001 with 3 items totalling €100
- WHEN the user opens the refund form and selects "Alle items teruggeven" (all items)
- THEN the form MUST populate:
  - All 3 line items with full original quantities
  - Refund amount = transaction total (€100)
- AND the user can proceed directly to confirm

#### Scenario 28: Full refund calculation

- GIVEN a transaction with:
  - Item 1: qty 2, €10 each = €20 (excl. tax)
  - Item 2: qty 1, €50 = €50 (excl. tax)
  - Tax: €14.70 (21% on €70 base)
  - Total: €84.70
- WHEN the user creates a full refund
- THEN refund totals MUST be:
  - Refund amount: €70
  - Total tax: €14.70
  - Grand total: €84.70

---

### REQ-REF-011: Partial Transaction Refund [MVP]

The system MUST support refunding a subset of items or quantities from a transaction.

#### Scenario 29: Partial refund scenario

- GIVEN a transaction with:
  - Item A: qty 2, €10 each
  - Item B: qty 1, €100
  - Item C: qty 3, €5 each
- WHEN the user creates a refund with:
  - Item A: qty 1 (half)
  - Item C: qty 1 (one-third)
- THEN refund totals MUST reflect:
  - Item A refund: €10 (1/2 of €20)
  - Item C refund: €1.67 (1/3 of €5)
  - Item B: NOT included

---

### REQ-REF-012: Payment Method and Tender Reversal [MVP]

The refund MUST capture the original payment method and a payment reference for use
in reversing the charge to the customer's original tender.

#### Scenario 30: Capture payment method from original transaction

- GIVEN a transaction paid with "card_visa" via payment ref "VISA-5678"
- WHEN a refund is created against this transaction
- THEN the refund MUST automatically populate:
  - `paymentMethod: "card_visa"`
  - `paymentReference: "VISA-5678"`
- AND these values MUST be included in the refund event emitted to Shillinq

#### Scenario 31: Support multiple payment methods

- GIVEN transactions paid via: cash, card_visa, card_mastercard, digital_wallet
- WHEN refunds are created for each
- THEN each refund MUST correctly capture the original payment method
- AND the refund event MUST include paymentMethod and paymentReference for Shillinq's reversal

---

### REQ-REF-013: Audit Trail [MVP]

Every refund MUST have a complete audit trail recorded automatically by OpenRegister.
The trail MUST include creation, edits, confirmation, and rejection.

#### Scenario 32: View refund audit trail

- GIVEN a refund RET-2026-0001 that was:
  - Created on 2026-05-20 at 10:00 by emma.bakker (draft)
  - Edited at 10:05 (line qty changed)
  - Confirmed at 10:10 by jan.smit
- WHEN the user opens the refund detail and views the audit tab
- THEN the audit trail MUST show all 3 events with:
  - Timestamp (ISO 8601)
  - User who made the change
  - Field(s) changed and old/new values (for edits)
  - Action type (created / modified / confirmed / rejected)

---

### REQ-REF-014: Error Scenarios [MVP]

The system MUST handle error conditions gracefully.

#### Scenario 33: Confirm refund when original transaction not found

- GIVEN a refund with an invalid originalTransaction reference (orphaned)
- WHEN the manager clicks "Bevestigen"
- THEN the system MUST reject with error:
  "Originele kassabon niet gevonden. Kan refund niet verwerken."
- AND the refund MUST remain pending

#### Scenario 34: Confirm refund when total exceeds original transaction

- GIVEN a refund with refund lines totalling €150
- AND the original transaction total is €100
- WHEN the manager tries to confirm
- THEN the system MUST reject with error:
  "Retourvolume (€150) overschrijdt originele totaal (€100)"
- AND the refund MUST NOT be confirmed

#### Scenario 35: Stock movement event delivery failure

- GIVEN a completed refund with restock=true on one line
- AND the Shillinq stock movement event fails to deliver
- WHEN the event is retried after 5 minutes
- THEN the system MUST retry the event delivery via WebhookService
- AND the refund status MUST remain "completed" (event emission is async)
- AND the failure MUST be logged for manual investigation

---

### REQ-REF-015: Refund Statistics and Reporting [V1]

The system SHOULD provide basic refund statistics for reporting (e.g., refund count, total amount,
top refund reasons).

#### Scenario 36: Dashboard widget shows refund metrics

- GIVEN the POS dashboard
- WHEN the user views the overview
- THEN a widget MUST show:
  - Refunds today (count)
  - Total refunded amount (EUR)
  - Top refund reason (e.g., "Damaged: 5")

---

### REQ-REF-016: Bulk Refund Processing [V1]

The system SHOULD support processing multiple refunds at once (batch confirm) to save time
during end-of-day reconciliation.

#### Scenario 37: Select and confirm multiple refunds

- GIVEN the refund list with 5 pending refunds
- WHEN the user selects 3 refunds via checkboxes and clicks "Alle bevestigen"
- THEN all 3 MUST transition to completed with a single action
- AND CloudEvents MUST be emitted for each

---

### REQ-REF-017: Mobile Refund Processing [V1]

The refund form SHOULD be optimized for mobile devices (small screens, touch-friendly).

#### Scenario 38: Process refund on mobile POS terminal

- GIVEN the user opens Pipelinq on a mobile POS terminal (tablet, phone)
- WHEN they open `/pos/refunds/new/:transactionId`
- THEN the form MUST be responsive and touch-friendly
- AND all fields and buttons MUST be easily accessible without scrolling

