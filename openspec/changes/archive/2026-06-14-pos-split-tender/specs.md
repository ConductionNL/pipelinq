---
status: draft
---

# Specs: POS Multi-tender Payment (cash + card + voucher)

## Purpose

Enable a single POS transaction to accept payment via multiple tender methods (cash, card, voucher) simultaneously. Compute change when cash tender exceeds the transaction total. Post each tender to its configured GL account upon settlement. Prevent transaction settlement until tender sum equals transaction total.

---

## REQ-PST-001: Tender Type Configuration [MVP]

The system MUST provide an administrative interface to configure available tender types. Each tender type specifies its name, GL posting account, and whether it requires external reference (card receipt, voucher code) or PIN verification.

### Scenario: Tender type list is retrievable

- GIVEN the system has been initialized with seed tender types (Contant, Betaalpas, Cadeaubon)
- WHEN an admin calls `GET /api/pos/tender-types`
- THEN the response MUST return an array of `posTenderType` objects
- AND each object MUST include `name`, `code`, `glAccount`, `isActive`, `sortOrder`

### Scenario: Tender type can be created

- GIVEN an admin wants to add a custom tender type for MOLLIE payments
- WHEN the admin calls `POST /api/pos/tender-types` with payload:
  ```json
  {
    "name": "iDEAL",
    "code": "MOLLIE",
    "glAccount": "1200",
    "requiresReference": true
  }
  ```
- THEN the system MUST create a new `posTenderType` object
- AND the response MUST include the generated `uuid` and `createdAt` timestamp

### Scenario: GL account is required

- GIVEN an admin attempts to create a tender type
- WHEN `glAccount` is omitted or empty
- THEN the system MUST reject the request with HTTP 400
- AND return error message: `"GL account is required"`

### Scenario: Tender type can be deactivated

- GIVEN an active tender type exists
- WHEN an admin updates it with `isActive: false`
- THEN new sales MAY NOT use this tender type for payment
- AND existing tenders of this type are NOT affected
- AND the tender type remains in system for historical reference

---

## REQ-PST-002: Add Tender to Transaction [MVP]

A transaction MUST accept one or more tenders. Each tender specifies the payment method and amount. Amount MUST be ≥ 0.01 EUR. Tender cannot be added if transaction is already settled.

### Scenario: Tender is added to transaction

- GIVEN a `posTransaction` with `total: 97.97` EUR in status `confirmed` (not yet settled)
- AND `posTenderType` with code `CASH` and `glAccount: "1100"` exists
- WHEN a cashier calls `POST /api/pos/transactions/{txn-uuid}/tenders` with:
  ```json
  {
    "tenderType": "tender-cash-uuid",
    "amount": 50.00
  }
  ```
- THEN the system MUST create a `posTender` object
- AND the tender MUST include `glAccount` copied from `tenderType.glAccount`
- AND the tender MUST be linked to the transaction via `transaction` reference

### Scenario: Multiple tenders can be added to same transaction

- GIVEN a transaction with one CASH tender of €50.00 already added
- WHEN the cashier adds a CARD tender of €47.97
- THEN both tenders MUST exist on the transaction
- AND `tenderSum = €97.97` equals transaction `total`
- AND the system MUST allow settlement with both tenders present

### Scenario: Amount must be positive

- GIVEN a tender type is selected and UI shows amount input
- WHEN the amount is 0 or negative
- THEN the system MUST reject the submit
- AND display error: `"Tender amount must be greater than €0.01"`

### Scenario: Tender cannot be added to settled transaction

- GIVEN a transaction with status `settled`
- WHEN a cashier attempts `POST /api/pos/transactions/{settled-txn}/tenders`
- THEN the system MUST reject with HTTP 409 Conflict
- AND return error: `"Cannot add tenders to a settled transaction"`

### Scenario: Reference is required for CARD tender

- GIVEN a tender type CARD has `requiresReference: true`
- WHEN a tender is submitted without `reference` field
- THEN the system MUST reject with HTTP 400
- AND return error: `"Reference is required for this tender type"`

---

## REQ-PST-003: Remove Tender from Transaction [MVP]

A cashier MUST be able to remove a tender from an unsettled transaction. Removal from settled transactions is not allowed.

### Scenario: Tender is removed from transaction

