# Proposal: POS Attach Customer to Ticket

## Summary

Implement customer attachment to POS transactions, enabling cashiers to link sales to Pipelinq contacts, view customer purchase history at the register, and capture marketing consent. This change bridges the POS module with Pipelinq's CRM, supporting customer-centric operations, targeted marketing, and debtor tracking (via shillinq integration with "on account" tender).

13 of 13 competitor POS systems implement customer master + transaction history lookup at the point of sale (P0-must demand signal, 100% competitor coverage).

## Demand Evidence

### Customer Master + Purchase History at Point of Sale (demand: 13, 13 tender mentions)

Every surveyed POS competitor links transactions to a customer master and displays purchase history at the terminal. This enables:

- **Customer identification**: cashier looks up customer by name, email, or phone before ringing up
- **Purchase history**: view recent transactions, common items, frequency
- **Loyalty integration**: check available points, discounts, or loyalty status (implemented in separate changes)
- **Debtor tracking**: identify customers with outstanding credit (on account tender), flag overdue accounts
- **Marketing consent**: record and verify opt-in for email/SMS campaigns before data use

Competitor implementations vary in depth — some show only last 5 transactions, others show full year history and cumulative spend. The minimum viable feature is lookup + last 10 transactions + consent flag.

### Cross-App Integration: POS ↔ Pipelinq ↔ Shillinq

When a transaction is tagged "on account", the customer becomes a debtor in shillinq's AR ledger. Shillinq requires:

- Customer reference (from Pipelinq `client` or `contact`)
- Transaction reference (from POS)
- Amount due (from POS transaction total)

This change enables the link; shillinq integration is handled in a separate change.

## Inferred Stakeholders

| Stakeholder | Role | Goal |
|---|---|---|
| Cashier | Day-to-day POS operator | Look up customers quickly, see history, record consent |
| Store Manager | POS configuration owner | Configure customer lookup rules, set history depth |
| Marketing Team | Campaign owner | Target customers with valid consent, segment by purchase behavior |
| Accounting / Credit Manager | AR owner | Identify on-account sales for debtor tracking |

## Inferred User Stories

1. As a **cashier**, I want to look up a customer by name, email, or phone before ringing up, so I can attach the sale to their record.
2. As a **cashier**, I want to see the customer's last 10 transactions at the terminal, so I can answer questions like "How much did I spend last month?" or "What did I buy last time?"
3. As a **cashier**, I want to capture the customer's marketing consent (email/SMS opt-in) during checkout, so the marketing team can contact them with offers.
4. As a **store manager**, I want to configure which customer fields are searchable (name, email, phone), so the lookup is fast and relevant to our customer base.
5. As a **store manager**, I want to set the history depth (e.g., last 10 transactions vs. last year), so the lookup doesn't slow down the register.
6. As a **accounting**, I want to see which transactions are flagged "on account", so I can reconcile AR ledger with POS at end of month.

## Scope

### In scope

- **Customer lookup modal** at POS checkout: search by name, email, or phone; returns Pipelinq `client` or `contact` objects
- **Transaction customer attachment**: every POS transaction gains an optional `customer` field linking to a Pipelinq contact
- **Purchase history display**: show last N transactions (configurable, default 10) for the selected customer at the terminal
- **Marketing consent capture**: checkbox at checkout for email/SMS opt-in; consent flag recorded in the transaction and synced to Pipelinq contact
- **Lookup configuration** (admin settings): enable/disable search by name/email/phone; set history depth
- **On-account tender tracking**: flag transactions as "on account" for later debtor/AR integration with shillinq
- **Search audit**: customer lookup uses Pipelinq full-text search and respects privacy settings (e.g., do not contact flagged customers)

### Out of scope

- **Loyalty points integration** (separate change: pos-loyalty-integration)
- **Discount matching** (e.g., loyalty tier discount auto-apply; separate change)
- **Customer segments and targeting** (marketing campaigns; separate change)
- **Debtor/AR system** (shillinq integration; separate change)
- **Customer self-service portal** (customer history in a mobile app; separate change)
- **Duplicate customer detection/merge** (CRM data quality; separate change)

## Acceptance Criteria

1. **GIVEN** a cashier at checkout, **WHEN** they click "Customer lookup" or similar, **THEN** a modal opens with search fields for name, email, and phone (configurable which are visible).
2. **GIVEN** a cashier searches for "John Smith", **WHEN** results are returned, **THEN** each result shows the customer's name, email, phone, and last purchase date in a clickable list.
3. **GIVEN** a cashier selects a customer, **WHEN** the transaction is saved, **THEN** the `customer` field is populated with the contact's UUID, and the transaction detail view shows "Customer: John Smith".
4. **GIVEN** a customer is selected, **WHEN** the cashier opens the history panel, **THEN** the last 10 transactions (or configured depth) are displayed with date, items, total, and tender type.
5. **GIVEN** a transaction with a customer selected, **WHEN** the consent checkbox is checked, **THEN** the `marketingConsent` flag is set to true and synced to the Pipelinq contact on save.
6. **GIVEN** a transaction marked "on account", **WHEN** it is saved, **THEN** the transaction is tagged with `tender: "onAccount"` and visible in the AR feed for shillinq integration.

## Dependencies

- `pos-transaction-core` — Transaction CRUD, line items, and tender types are already implemented
- `pipelinq-core` — Client and contact entities are defined in the Pipelinq data model
- Pipelinq REST API — Customer search via `/api/objects/client` and `/api/objects/contact` (already available)

## Notes

This change involves close cross-app coordination:
- POS module calls Pipelinq API to search for and fetch customer data
- POS module stores customer UUID and consent state in transaction records
- Shillinq module (in a later change) reads customer and on-account flags to build AR journal
- Pipelinq module (in a later change) subscribes to POS transaction events to update contact purchase history snapshots
