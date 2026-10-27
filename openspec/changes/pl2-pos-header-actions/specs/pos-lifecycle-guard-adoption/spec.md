# pos-lifecycle-guard-adoption Specification (delta)

## ADDED Requirements

### Requirement: The POS header shows one primary action per state

The POS transaction page SHALL show one primary button that follows the transaction's status: Confirm for a draft or
parked transaction that has lines, Resume for a parked one, Check out for a confirmed one. Print receipt, Email receipt
and Register refund SHALL be quick actions while they apply. Edit line items, Park and Reverse SHALL be in the Actions
menu; Reverse SHALL be for admins and ask for a reason. The POS refund page SHALL offer Confirm as the pending refund's
primary button and Reject with a reason, both for admins.

#### Scenario: A confirmed transaction

- GIVEN a confirmed transaction
- WHEN the cashier opens it
- THEN the primary button reads Check out
- AND Print receipt, Email receipt and Register refund are beside it