- GIVEN a transaction in status `confirmed` with two tenders (CASH €50 + CARD €47.97)
- WHEN the cashier calls `DELETE /api/pos/transactions/{txn-uuid}/tenders/{tender-uuid}`
- THEN the tender MUST be deleted
- AND the transaction MUST contain only the remaining tender (CASH €50)

### Scenario: Cannot remove tender from settled transaction

- GIVEN a transaction in status `settled`
- WHEN a cashier attempts to remove a tender
- THEN the system MUST reject with HTTP 409 Conflict
- AND return error: `"Cannot remove tenders from a settled transaction"`

---

## REQ-PST-004: Tender Sum Validation Before Settlement [MVP]

Before a transaction can settle, the sum of all tender amounts MUST equal the transaction total (including tax). If tender sum does not match total, settlement MUST be blocked with clear error indicating the discrepancy.

### Scenario: Settlement succeeds when tender sum equals total

- GIVEN a transaction with `total: 97.97` EUR
- AND two tenders: CASH €50.00 + CARD €47.97
- WHEN the cashier confirms settlement
- THEN `tenderSum = €97.97` equals `total`
- AND the system MUST allow transition to `settled` status
- AND the settlement MUST proceed

### Scenario: Settlement is blocked when tender sum is less than total

- GIVEN a transaction with `total: 97.97` EUR
- AND only one CASH tender of €50.00
- WHEN the cashier attempts to settle
- THEN the system MUST reject with error: `"Tender sum (€50.00) does not equal transaction total (€97.97). Underpayment: €47.97"`
- AND the transaction MUST remain in `confirmed` status (not settled)

### Scenario: Settlement is blocked when tender sum exceeds total (no cash change)

- GIVEN a transaction with `total: 97.97` EUR
- AND CARD tenders totaling €100.00
- WHEN the cashier attempts to settle without a CASH tender for change
- THEN the system MUST reject with error: `"Tender sum (€100.00) exceeds transaction total. Overpayment: €2.03 without change tender"`
- AND the transaction MUST remain in `confirmed` status

### Scenario: Settlement succeeds when CASH tender allows change

- GIVEN a transaction with `total: 27.20` EUR
- AND a CASH tender of €50.00 (tender type allows change)
- WHEN the cashier confirms settlement
- THEN the system MUST calculate change: €50.00 − €27.20 = €22.80
- AND the system MUST allow settlement
- AND transaction MUST include note: `"Change due: €22.80"`

---

## REQ-PST-005: Change Calculation for Cash [MVP]

When a CASH tender exceeds the transaction total and the tender type has `allowsChange: true`, the system MUST calculate and display the change amount. Change is displayed to the cashier; the tender amount remains as submitted (no auto-adjustment).

### Scenario: Change is calculated for cash overpayment

- GIVEN a `posTenderType` CASH with `allowsChange: true`
- AND a transaction total of €27.20
- AND a CASH tender of €50.00 is submitted
- WHEN the tender is added to the transaction
- THEN the system MUST calculate change: €22.80
- AND the UI MUST display: `"Change due: €22.80"` in a green highlight
- AND the tender MUST be recorded with `amount: 50.00` (not adjusted)

### Scenario: Change is NOT calculated for non-cash tender

- GIVEN a CARD tender type with `allowsChange: false`
- AND a transaction total of €50.00
- AND a CARD tender of €55.00 is submitted
- WHEN the tender is validated
- THEN no change amount is calculated
- AND the settlement validation MUST reject (overpayment without CASH tender for change)

### Scenario: Change calculation shown in transaction detail

- GIVEN a transaction with a CASH tender of €50.00 for total of €27.20
- WHEN the cashier opens the transaction detail view
- THEN the tender section MUST display:
  - Tender type: "Contant"
  - Amount: "€50.00"
  - Change: "€22.80"

---

## REQ-PST-006: GL Account Posting on Settlement [MVP]

When a transaction settles, the system MUST emit a CloudEvent for each tender. shillinq receives the event and posts a debit/credit entry to the GL account specified in the tender. Posting failure does NOT block settlement.

### Scenario: CloudEvent is emitted per tender on settlement

- GIVEN a settled transaction with two tenders:
  - CASH €50.00 to GL account "1100"
  - CARD €47.97 to GL account "1200"
- WHEN the transaction transitions to `settled`
- THEN the system MUST emit two CloudEvents
- AND each event MUST include:
  - `type: "nl.pipelinq.pos.tender.posted"`
  - `transactionReference: "TXN-2026-XXXX"`
  - `tenderType: "CASH"`
  - `amount: 50.00`
  - `glAccount: "1100"`

### Scenario: GL posting failure does not block settlement

- GIVEN a tender is about to be posted to GL
- WHEN the shillinq app is unavailable
- THEN the settlement MUST complete successfully
- AND the CloudEvent MUST be retried via background job
- AND the transaction MUST be marked with status `settled` (not `pending_gl_posting`)

### Scenario: GL posting is idempotent

- GIVEN a transaction has already settled and CloudEvents were posted
- WHEN the background job retries the same event
- THEN shillinq MUST detect duplicate via event `id` field
- AND MUST NOT create duplicate GL entries

---

## REQ-PST-007: Transaction Detail UI [MVP]

The transaction detail view MUST display a Tenders section listing all tenders and allow adding/removing tenders.

### Scenario: Tenders section displays in transaction detail

- GIVEN a transaction with multiple tenders
- WHEN the cashier opens the transaction detail view
- THEN a "Tenders" section MUST appear below the line items
- AND the section MUST show a table with columns:
  - Tender Type (e.g., "Contant", "Betaalpas")
  - Amount (e.g., "€50.00")
  - GL Account (e.g., "1100")
  - Change (if applicable, e.g., "€22.80")
  - Remove button (enabled only if transaction not settled)

### Scenario: Add Tender modal is accessible

- GIVEN a transaction in draft or confirmed status
- WHEN the cashier clicks "Add Tender" button
- THEN a modal MUST open with:
  - Dropdown to select tender type (filtered to `isActive: true`, sorted by `sortOrder`)
  - Amount input field
  - Conditional "Reference" input if tender type requires it
  - Cancel and Submit buttons

### Scenario: Running total of tenders is displayed

- GIVEN a transaction with €50.00 CASH already added
- WHEN the cashier opens the Add Tender modal
- THEN the modal MUST display: `"Current tender sum: €50.00 | Transaction total: €97.97 | Remaining: €47.97"`

### Scenario: Remove tender button is disabled when settled

- GIVEN a transaction in `settled` status
- WHEN the cashier opens the transaction detail view
- THEN the Remove button on each tender line MUST be disabled (greyed out)
- AND hovering over it MUST show tooltip: `"Cannot remove tenders from a settled transaction"`

---

## REQ-PST-008: Tender Type Admin UI [MVP]

Administrators MUST have a page to manage tender types: list, create, edit, deactivate.

### Scenario: Tender types are listed in admin panel

- GIVEN an administrator opens the admin settings area
- WHEN they navigate to "Tender Types"
- THEN a list MUST display all tender types with columns:
  - Name (e.g., "Contant", "Betaalpas")
  - Code (e.g., "CASH", "CARD")
  - GL Account
  - Active (toggle)
  - Actions (Edit, Delete)

### Scenario: Tender type can be edited

- GIVEN a tender type exists
- WHEN an admin clicks Edit
- THEN a form MUST open with all fields:
  - Name, Code (read-only after creation), GL Account, Requires Reference, Requires PIN, Allows Change, Active, Sort Order
- AND the admin can modify and save

### Scenario: Cannot delete tender type if active tenders reference it

- GIVEN a tender type has active tenders in recent transactions
- WHEN an admin attempts to delete the tender type
- THEN the system MUST reject with error: `"Cannot delete tender type with active references"` 
- AND MUST show count: `"3 active tenders reference this type"`

---

## REQ-PST-009: Multi-language Support [MVP]

All new UI strings and error messages MUST be translated to English and Dutch per ADR-007.

### Scenario: Dutch tender type names are provided

- GIVEN the seed data for tender types
- THEN tender type names MUST be in Dutch:
  - "Contant" (not "Cash")
  - "Betaalpas" (not "Card")
  - "Cadeaubon" (not "Voucher")

### Scenario: All error messages are translated

- GIVEN the system rejects tender submission due to missing reference
- WHEN the UI language is set to Dutch
- THEN the error MUST display in Dutch: `"Referentie is vereist voor dit betalingstype"`
- AND when set to English: `"Reference is required for this tender type"`
